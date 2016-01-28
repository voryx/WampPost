<?php

namespace WampPost;

use React\EventLoop\Factory;
use React\Http\Request;
use React\Http\Response;
use React\Socket\Server;
use Thruway\CallResult;
use Thruway\Common\Utils;
use Thruway\Peer\Client;

class WampPost extends Client {
    private $bindAddress;
    private $port;
    private $realmName;
    private $socket;
    private $http;

    function __construct($realmName, $loop = null, $bindAddress = '127.0.0.1', $port = 8181)
    {
        if ($loop === null) {
            $loop = Factory::create();
        }

        $this->bindAddress = $bindAddress;
        $this->port = $port;
        $this->realmName = $realmName;
        $this->socket = new Server($loop);
        $this->http = new \React\Http\Server($this->socket);

        $this->http->on('request', [$this, 'handleRequest']);

        $this->socket->listen($this->port, $bindAddress);

        parent::__construct($realmName, $loop);
    }

    public function start($startLoop = true) {

        parent::start($startLoop);
    }

    public function onSessionStart($session, $transport) {

    }

    /**
     * @param Request $request
     * @param Response $response
     */
    public function handleRequest($request, $response) {
        if ($request->getPath() == '/pub' && $request->getMethod() == 'POST') {
            $bodySnatcher = new BodySnatcher($request);
            $bodySnatcher->promise()->then(function ($body) use ($request, $response) {
                try {
                    //{"topic": "com.myapp.topic1", "args": ["Hello, world"]}
                    $json = json_decode($body);

                    if ($json === null) {
                        $response->writeHead(400, ['Content-Type' => 'text/plain', 'Connection' => 'close']);
                        $response->end("JSON decoding failed: " . json_last_error_msg());
                        return;
                    }

                    if (isset($json->topic) && isset($json->args)
                        && Utils::uriIsValid($json->topic)
                        && is_array($json->args)
                        && ($this->getPublisher() !== null)
                    ) {
                        $argsKw = isset($json->argsKw) && is_object($json->argsKw) ? $json->argsKw : null;
                        $options = isset($json->options) && is_object($json->opitons) ? $json->options : null;
                        $this->getSession()->publish($json->topic, $json->args, $argsKw, $options);
                    }
                } catch (\Exception $e) {
                    // should shut down everything
                    $response->writeHead(400, ['Content-Type' => 'text/plain', 'Connection' => 'close']);
                    $response->end(
                        "An exception was thrown: " . $e->getMessage() . PHP_EOL . $e->getTraceAsString()
                    );
                    return;
                }
                $response->writeHead(200, ['Content-Type' => 'text/plain', 'Connection' => 'close']);
                $response->end("pub");
            });
        } else if ($request->getPath() == '/call' && $request->getMethod() == 'POST') {
            $this->handleCallHttpRequest($request, $response);
        } else {
            $response->writeHead(404, ['Content-Type' => 'text/plain', 'Connection' => 'close']);
            $response->end("Not found");
        }
    }

    private function handleCallHttpRequest($request, $response) {
        $bodySnatcher = new BodySnatcher($request);
        $bodySnatcher->promise()->then(function ($body) use ($request, $response) {
            try {
                //{"procedure": "com.myapp.procedure1", "args": ["Hello, world"], "argsKw": {}, "options": {} }
                $json = json_decode($body);

                if (isset($json->procedure)
                    && Utils::uriIsValid($json->procedure)
                    && ($this->getCaller() !== null)
                ) {
                    $args = isset($json->args) && is_array($json->args) ? $json->args : null;
                    $argsKw = isset($json->argsKw) && is_object($json->argsKw) ? $json->argsKw : null;
                    $options = isset($json->options) && is_object($json->opitons) ? $json->options : null;

                    $this->getSession()->call($json->procedure, $args, $argsKw, $options)->then(
                        /** @param CallResult $result */
                        function (CallResult $result) use ($response) {
                            $responseObj = new \stdClass();
                            $responseObj->result = "SUCCESS";
                            $responseObj->args = $result->getArguments();
                            $responseObj->argsKw = $result->getArgumentsKw();
                            $responseObj->details = $result->getDetails();

                            $response->writeHead(200, ['Content-Type' => 'application/json', 'Connection' => 'close']);
                            $response->end(json_encode($responseObj));
                        },
                        function ($result) use ($response) {
                            $responseObj = new \stdClass();
                            $responseObj->result = "ERROR";

                            // maybe return an error code here
                            $response->writeHead(200, ['Content-Type' => 'application/json', 'Connection' => 'close']);
                            $response->end(json_encode($responseObj));
                        }
                    );
                } else {
                    // maybe return an error code here
                    $response->writeHead(200, ['Content-Type' => 'text/plain', 'Connection' => 'close']);
                    $response->end("No procedure set");
                }
            } catch (\Exception $e) {
                // maybe return an error code here
                $response->writeHead(200, ['Content-Type' => 'text/plain', 'Connection' => 'close']);
                $response->end("Problem");
            }
        });
    }
}