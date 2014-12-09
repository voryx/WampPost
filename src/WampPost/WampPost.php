<?php

namespace WampPost;

use React\EventLoop\Factory;
use React\Http\Request;
use React\Http\Response;
use React\Socket\Server;
use React\Stream\BufferedSink;
use Thruway\Peer\Client;
use Thruway\Role\AbstractRole;

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

                    if (isset($json->topic) && isset($json->args)
                        && AbstractRole::uriIsValid($json->topic)
                        && is_array($json->args)
                        && ($this->getPublisher() !== null)
                    ) {
                        $this->getSession()->publish($json->topic, $json->args);
                    }
                } catch (\Exception $e) {
                    // should shut down everything
                }
                $response->writeHead(200, ['Content-Type' => 'text/plain', 'Connection' => 'close']);
                $response->end("pub");
            });
        } else {
            $response->writeHead(404, ['Content-Type' => 'text/plain', 'Connection' => 'close']);
            $response->end("Not found");
        }
    }
}