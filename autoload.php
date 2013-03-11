<?

define('TRUMAN_HOME', __DIR__);

spl_autoload_register(function($class) {
	
	if (strpos($class, 'Truman') !== 0)
		return;

	$basename = str_replace('Truman', '/src/', $class);
	$abspath  = TRUMAN_HOME."{$basename}.php";

	if (!file_exists($abspath))
		return;

	require_once $abspath;

});