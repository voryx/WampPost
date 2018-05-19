[![Build Status](https://travis-ci.org/voryx/WampPost.svg?branch=master)](https://travis-ci.org/voryx/WampPost)
WampPost
===========

WampPost is a [WAMP v2](http://wamp.ws/) (Web Application Messaging Protocol) Client built with 
[Thruway](https://github.com/voryx/Thruway) that allows publishing events and making RPC calls to a realm via HTTP Post.

WampPost is designed to be compatible with the [crossbar HTTP pusher service](http://crossbar.io/docs/HTTP-Pusher-Service/).
 
There is no security on the HTTP side, so if this is going to be used, it would be best to use it only
on localhost or behind some other security measure.

The WAMP side can be configured to use any security mechanism that is supported by Thruway,
but any authentication and authorization will be the same for all HTTP events.

### Quick Start with Composer

Create a directory for the test project

      $ mkdir wamppost

Switch to the new directory

      $ cd wamppost

Download Composer [more info](https://getcomposer.org/doc/00-intro.md#downloading-the-composer-executable)

      $ curl -sS https://getcomposer.org/installer | php
      
Download WampPost and dependencies

      $ php composer.phar require "voryx/wamppost" "thruway/pawl-transport"

If you need a WAMP router to test with, then start the sample with:

      $ php vendor/voryx/thruway/Examples/SimpleWsServer.php
    
Thruway is now running on 127.0.0.1 port 9090.

### PHP WampPost Client Usage

```php
<?php
require_once __DIR__ . "/vendor/autoload.php";

// create an HTTP server on port 8181
$wp = new \WampPost\WampPost('realm1', null, '127.0.0.1', 8181);

// add a transport to connect to the WAMP router
$wp->addTransportProvider(new \Thruway\Transport\PawlTransportProvider('ws://127.0.0.1:9090/'));

// start the WampPost client
$wp->start();
```

### Publishing messages

Now that you have a WampPost client, you will be able to publish messages to the realm using a standard HTTP post.

An example using curl:

```
curl -H "Content-Type: application/json" -d '{"topic": "com.myapp.topic1", "args": ["Hello, world"]}' http://127.0.0.1:8181/pub
```

### Making an RPC Call

```
curl -H "Content-Type: application/json" \
   -d '{"procedure": "com.myapp.my_rpc"}' \
   http://127.0.0.1:8181/call
```

RPC calls return a JSON object in the body:

```
{
    result: "SUCCESS",
    args: []
    argsKw: {}
    details: {}
}
```

### Running WampPost Client Internally in Your Thruway Router

This Client can be easily run as an internal client in your Thruway Router.

```php
<?php
require_once __DIR__ . "/vendor/autoload.php";

use Thruway\Peer\Router;
use Thruway\Transport\RatchetTransportProvider;

$router = new Router();

//////// WampPost part
// The WampPost client
// create an HTTP server on port 8181 - notice that we have to
// send in the same loop that the router is running on
$wp = new WampPost\WampPost('realm1', $router->getLoop(), '127.0.0.1', 8181);

// add a transport to connect to the WAMP router
$router->addTransportProvider(new Thruway\Transport\InternalClientTransportProvider($wp));
//////////////////////

// The websocket transport provider for the router
$transportProvider = new RatchetTransportProvider("127.0.0.1", 9090);
$router->addTransportProvider($transportProvider);
$router->start();
```
