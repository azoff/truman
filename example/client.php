#!/usr/bin/env php
<? require_once dirname(__DIR__).'/autoload.php';

// calls sleep(1) in some other PHP process
truman\Truman::enqueue('sleep', [1]);