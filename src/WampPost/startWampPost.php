<?php

require_once __DIR__ . "/../../vendor/autoload.php";

$wp = new \WampPost\WampPost('realm1', null, '127.0.0.1', 8181);

$wp->addTransportProvider(new \Thruway\Transport\PawlTransportProvider('ws://127.0.0.1:9090/'));

$wp->start();
