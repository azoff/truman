#!/usr/bin/env php
# starts the load test spammer
# usage: bin/spammer.php [options], see Spammer::$_DEFAULT_OPTIONS
<? require_once dirname(__DIR__) . '/autoload.php';

truman\test\load\Spammer::main($argv);