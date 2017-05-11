<?php

namespace WampPost\Tests\Functional;

use React\HttpClient\Response;
use Thruway\ClientSession;
use Thruway\Message\EventMessage;
use Thruway\WampErrorException;
use WampPost\WampPost;

class WampPostTest extends TestCase
{
    /**
     * @var EventMessage[]
     */
    private $_testTopicEvents = [];

    private function runTestWith($method, $url, array $headers = [], $protocolVersion = '1.0', $body)
    {
        $router = $this->createTestRouter();

        $wampPost = new WampPost("test_realm", \EventLoop\getLoop(), "tcp://127.0.0.1:18181/");

        $opened = false;

        $wampPost->on('open', function (ClientSession $session) use (&$opened, $router) {
            $opened = true;
            $session->register("procedure.that.errors", function () {
                throw new WampErrorException("my.custom.error", [4,5,6], (object)["x"=>"y"], (object)["y"=>"z"]);
            });

            $this->_testTopicEvents = [];

            // this subscription is here to test that options are working ("exclude_me")
            $session->subscribe("wamppost.tests.nonexclude.topic", function ($args, $argsKw, $details, $pubId) {
                $event = new EventMessage(0, $pubId, $details, $args, $argsKw, "wamppost.tests.nonexclude.topic");
                $this->_testTopicEvents[] = $event;
            });
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

        $this->assertEquals($responseBody, "pub");
        $this->assertEquals(200, $response->getCode());

        $this->assertEvents(
            [
                new EventMessage(0, 0, (object)["topic" => "wamppost.tests.some.topic"], [1, "two"], null, null,
                    "wamppost.tests.some.topic")
            ],
            $events
        );
    }

    function testPublishWithOptions()
    {
        /** @var Response $response */
        list($response, $responseBody, $events) = $this->runTestWith(
            "POST",
            "http://127.0.0.1:18181/pub",
            [],
            '1.0',
            json_encode(
                [
                    "topic" => "wamppost.tests.nonexclude.topic",
                    "args"  => [1, "two"],
                    "options" => [ "exclude_me" => false ]
                ]
            )
        );

        $this->assertEquals($responseBody, "pub");
        $this->assertEquals(200, $response->getCode());

        $this->assertEvents(
            [
                new EventMessage(0, 0, (object)["topic" => "wamppost.tests.nonexclude.topic"], [1, "two"], null, null,
                    "wamppost.tests.nonexclude.topic")
            ],
            $events
        );

        $this->assertEquals(1, count($this->_testTopicEvents));
        $this->assertEvents([
            new EventMessage(0, 0, new \stdClass(), [1, "two"], null, null,
                "wamppost.tests.nonexclude.topic")
        ], $this->_testTopicEvents);
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

        $this->assertEquals($responseBody, "pub");
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
            "Bad Request: Invalid request: {\"topic\":\"wamppost.tests.some.topic\",\"args\":null}");
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

        $this->assertEquals($responseBody, "Bad Request: Invalid URI: wamppost.tests.*");
        $this->assertEquals(400, $response->getCode());

        $this->assertEvents([], $events);
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

        $this->assertEquals($responseBody, "Bad Request: Invalid request: {\"args\":null}");
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

        $this->assertEquals($responseBody, "Bad Request: JSON decoding failed: Syntax error");
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

        $this->assertEquals($responseBody, "Not found");
        $this->assertEquals(404, $response->getCode());

        $this->assertEvents([], $events);
    }

    public function testCallWithError()
    {
        /** @var Response $response */
        list($response, $responseBody, $events) = $this->runTestWith(
            "POST",
            "http://127.0.0.1:18181/call",
            [],
            '1.0',
            json_encode(
                [
                    "procedure" => "procedure.that.errors",
                    "args"      => [1,2,3]
                ]
            )
        );

        $this->assertEquals($responseBody, "{\"result\":\"ERROR\",\"error_uri\":\"my.custom.error\",\"error_args\":[4,5,6],\"error_argskw\":{\"x\":\"y\"},\"error_details\":{\"y\":\"z\"}}");
        $this->assertEquals(200, $response->getCode());

        $this->assertEvents([], $events);
    }
}