#!/usr/bin/env php

<? require_once dirname(__DIR__) . '/autoload.php';

use truman\core\Util;
use truman\test\load\Spammer;

$options = Util::getOptions([
	'd' => 'job_delay_max::',
	'u' => 'job_duration_max::'
]);

Spammer::main($argv, $options);