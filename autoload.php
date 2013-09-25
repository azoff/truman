<?

define('TRUMAN_HOME', __DIR__);
define('TRUMAN_BASE_MEMORY', memory_get_usage(true));

spl_autoload_register(function($class) {
	
	if (strpos($class, 'truman') !== 0)
		return;

	$basename = str_replace('\\', '/', $class);
	$abspath  = TRUMAN_HOME."/src/{$basename}.php";

	if (!is_readable($abspath))
		return;

	require_once $abspath;

});