#!/usr/bin/env php

<? require_once dirname(__DIR__) . '/autoload.php';

use truman\Util;
use truman\test\load\Spammer;

$options = Util::get_options([
	's' => 'desk_specs:',
	'd' => 'job_delay_max::',
	'u' => 'job_duration_max::'
]);

Spammer::main($argv, $options);