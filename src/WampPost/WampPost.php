<?php

namespace WampPost;

use React\EventLoop\Factory;
use React\Http\Request;
use React\Http\Response;
use React\Socket\Server;
use Thruway\CallResult;
use Thruway\Common\Utils;
use Thruway\Message\ErrorMessage;
use Thruway\Peer\Client;

class WampPost extends Client {
    private $bindAddress;
    private $port;
    private $realmName;

    /** @var Server */
    private $socket;
    private $http;

    public function __construct($realmName, $loop = null, $bindAddress = '127.0.0.1', $port = 8181)
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
     * @inheritDoc
     */
    public function onClose($reason)
    {
        $this->socket->shutdown();

        parent::onClose($reason);
    }

    /**
     * @param Request $request
     * @param Response $response
     */
    public function handleRequest(Request $request, Response $response) {
        if ($request->getPath() == '/pub' && $request->getMethod() == 'POST') {
            $this->handlePublishHttpPost($request, $response);
        } else if ($request->getPath() == '/call' && $request->getMethod() == 'POST') {
            $this->handleCallHttpRequest($request, $response);
        } else {
            $response->writeHead(404, ['Content-Type' => 'text/plain', 'Connection' => 'close']);
            $response->end("Not found");
        }
    }

    private function handlePublishHttpPost(Request $request, Response $response) {
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

                if (
                    isset($json->topic)
                    && isset($json->args)
                    && Utils::uriIsValid($json->topic)
                    && is_array($json->args)
                    && ($this->getPublisher() !== null)
                ) {
                    $argsKw = isset($json->argsKw) && is_object($json->argsKw) ? $json->argsKw : null;
                    $options = isset($json->options) && is_object($json->options) ? $json->options : null;
                    $this->getSession()->publish($json->topic, $json->args, $argsKw, $options);
                } else {
                    $errors = [];
                    if (!isset($json->topic)) {
                        $errors[] = 'Topic not set';
                    }
                    if (!isset($json->args)) {
                        $errors[] = 'Args not set';
                    }
                    if (!is_array($json->args)) {
                        $errors[] = 'Args is not an array, got ' . gettype($json->args);
                    }
                    if (!Utils::uriIsValid($json->topic)) {
                        $errors[] = 'Topic is not a valid URI';
                    }
                    if (!($this->getPublisher() !== null)) {
                        $errors[] = 'Publisher is not set';
                    }
                    $response->writeHead(400, ['Content-Type' => 'text/plain', 'Connection' => 'close']);
                    $response->end(
                        "The following errors occurred:" . PHP_EOL . PHP_EOL . implode(PHP_EOL, $errors)
                    );
                    return;
                }
            } catch (\Exception $e) {
                // should shut down everything
                $response->writeHead(400, ['Content-Type' => 'text/plain', 'Connection' => 'close']);
                $response->end("Bad Request: " . $e->getMessage());
                return;
            }
            $response->writeHead(200, ['Content-Type' => 'text/plain', 'Connection' => 'close']);
            $response->end("pub");
        });
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
                    $options = isset($json->options) && is_object($json->options) ? $json->options : null;

                    $this->getSession()->call($json->procedure, $args, $argsKw, $options)->then(
                    /** @param CallResult $result */
                        function (CallResult $result) use ($response) {
                            $responseObj          = new \stdClass();
                            $responseObj->result  = "SUCCESS";
                            $responseObj->args    = $result->getArguments();
                            $responseObj->argsKw  = $result->getArgumentsKw();
                            $responseObj->details = $result->getDetails();

                            $response->writeHead(200, ['Content-Type' => 'application/json', 'Connection' => 'close']);
                            $response->end(json_encode($responseObj));
                        },
                        function (ErrorMessage $msg) use ($response) {
                            $responseObj                = new \stdClass();
                            $responseObj->result        = "ERROR";
                            $responseObj->error_uri     = $msg->getErrorURI();
                            $responseObj->error_args    = $msg->getArguments();
                            $responseObj->error_argskw  = $msg->getArgumentsKw();
                            $responseObj->error_details = $msg->getDetails();

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