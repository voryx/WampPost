<?php

namespace WampPost\Tests\Functional;

use function EventLoop\getLoop;
use React\EventLoop\Timer\Timer;
use React\Promise\Deferred;
use Thruway\ClientSession;
use Thruway\Message\EventMessage;
use Thruway\Peer\Client;
use Thruway\Peer\Router;

class TestCase extends \WampPost\Tests\TestCase
{
    /** @var Timer */
    private $currentTimer;

    /** @var Router */
    private $router;

    /** @var Client */
    private $eventClient;

    /** @var EventMessage[] */
    protected $recordedEvents;

    /** @var EventMessage[] */
    protected $expectedMessages;

    protected function createTestRouter()
    {
        $this->assertNull($this->router);

        $this->router = new Router(\EventLoop\getLoop());

        // create a client that records all publish events
        $this->recordedEvents = [];
        $this->eventClient    = new Client("test_realm", \EventLoop\getLoop());

        $this->eventClient->on('open', function (ClientSession $session) {
            $session->subscribe(
                "wamppost.tests.",
                function ($args, $argsKw, $details, $pubId) {
                    $eventMessage = new EventMessage(0, $pubId, $details, $args, $argsKw, $details->topic);
                    array_push($this->recordedEvents, $eventMessage);
                },
                (object)["match" => "prefix"]
            );
        });

        $this->router->addInternalClient($this->eventClient);

        return $this->router;
    }

    protected function startRouterWithTimeout($timeout)
    {
        $this->assertNull($this->currentTimer);

        $this->currentTimer = \EventLoop\addTimer($timeout, function () {
            \EventLoop\getLoop()->stop();
            $this->fail("Router timeout exceeded");
        });

        $this->router->start();
    }

    protected function stopRouter()
    {
        $this->assertInstanceOf('Thruway\Peer\Router', $this->router);
        $this->assertInstanceOf('React\EventLoop\Timer\Timer', $this->currentTimer);
        $this->currentTimer->cancel();
        $this->router->stop(false);
    }

    protected function makeHttpRequest($method, $url, array $headers = [], $protocolVersion = '1.0', $body)
    {
        $httpClient = new \React\HttpClient\Client(getLoop());

        $deferred = new Deferred();

        $headers['Content-Length'] = strlen($body);

        $request = $httpClient->request($method, $url, $headers, $protocolVersion);

        $request->on('response', function ($response) use ($deferred) {
            $responseBody = "";
            $response->on('data', function ($data) use (&$responseBody) {
                $responseBody .= $data;
            });
            $response->on('end', function () use (&$responseBody, $deferred, $response) {
                $deferred->resolve([$response, $responseBody]);
            });
        });

        $request->end($body);

        return $deferred->promise();
    }

    protected function expectMessages($messages)
    {
        $this->expectedMessages = $messages;
    }

    protected function assertEvents($expected, $actual)
    {
        $this->assertSameSize($expected, $actual);

        foreach ($expected as $expectedEvent) {
            $actualEvent = array_shift($actual);
            $this->assertEventMessagesEqual($expectedEvent, $actualEvent);
        }
    }

    protected function assertEventMessagesEqual(EventMessage $em1, EventMessage $em2)
    {
        // we are not checking the publication id or subscription
        $em2->setPublicationId($em1->getPublicationId());
        $em2->setSubscriptionId($em1->getSubscriptionId());

        $this->assertEquals(json_encode($em1), json_encode($em2), "EventMessages are equal");
    }
}