<? require_once dirname(__DIR__).'/autoload.php';

function execute(Truman_Buck $buck) {
	ob_start();
	@trigger_error('');
	$data['buck']    = $buck;
	$data['runtime'] = -microtime(true);
	try {
		$data['retval'] = @$buck->invoke();
	} catch (Exception $ex) {
		$data['exception'] = $ex;
	}
	$error = error_get_last();
	if (strlen($error['message'])) {
		$data['error'] = $error;
	} if ($output = ob_get_clean()) {
		$data['output'] = $output;
	}
	$data['runtime'] += microtime(true);
	$result = Truman_Result::newInstance(
		isset($data['retval']) && $data['retval'],
		(object) $data
	);
	return $result;
}

function tick(array $inputs) {

	if (!stream_select($inputs, $i, $j, 1))
		return true;

	$input = trim(fgets($inputs[0]));
	$buck  = @unserialize($input);
	if ($buck instanceof Truman_Buck)
		print execute($buck)->asXML();
	else
		error_log("Huh? '{$input}' is not a serialize()'d Truman_Buck");

	return true;

}

function terminate() {
	print "\nBye!\n";
	exit(0);
}

function setup_process() {
	declare(ticks = 1);
	ini_set('error_log', false);
	pcntl_signal(SIGINT, 'terminate');
	pcntl_signal(SIGTERM, 'terminate');
}

function require_all(array $include_paths) {
	foreach ($include_paths as $include_path)
		require_once $include_path;
}

function main(array $argv) {
	setup_process();
	require_all(array_slice($argv, 1));
	while(tick(array(STDIN)));
}

main($argv);