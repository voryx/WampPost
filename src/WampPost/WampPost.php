<?php

namespace WampPost;

use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\Http\Response;
use React\Http\Server as HttpServer;
use React\Promise\Deferred;
use React\Socket\Server;
use Thruway\CallResult;
use Thruway\Common\Utils;
use Thruway\Message\ErrorMessage;
use Thruway\Peer\Client;

class WampPost extends Client
{
    private $socket;

    public function __construct($realmName, LoopInterface $loop = null, $bindAddress = 'tcp://127.0.0.1:8181')
    {
        $loop = $loop ?: Factory::create();
        $http = new HttpServer([$this, 'handleRequest']);

        $this->socket = new Server($bindAddress, $loop);

        $http->listen($this->socket);

        parent::__construct($realmName, $loop);
    }

    public function onSessionStart($session, $transport)
    {
    }

    /**
     * @inheritDoc
     */
    public function onClose($reason)
    {
        $this->socket->close();

        parent::onClose($reason);
    }

    public function handleRequest(ServerRequestInterface $request, callable $next)
    {
        if ($request->getMethod() === 'POST' && $request->getUri()->getPath() === '/pub') {
            return $this->handlePublishHttpPost($request, $next);
        }

        if ($request->getMethod() === 'POST' && $request->getUri()->getPath() === '/call') {
            return $this->handleCallHttpRequest($request, $next);
        }

        return new Response(404, [], 'Not found');
    }

    private function handlePublishHttpPost(ServerRequestInterface $request, callable $next)
    {
        try {
            //{"topic": "com.myapp.topic1", "args": ["Hello, world"]}
            $json = json_decode($request->getBody());

            if ($json === null) {
                throw new \Exception('JSON decoding failed: ' . json_last_error_msg());
            }

            if (isset($json->topic)
                && is_scalar($json->topic)
                && isset($json->args)
                && is_array($json->args)
                && ($this->getPublisher() !== null)
            ) {
                $json->topic = strtolower($json->topic);
                if (!Utils::uriIsValid($json->topic)) {
                    throw new \Exception('Invalid URI: ' . $json->topic);
                }

                $argsKw  = isset($json->argsKw) && is_object($json->argsKw) ? $json->argsKw : null;
                $options = isset($json->options) && is_object($json->options) ? $json->options : null;
                $this->getSession()->publish($json->topic, $json->args, $argsKw, $options);
            } else {
                throw new \Exception('Invalid request: ' . json_encode($json));
            }
        } catch (\Exception $e) {
            return new Response(400, [], 'Bad Request: ' . $e->getMessage());
        }

        return new Response(200, [], 'pub');
    }

    private function handleCallHttpRequest(ServerRequestInterface $request, callable $next)
    {
        $deferred = new Deferred();
        try {
            //{"procedure": "com.myapp.procedure1", "args": ["Hello, world"], "argsKw": {}, "options": {} }
            $json = json_decode($request->getBody());

            if (isset($json->procedure)
                && Utils::uriIsValid($json->procedure)
                && ($this->getCaller() !== null)
            ) {
                $args    = isset($json->args) && is_array($json->args) ? $json->args : null;
                $argsKw  = isset($json->argsKw) && is_object($json->argsKw) ? $json->argsKw : null;
                $options = isset($json->options) && is_object($json->options) ? $json->options : null;

                $this->getSession()->call($json->procedure, $args, $argsKw, $options)->then(
                /** @param CallResult $result */
                    function (CallResult $result) use ($deferred) {
                        $responseObj          = new \stdClass();
                        $responseObj->result  = 'SUCCESS';
                        $responseObj->args    = $result->getArguments();
                        $responseObj->argsKw  = $result->getArgumentsKw();
                        $responseObj->details = $result->getDetails();

                        $deferred->resolve(new Response(200, ['Content-Type' => 'application/json'], json_encode($responseObj)));
                    },
                    function (ErrorMessage $msg) use ($deferred) {
                        $responseObj                = new \stdClass();
                        $responseObj->result        = 'ERROR';
                        $responseObj->error_uri     = $msg->getErrorURI();
                        $responseObj->error_args    = $msg->getArguments();
                        $responseObj->error_argskw  = $msg->getArgumentsKw();
                        $responseObj->error_details = $msg->getDetails();

                        // maybe return an error code here
                        $deferred->resolve(new Response(200, ['Content-Type' => 'application/json'], json_encode($responseObj)));
                    }
                );
            } else {
                // maybe return an error code here
                $deferred->resolve(new Response(200, [], 'No procedure set'));
            }
        } catch (\Exception $e) {
            // maybe return an error code here
            $deferred->resolve(new Response(200, [], 'Problem'));
        }

        return $deferred->promise();
    }
}
