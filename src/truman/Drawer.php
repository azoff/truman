<? namespace truman;

class Drawer {

	private $options;

	private static $_DEFAULT_OPTIONS = [
		'log_errors'         => true,
		'log_bucks_received' => true,
		'timeout'            => 0
	];

	public static function main(array $argv, array $inputs, array $options = []) {
		$reqs   = array_slice($argv, 1);
		$drawer = new Drawer($reqs, $options);
		return $drawer->poll($inputs);
	}

	public function __construct(array $requirements = [], array $options = []) {
		$this->options = $options + self::$_DEFAULT_OPTIONS;
		foreach ($requirements as $requirement)
			require_once $requirement;
	}

	function __toString() {
		$pid = getmypid();
		return "Drawer<{$pid}>";
	}


	public function poll(array $inputs) {
		declare(ticks = 1);
		do $status = $this->tick($inputs);
		while($status < 0);
		return $status;
	}

	private function log($msg, $log_option = null, $code = -1) {
		if (is_null($log_option) || $this->options["log_{$log_option}"])
			error_log("{$this} {$msg}");
		return $code;
	}

	private function tick(array $streams) {

		if (!stream_select($inputs = $streams, $i, $j, $this->options['timeout']))
			return -1;

		$input = fgets(reset($inputs));
		if (!isset($input{0}))
			return -1;

		$buck  = unserialize($input);
		$valid = $buck instanceof Buck;
		if ($valid) $this->log("received {$buck}", 'bucks_received');
		else return $this->log('received unrecognized input, ignoring...', 'errors');

		$result = $this->execute($buck);

		print(serialize($result) . "\n");

		return -1;

	}

	private function execute(Buck $buck) {

		$pid = getmypid();
		$context = $buck->getContext();
		Buck::setThreadContext($pid, $context);

		ob_start();
		@trigger_error('');

		$data['pid']     = $pid;
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

		Buck::unsetThreadContext($pid);

		$passed = true;
		$data   = (object) $data;
		if (isset($data->exception) || isset($data->error))
			$passed = false;
		else if (isset($data->retval))
			$passed = (bool) $data->retval;

		return new Result($passed, $data);

	}

}