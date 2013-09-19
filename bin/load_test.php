#!/usr/bin/env php

<? require_once dirname(__DIR__) . '/autoload.php';

use truman\Util;
use truman\test\load\LoadTest;

$options = Util::get_options([
	'n' => 'desks::',
	'c' => 'clients::',
	'r' => 'drawers::',
	'd' => 'job_delay_max::',
	'u' => 'job_duration_max::'
]);

LoadTest::main($options);