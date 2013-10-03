#!/usr/bin/env php
<? require_once dirname(__DIR__).'/autoload.php';

// calls php's `sleep(int seconds)` in server.php
truman\Truman::enqueue('sleep', [1]);