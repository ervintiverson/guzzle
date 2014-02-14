<?php

namespace Guzzle\Tests\Http\Event;

use Guzzle\Http\Adapter\Transaction;
use Guzzle\Http\Client;
use Guzzle\Http\Event\HeadersEvent;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;

/**
 * @covers Guzzle\Http\Event\HeadersEvent
 */
class HeadersEventTest extends \PHPUnit_Framework_TestCase
{
    public function testHasValues()
    {
        $c = new Client();
        $r = new Request('GET', '/');
        $t = new Transaction($c, $r);
        $response = new Response(200);
        $t->setResponse($response);
        $e = new HeadersEvent($t);
        $this->assertSame($c, $e->getClient());
        $this->assertSame($r, $e->getRequest());
        $this->assertSame($response, $e->getResponse());
    }
}
