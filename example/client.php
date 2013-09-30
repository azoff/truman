#!/usr/bin/env php
# calls php's `sleep(int seconds)` in another process
<? require_once dirname(__DIR__).'/autoload.php';
truman\Truman::enqueue('sleep', [1]);