<? namespace truman;

class Drawer implements \JsonSerializable {

	const KILLCODE = '__DRAWER_KILL__';

	private $options, $data;

	private static $_DEFAULT_OPTIONS = [
		'log_errors'         => true,
		'log_bucks_received' => true,
		'log_bucks_executed' => true,
		'timeout'            => 0,
		'stream_input'       => STDIN,
		'stream_output'      => STDOUT,
	];

	public static function main(array $argv, array $options = []) {
		$reqs   = array_slice($argv, 1);
		$drawer = new Drawer($reqs, $options);
		register_shutdown_function([$drawer, 'shutdown']);
		exit($drawer->poll());
	}

	public function shutdown() {

		// no jobs killed this script, it exited normally
		if (!isset($this->data))
			exit(0);

		// something bad happened; let papa know
		$error = error_get_last();
		if (isset($error['message']{0}))
			$this->data['error'] = $error;
		if ($output = ob_get_clean())
			$this->data['output'] = $output;
		$this->data['runtime'] += microtime(1);

		$result = new Result(false, (object) $this->data);
		Util::writeObjectToStream($result,  $this->options['stream_output']);

		exit(1);

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

	public function jsonSerialize() {
		return $this->__toString();
	}

	public function poll() {
		declare(ticks = 1);
		do $status = $this->tick();
		while($status < 0);
		return $status;
	}

	private function log($msg, $log_option = null, $code = -1) {
		if (is_null($log_option) || $this->options["log_{$log_option}"])
			error_log("{$this} {$msg}");
		return $code;
	}

	public function tick() {

		$output = $this->options['stream_output'];
		$inputs = [$this->options['stream_input']];

		if (!stream_select($inputs, $i, $j, $this->options['timeout']))
			return -1;

		$input = fgets(reset($inputs));
		$buck  = Util::streamDataDecode($input);

		if (is_null($buck)) return -1;

		$valid = $buck instanceof Buck;
		if ($valid) $this->log("received {$buck}", 'bucks_received');
		else return $this->log('received unrecognized input, ignoring...', 'errors');

		$result = $this->execute($buck);
		$this->log("executed {$buck}", 'bucks_executed');

		Util::writeObjectToStream($result, $output);
		$data = $result->data();

		if (isset($data->retval) && $data->retval === self::KILLCODE) {
			$this->log("received KILL code, exiting...", 'bucks_received');
			return 0;
		}

		return -1;

	}

	public function execute(Buck $buck) {

		$pid = getmypid();
		$context = $buck->getContext();
		Buck::setThreadContext($pid, $context);

		ob_start();
		@trigger_error('');

		$this->data            = [];
		$this->data['pid']     = $pid;
		$this->data['buck']    = $buck;
		$this->data['runtime'] = -microtime(1);
		try {
			$this->data['retval'] = @$buck->invoke();
		} catch (Exception $ex) {
			$this->data['exception'] = $ex;
		}
		$error = error_get_last();
		if (isset($error['message']{0}))
			$this->data['error'] = $error;
		if ($output = ob_get_clean())
			$this->data['output'] = $output;
		$this->data['runtime'] += microtime(1);

		Buck::unsetThreadContext($pid);

		$data   = (object) $this->data;
		$passed =
			!isset($data->exception) &&
			!isset($data->error)     && (
			!isset($data->retval)    ||
			(bool) $data->retval     );

		unset($this->data);

		return new Result($passed, $data);

	}

}