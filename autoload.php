<? 

spl_autoload_register(function($class) {
	
	if (strpos($class, 'Truman') === false)
		return;

	$basename = str_replace('_', '/', $class);
	$relpath  = "/src/{$basename}.php";
	$abspath  = __DIR__.$relpath;

	if (!file_exists($abspath))
		return;

	require_once $abspath;

});