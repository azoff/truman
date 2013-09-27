#!/usr/bin/env php
<? require_once dirname(__DIR__).'/autoload.php';

// enqueue a job to sleep for 1 second
truman\Truman::enqueue('sleep', [1]);