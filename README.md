WampPost
===========

WampPost is a [WAMP v2](http://wamp.ws/) (Web Application Messaging Protocol) Client built with 
[Thruway](https://github.com/voryx/Thruway) that allows publishing events to a realm via HTTP Post.

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

      $ php composer.phar require "voryx/wamppost":"dev-master"

If you need a WAMP router to test with, then start the sample with:

      $ php vendor/voryx/thruway/Examples/SimpleWsServer.php
    
Thruway is now running on 127.0.0.1 port 9090 

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
curl -H "Content-Type: application/json" -d '{"topic": ".myapp.topic1", "args": ["Hello, world"]}' http://127.0.0.1:8181/pub
```