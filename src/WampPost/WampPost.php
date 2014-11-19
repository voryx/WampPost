<?php

namespace WampPost;

use React\EventLoop\Factory;
use React\Http\Request;
use React\Http\Response;
use React\Socket\Server;
use React\Stream\BufferedSink;
use Thruway\Peer\Client;
use Thruway\Role\AbstractRole;
use Thruway\Transport\PawlTransportProvider;

class WampPost extends Client {
    private $routerAddress;
    private $bindAddress;
    private $port;
    private $realmName;
    private $loop;
    private $socket;
    private $http;

    function __construct($realmName, $routerAddress = 'ws://127.0.0.1:9090/', $bindAddress = '127.0.0.1', $port = 8181, $loop = null)
    {
        if ($loop === null) {
            $loop = Factory::create();
        }

        $this->routerAddress = $routerAddress;
        $this->bindAddress = $bindAddress;
        $this->port = $port;
        $this->realmName = $realmName;
        $this->socket = new Server($loop);
        $this->http = new \React\Http\Server($this->socket);

        $this->http->on('request', [$this, 'handleRequest']);

        $this->socket->listen($this->port, $bindAddress);

        parent::__construct($realmName, $loop);

        $this->addTransportProvider(new PawlTransportProvider($routerAddress));
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
            // get the body
            echo "Content length is " . $request->getHeaders()['Content-Length'] . "\n";

            BufferedSink::createPromise($request)->then(function ($body) use ($request, $response) {
                try {
                    //{"topic": "com.myapp.topic1", "args": ["Hello, world"]}
                    $json = json_decode($body);

                    if (isset($json->topic) && isset($json->args)
                        && AbstractRole::uriIsValid($json->topic)
                        && is_array($json->args)
                    ) {
                        $this->getSession()->publish($json->topic, $json->args);
                    }
                } catch (\Exception $e) {
                    // should shut down everything
                }
            });
            $response->writeHead(200, ['Content-Type' => 'text/plain']);
            $response->end("pub");
        } else {
            $response->writeHead(404, ['Content-Type' => 'text/plain']);
            $response->end("Not found");
        }
    }
}