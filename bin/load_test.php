#!/usr/bin/env php
<? require_once dirname(__DIR__) . '/autoload.php';
# starts the load test monitor
# usage: bin/load_test.php [options], see LoadTest::$_DEFAULT_OPTIONS

truman\test\load\LoadTest::main($argv);