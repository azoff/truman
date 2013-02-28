<?

define('TRUMAN_HOME', __DIR__);

spl_autoload_register(function($class) {
	
	if (strpos($class, 'Truman') === false)
		return;

	$basename = str_replace('_', '/', $class);
	$basepath = strtolower($basename);
	$relpath  = "/src/{$basepath}.php";
	$abspath  = TRUMAN_HOME.$relpath;

	if (!file_exists($abspath))
		return;

	require_once $abspath;

});