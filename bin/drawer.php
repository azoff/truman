<? require_once dirname(__DIR__).'/autoload.php';

use truman\Buck;
use truman\Result;

function execute(Buck $buck) {

	ob_start();
	@trigger_error('');
	Buck::setEnvContext($buck);

	$data['pid']     = PID;
	$data['buck']    = $buck;
	$data['runtime'] = -microtime(1);
	try {
		$data['retval'] = @$buck->invoke();
	} catch (Exception $ex) {
		$data['exception'] = $ex;
	}
	$error = error_get_last();
	if (strlen($error['message']))
		$data['error'] = $error;
	if ($output = ob_get_clean())
		$data['output'] = $output;
	$data['runtime'] += microtime(1);

	Buck::unsetEnvContext($buck);

	$passed = true;
	$data   = (object) $data;
	if (isset($data->exception) || isset($data->error))
		$passed = false;
	else if (isset($data->retval))
		$passed = (bool) $data->retval;

	print serialize(new Result($passed, $data));

}

function tick(array $inputs) {

	if (!stream_select($inputs, $i, $j, 1))
		return true;

	$input = trim(fgets($inputs[0]));
	$buck  = unserialize($input);
	if ($buck instanceof Buck)
		execute($buck);
	else
		error_log("Huh? '{$input}' is not a serialize()'d Buck");

	return true;

}

function setup_process() {
	declare(ticks = 1);
	define('PID', getmypid());
}

function require_all(array $include_paths) {
	foreach ($include_paths as $include_path)
		require_once $include_path;
}

function main(array $argv) {
	require_all(array_slice($argv, 1));
	setup_process();
	do tick([STDIN]);
	while(true);
}

main($argv);