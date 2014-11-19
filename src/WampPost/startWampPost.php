<?php

require_once __DIR__ . "/../../vendor/autoload.php";

$wp = new \WampPost\WampPost('realm1');

$wp->start();
