<?

if (!extension_loaded('sockets'))
	throw new RuntimeException('Truman requires the PHP Sockets Extension. http://php.net/manual/sockets.setup.php');

if (!extension_loaded('pcntl'))
	throw new RuntimeException('Truman requires the PHP PCNTL Extension. http://php.net/manual/pcntl.setup.php');

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