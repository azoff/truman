#!/usr/bin/env php
<? require_once dirname(__DIR__).'/autoload.php';
# spawns a local Desk
# usage: bin/desk.php inbound_socket_spec [options], see Drawer::$_DEFAULT_OPTIONS

truman\core\Drawer::main($argv);