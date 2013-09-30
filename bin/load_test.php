#!/usr/bin/env php
# starts the load test monitor
# usage: bin/load_test.php [options], see LoadTest::$_DEFAULT_OPTIONS
<? require_once dirname(__DIR__) . '/autoload.php';

truman\test\load\LoadTest::main($argv);