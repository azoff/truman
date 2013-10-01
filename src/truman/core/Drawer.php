<? namespace truman\core;

use truman\interfaces\LoggerContext;

/**
 * Class Drawer Does the work of executing Bucks
 * @package truman\core
 */
class Drawer implements \JsonSerializable, LoggerContext {

	const LOGGER_TYPE = 'DRAWER';

	/**
	 * Occurs when this Drawer is instantiated
	 */
	const LOGGER_EVENT_INIT    = 'INIT';

	/**
	 * Occurs when this Drawer exits
	 */
	const LOGGER_EVENT_EXIT    = 'EXIT';

	/**
	 * Occurs when this Drawer encounters a recoverable error
	 */
	const LOGGER_EVENT_ERROR   = 'ERROR';

	/**
	 * Occurs when this Drawer encounters a fatal error
	 */
	const LOGGER_EVENT_FATAL   = 'FATAL';

	/**
	 * Options for this Drawer's internal Logger
	 */
	const OPTION_LOGGER_OPTIONS = 'logger_options';

	/**
	 * The time to wait for data from the stream descriptors
	 */
	const OPTION_TIMEOUT = 'timeout';

	/**
	 * The input stream descriptor
	 */
	const OPTION_STREAM_INPUT = 'stream_input';

	/**
	 * The output stream descriptor
	 */
	const OPTION_STREAM_OUTPUT = 'stream_output';

	private $data;
	private $logger;
	private $original_memory_limit;
	private $original_time_limit;
	private $input, $output;
	private $timeout;

	private static $_DEFAULT_OPTIONS = [
		self::OPTION_LOGGER_OPTIONS     => [],
		self::OPTION_TIMEOUT            => 0,
		self::OPTION_STREAM_INPUT       => STDIN,
		self::OPTION_STREAM_OUTPUT      => STDOUT,
	];

	/**
	 * Runs a Drawer from the command line
	 * @param array $argv The arguments passed into the command line
	 * @param array $option_keys Any options to set by command line (defaults to all options)
	 */
	public static function main(array $argv, array $option_keys = null) {
		$reqs    = Util::getArgs($argv);
		$options = Util::getOptions($option_keys, self::$_DEFAULT_OPTIONS);
		$drawer = new Drawer($reqs, $options);
		pcntl_signal(SIGTERM,      [$drawer, 'shutdown']);
		pcntl_signal(SIGINT,       [$drawer, 'shutdown']);
		register_shutdown_function([$drawer, 'shutdown']);
		exit($drawer->poll());
	}

	/**
	 * Called when the script exits, used to log and output any erroneous Buck execution
	 */
	public function shutdown($status_code = -1) {

		// something bad happened; let papa know
		if (isset($this->data)) {
			$error = error_get_last();
			$this->logger->log(self::LOGGER_EVENT_FATAL, $error);
			if (isset($error['message']{0}))
				$this->data['error'] = $error;
			if ($output = ob_get_clean())
				$this->data['output'] = $output;
			$this->data['runtime'] += microtime(true);
			$this->data['memory'] = Util::getMemoryUsage();

			$result = new Result(false, (object) $this->data);

			$this->result_log($result);
			$this->result_write($result);
			$status_code = $error['type'];
		}

		if ($status_code >= 0) {
			$this->logger->log(self::LOGGER_EVENT_EXIT, $status_code);
			exit($status_code);
		}

	}

	/**
	 * Creates a new Drawer
	 * @param array $requirements A list of include files to start the Drawer with
	 * @param array $options Optional settings for this Drawer. See Drawer::$_DEFAULT_OPTIONS
	 */
	public function __construct(array $requirements = [], array $options = []) {
		$options += self::$_DEFAULT_OPTIONS;
		$this->timeout = $options[self::OPTION_TIMEOUT];
		$this->input   = $options[self::OPTION_STREAM_INPUT];
		$this->output  = $options[self::OPTION_STREAM_OUTPUT];
		$this->logger  = new Logger($this, $options[self::OPTION_LOGGER_OPTIONS]);
		$this->original_memory_limit = ini_get('memory_limit');
		$this->original_time_limit = ini_get('max_execution_time');
		pcntl_signal(SIGALRM, [$this, 'timeoutError'], true);
		foreach ($requirements as $requirement)
			require_once $requirement;
		$this->logger->log(self::LOGGER_EVENT_INIT, $requirements);
	}

