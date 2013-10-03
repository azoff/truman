#!/usr/bin/env php
<? require_once dirname(__DIR__).'/autoload.php';

// blocks and waits for client.php's Bucks
truman\Truman::listen();