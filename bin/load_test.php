#!/usr/bin/env php

<? require_once dirname(__DIR__) . '/autoload.php';

use truman\Util;
use truman\test\load\LoadTest;

$options = Util::get_options([
	'n::' => 'desks::',
	'r::' => 'drawers::',
	's::' => 'spammers::',
	'a::' => 'refresh_rate::',
	'd::' => 'job_delay_max::',
	'u::' => 'job_duration_max::'
]);

LoadTest::main($options);