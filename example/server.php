#!/usr/bin/env php
# waits for incoming Bucks and executes them
<? require_once dirname(__DIR__).'/autoload.php';
truman\Truman::listen();