<?php

namespace WampPost\Tests\Functional;

use React\HttpClient\Response;
use Thruway\ClientSession;
use Thruway\Message\EventMessage;
use WampPost\WampPost;

class WampPostTest extends TestCase
{
    private function runTestWith($method, $url, array $headers = [], $protocolVersion = '1.0', $body)
    {
        $router = $this->createTestRouter();

        $wampPost = new WampPost("test_realm", \EventLoop\getLoop(), "127.0.0.1", 18181);

        $opened = false;

        $wampPost->on('open', function (ClientSession $session) use (&$opened, $router) {
            $opened = true;
        });

        $router->addInternalClient($wampPost);

        $response     = null;
        $responseBody = null;

        \EventLoop\addTimer(0,
            function () use (&$response, &$responseBody, $method, $url, $headers, $protocolVersion, $body) {
                $this->makeHttpRequest($method, $url, $headers, $protocolVersion, $body)->then(
                    function ($ret) use (&$response, &$responseBody) {
                        list($response, $responseBody) = $ret;
                        $this->stopRouter();
                    }
                );
            });

        $this->startRouterWithTimeout(5);

        $this->assertEmpty($this->expectedMessages);
        $this->assertNotNull($response);
        $this->assertNotNull($responseBody);
        $this->assertTrue($opened);

        return [$response, $responseBody, $this->recordedEvents];
    }

    function testPublishOnlyArgs()
    {
        /** @var Response $response */
        list($response, $responseBody, $events) = $this->runTestWith(
            "POST",
            "http://127.0.0.1:18181/pub",
            [],
            '1.0',
            json_encode(
                [
                    "topic" => "wamppost.tests.some.topic",
                    "args"  => [1, "two"]
                ]
            )
        );

        $this->assertEquals($responseBody, "3\r\npub\r\n0\r\n\r\n");
        $this->assertEquals(200, $response->getCode());

        $this->assertEvents(
            [
                new EventMessage(0, 0, (object)["topic" => "wamppost.tests.some.topic"], [1, "two"], null, null,
                    "wamppost.tests.some.topic")
            ],
            $events
        );
    }

    function testPublishArgsArgsKw()
    {
        /** @var Response $response */
        list($response, $responseBody, $events) = $this->runTestWith(
            "POST",
            "http://127.0.0.1:18181/pub",
            [],
            '1.0',
            json_encode(
                [
                    "topic"  => "wamppost.tests.some.topic",
                    "args"   => [1, "two"],
                    "argsKw" => [1, "two"]
                ]
            )
        );

        $this->assertEquals($responseBody, "3\r\npub\r\n0\r\n\r\n");
        $this->assertEquals(200, $response->getCode());

        $this->assertEvents(
            [
                new EventMessage(0, 0, (object)["topic" => "wamppost.tests.some.topic"], [1, "two"],
                    (object)["0" => 1, "1" => "two"], null, "wamppost.tests.some.topic")
            ],
            $events
        );
    }

    function testPublishNullArgs()
    {
        /** @var Response $response */
        list($response, $responseBody, $events) = $this->runTestWith(
            "POST",
            "http://127.0.0.1:18181/pub",
            [],
            '1.0',
            json_encode(
                [
                    "topic" => "wamppost.tests.some.topic",
                    "args"  => null
                ]
            )
        );

        $this->assertEquals($responseBody,
            "4f\r\nBad Request: Invalid request: {\"topic\":\"wamppost.tests.some.topic\",\"args\":null}\r\n0\r\n\r\n");
        $this->assertEquals(400, $response->getCode());

        $this->assertEvents([], $events);
    }

    function testPublishBadUri()
    {
        /** @var Response $response */
        list($response, $responseBody, $events) = $this->runTestWith(
            "POST",
            "http://127.0.0.1:18181/pub",
            [],
            '1.0',
            json_encode(
                [
                    "topic" => "wamppost.tests.*",
                    "args"  => []
                ]
            )
        );

        $this->assertEquals($responseBody, "2a\r\nBad Request: Invalid URI: wamppost.tests.*\r\n0\r\n\r\n");
        $this->assertEquals(400, $response->getCode());

        $this->assertEvents([], $events);
    }

    public function testBadPath()
    {
    }

    public function testNoTopicPublish()
    {
        /** @var Response $response */
        list($response, $responseBody, $events) = $this->runTestWith(
            "POST",
            "http://127.0.0.1:18181/pub",
            [],
            '1.0',
            json_encode(
                [
                    "args" => null
                ]
            )
        );

        $this->assertEquals($responseBody, "2b\r\nBad Request: Invalid request: {\"args\":null}\r\n0\r\n\r\n");
        $this->assertEquals(400, $response->getCode());

        $this->assertEvents([], $events);
    }

    public function testBadJson()
    {
        /** @var Response $response */
        list($response, $responseBody, $events) = $this->runTestWith(
            "POST",
            "http://127.0.0.1:18181/pub",
            [],
            '1.0',
            '{ "topic": "wamppost.tests.some.topic", "args": [1,2,3], }'
        );

        $this->assertEquals($responseBody, "2f\r\nBad Request: JSON decoding failed: Syntax error\r\n0\r\n\r\n");
        $this->assertEquals(400, $response->getCode());

        $this->assertEvents([], $events);
    }

    public function testGet()
    {
        /** @var Response $response */
        list($response, $responseBody, $events) = $this->runTestWith(
            "GET",
            "http://127.0.0.1:18181/pub",
            [],
            '1.0',
            json_encode(
                [
                    "args" => null
                ]
            )
        );

        $this->assertEquals($responseBody, "9\r\nNot found\r\n0\r\n\r\n");
        $this->assertEquals(404, $response->getCode());

        $this->assertEvents([], $events);
    }
}