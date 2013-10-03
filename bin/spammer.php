#!/usr/bin/env php
<? require_once dirname(__DIR__) . '/autoload.php';
# starts the load test spammer
# usage: bin/spammer.php [options], see Spammer::$_DEFAULT_OPTIONS

truman\test\load\Spammer::main($argv);