	/**
	 * @inheritdoc
	 */
	function __toString() {
		$id = $this->getLoggerId();
		return "Drawer<{$id}>";
	}

	/**
	 * Called by the pcntl extension if the script times out.
	 */
	public function timeoutError() {
		$runtime = $this->data['runtime'] + microtime(true);
		@trigger_error("Script timed out after {$runtime} seconds", E_USER_WARNING);
	}

	/**
	 * @inheritdoc
	 */
	public function jsonSerialize() {
		return $this->__toString();
	}

	/**
	 * @inheritdoc
	 */
	public function getLoggerType() {
		return self::LOGGER_TYPE;
	}

	/**
	 * @inheritdoc
	 */
	public function getLoggerId() {
		return getmypid();
	}

	/**
	 * @inheritdoc
	 */
	public function getLogger() {
		return $this->logger;
	}

	/**
	 * Calls tick() until it returns a status code >= 0
	 * @return int A status code >= 0
	 */
	public function poll() {
		declare(ticks = 1);
		do $status = $this->tick();
		while($status < 0);
		return (int) $status;
	}

	/**
	 * The basic work cycle for a Drawer. Receives input from the input stream and writes the result to the output stream
	 * @return int -1 to continue tick()ing, anything >= 0 will stop ticking
	 */
	public function tick() {

		$inputs = [$this->input];

		if (!@stream_select($inputs, $i, $j, $this->timeout))
			return -1;

		$input = fgets(reset($inputs));
		$buck  = Util::streamDataDecode($input);

		if (is_null($buck)) return -1;

		$valid = $buck instanceof Buck;
		if (!$valid) {
			$this->logger->log(self::LOGGER_EVENT_ERROR, $input);
			return -1;
		}

		$this->result_write($this->execute($buck));

		if ($buck instanceof Notification)
			if ($buck->isDrawerSignal())
				return (int) $buck->getNotice();

		return -1;

	}

	/**
	 * Writes an execution result to the output stream
	 * @param Result $result The execution Result
	 */
	private function result_write(Result $result) {
		if (!Util::writeObjectToStream($result, $this->output))
			$this->logger->log(self::LOGGER_EVENT_ERROR, 'UNABLE TO WRITE TO STDOUT');
	}

	/**
	 * Logs an execution result
	 * @param Result $result The execution result
	 */
	private function result_log(Result $result) {
		$data  = (array) $result->getData();
		$buck  = $data['buck'];
		$event = $result->wasSuccessful() ? Buck::LOGGER_EVENT_EXECUTE_COMPLETE : Buck::LOGGER_EVENT_EXECUTE_ERROR;
		unset($data['buck']);
		$buck->getLogger()->log($event, $data);
	}

	/**
	 * Executes a Buck under a monitored context. This is the only place Bucks should be invoke()d.
	 * @param Buck $buck The Buck to execute
	 * @return Result The result of Buck execution
	 */
	public function execute(Buck $buck) {

		$pid = $this->getLoggerId();
		$buck->getLogger()->log(Buck::LOGGER_EVENT_EXECUTE_START, $pid);

		$context = $buck->getContext();
		Buck::setThreadContext($pid, $context);

		ob_start();
		@trigger_error('');

		$this->data                = [];
		$this->data['pid']         = $pid;
		$this->data['buck']        = $buck;
		$this->data['runtime']     = -microtime(true);
		$this->data['memory_base'] = TRUMAN_BASE_MEMORY;

		ini_set('memory_limit', $buck->getMemoryLimit());
		pcntl_alarm($buck->getTimeLimit());

		try {
			$this->data['retval'] = @$buck->invoke();
		} catch (Exception $ex) {
			$this->data['exception'] = $ex;
		}

		pcntl_alarm($this->original_time_limit);
		ini_set('memory_limit', $this->original_memory_limit);

		$error = error_get_last();
		if (isset($error['message']{0}))
			$this->data['error'] = $error;
		if ($output = ob_get_clean())
			$this->data['output'] = $output;
		$this->data['runtime'] += microtime(true);
		$this->data['memory']   = Util::getMemoryUsage();

		Buck::unsetThreadContext($pid);

		$data   = (object) $this->data;
		$passed = !isset($data->exception) && !isset($data->error);

		unset($this->data);
		gc_collect_cycles();

		$result = new Result($passed, $data);

		$this->result_log($result);

		return $result;

	}

}