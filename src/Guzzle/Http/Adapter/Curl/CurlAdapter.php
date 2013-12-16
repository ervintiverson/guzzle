<?php

namespace Guzzle\Http\Adapter\Curl;

use Guzzle\Http\Adapter\AdapterInterface;
use Guzzle\Http\Adapter\BatchAdapterInterface;
use Guzzle\Http\Adapter\TransactionInterface;
use Guzzle\Http\Event\RequestEvents;
use Guzzle\Http\Exception\AdapterException;
use Guzzle\Http\Exception\RequestException;
use Guzzle\Http\Message\MessageFactoryInterface;

/**
 * HTTP adapter that uses cURL as a transport layer
 */
class CurlAdapter implements AdapterInterface, BatchAdapterInterface
{
    const ERROR_STR = 'See http://curl.haxx.se/libcurl/c/libcurl-errors.html for an explanation of cURL errors';

    /** @var CurlFactory */
    private $curlFactory;

    /** @var MessageFactoryInterface */
    private $messageFactory;

    /** @var array Array of curl multi handles */
    private $multiHandles = [];

    /** @var array Array of curl multi handles */
    private $multiOwned = [];

    /**
     * @param MessageFactoryInterface $messageFactory
     * @param array                   $options Array of options to use with the adapter
     *                                         - handle_factory: Optional factory used to create cURL handles
     */
    public function __construct(MessageFactoryInterface $messageFactory, array $options = [])
    {
        $this->handles = new \SplObjectStorage();
        $this->messageFactory = $messageFactory;
        $this->curlFactory = isset($options['handle_factory'])
            ? $options['handle_factory']
            : new CurlFactory();
    }

    public function __destruct()
    {
        foreach ($this->multiHandles as $handle) {
            if (is_resource($handle)) {
                curl_multi_close($handle);
            }
        }
    }

    /**
     * Throw an exception for a cURL multi response if needed
     *
     * @param int $code Curl response code
     * @throws AdapterException
     */
    public static function checkCurlMultiResult($code)
    {
        if ($code != CURLM_OK && $code != CURLM_CALL_MULTI_PERFORM) {
            $buffer = function_exists('curl_multi_strerror')
                ? curl_multi_strerror($code)
                : self::ERROR_STR;
            throw new AdapterException(sprintf('cURL error %s: %s', $code, $buffer));
        }
    }

    public function send(TransactionInterface $transaction)
    {
        $context = new BatchContext($this->checkoutMultiHandle(), true);
        $this->addHandle($transaction, $context);
        $this->perform($context);

        return $transaction->getResponse();
    }

    public function batch(\Iterator $transactions, $parallel)
    {
        $context = new BatchContext(
            $this->checkoutMultiHandle(),
            false,
            $transactions
        );

        $total = 0;
        while ($transactions->valid() && $total < $parallel) {
            $current = $transactions->current();
            $this->addHandle($current, $context);
            $total++;
            $transactions->next();
        }

        $this->perform($context);
    }

    private function perform(BatchContext $context)
    {
        // The first curl_multi_select often times out no matter what, but is usually required for fast transfers
        $selectTimeout = 0.001;
        $active = false;
        $multi = $context->getMultiHandle();

        do {
            while (($mrc = curl_multi_exec($multi, $active)) == CURLM_CALL_MULTI_PERFORM);
            $this->checkCurlMultiResult($mrc);
            $this->processMessages($context);
            if ($active && curl_multi_select($multi, $selectTimeout) === -1) {
                // Perform a usleep if a select returns -1: https://bugs.php.net/bug.php?id=61141
                usleep(150);
            }
            $selectTimeout = 1;
        } while ($active || $context->hasPending());

        $this->releaseMultiHandle($context->getMultiHandle());
    }

    private function processMessages(BatchContext $context)
    {
        $multi = $context->getMultiHandle();

        while ($done = curl_multi_info_read($multi)) {
            if ($transaction = $context->findTransaction($done['handle'])) {
                $this->processResponse($transaction, $done, $context);
                // Add the next transaction if there are more in the queue
                if ($next = $context->nextPending()) {
                    $this->addHandle($next, $context);
                }
            }
        }
    }

    private function processResponse(
        TransactionInterface $transaction,
        array $curl,
        BatchContext $context
    ) {
        $handle = $context->removeTransaction($transaction);

        try {
            if (!$this->isCurlException($transaction, $curl, $context)) {
                RequestEvents::emitAfterSendEvent($transaction, curl_getinfo($handle));
            }
        } catch (RequestException $e) {
            $this->throwException($e, $context);
        }
    }

    private function addHandle(TransactionInterface $transaction, BatchContext $context)
    {
        try {
            RequestEvents::emitBeforeSendEvent($transaction);
        } catch (RequestException $e) {
            $this->throwException($e, $context);
        }

        // Only transfer if the request was not intercepted
        if (!$transaction->getResponse()) {
            $handle = $this->curlFactory->createHandle(
                $transaction,
                $this->messageFactory
            );
            $context->addTransaction($transaction, $handle);
        }
    }

    private function isCurlException(
        TransactionInterface $transaction,
        array $curl,
        BatchContext $context
    ) {
        if (CURLM_OK == $curl['result'] || CURLM_CALL_MULTI_PERFORM == $curl['result']) {
            return false;
        }

        $request = $transaction->getRequest();
        try {
            RequestEvents::emitErrorEvent($transaction, new RequestException(
                sprintf(
                    '[curl] (#%s) %s [url] %s',
                    $curl['result'],
                    function_exists('curl_strerror')
                        ? curl_strerror($curl['result'])
                        : self::ERROR_STR,
                    $request->getUrl()
                ),
                $request
            ));
        } catch (RequestException $e) {
            $this->throwException($e, $context);
        }

        return true;
    }

    private function throwException(RequestException $e, BatchContext $context)
    {
        if ($context->throwExceptions()) {
            $this->releaseMultiHandle($context->getMultiHandle());
            throw $e;
        }
    }

    /**
     * Returns a curl_multi handle from the cache or creates a new one
     *
     * @return resource
     */
    private function checkoutMultiHandle()
    {
        // Find an unused handle in the cache
        if (false !== ($key = array_search(false, $this->multiOwned, true))) {
            $this->multiOwned[$key] = true;
            return $this->multiHandles[$key];
        }

        // Add a new handle
        $handle = curl_multi_init();
        $this->multiHandles[(int) $handle] = $handle;
        $this->multiOwned[(int) $handle] = true;

        return $handle;
    }

    /**
     * Releases a curl_multi handle back into the cache and removes excess cache
     *
     * @param resource $handle Curl multi handle to remove
     */
    private function releaseMultiHandle($handle)
    {
        $this->multiOwned[(int) $handle] = false;
        // Prune excessive handles
        $over = count($this->multiHandles) - 3;
        while (--$over > -1) {
            curl_multi_close(array_pop($this->multiHandles));
            array_pop($this->multiOwned);
        }
    }
}
