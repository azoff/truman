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

	/**
	 * @var Buck
	 */
	private $current_buck;

	private $logger;
	private $result_options;
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
		$runtime = $this->result_options[Result::DETAIL_RUNTIME] + microtime(true);
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
		$this->read();
		$status = $this->execute();
		$this->write();
		return $status;
	}

	/**
	 * Reads the input and tries to extract a Buck from the stream
	 * @return int the status code from the extracted Buck
	 */
	private function read() {

		$inputs = [$this->input];

		if (!@stream_select($inputs, $i, $j, $this->timeout))
			return;

		$input = fgets(reset($inputs));
		$buck  = Util::streamDataDecode($input);

		if (is_null($buck)) return;
		if ($buck instanceof Buck) $this->setBuck($buck);
		else $this->logger->log(self::LOGGER_EVENT_ERROR, $input);

	}

	/**
	 * Sets the current internal Buck
	 * @param Buck $buck The internal buck to execute
	 */
	public function setBuck(Buck $buck) {
		$this->current_buck = $buck;
	}

	/**
	 * Gets the result of the last execution
	 * @return null|Result
	 */
	public function getResult() {
		if (!$this->current_buck)   return null;
		if (!$this->result_options) return null;
		return new Result($this->current_buck, $this->result_options);
	}

	/**
	 * Writes and logs the Buck execution result
	 * @return Result The result of Buck execution
	 */
	private function write() {

		if (is_null($result = $this->getResult()))
			return null;

		$event  = $result->wasSuccessful()      ?
			Buck::LOGGER_EVENT_EXECUTE_COMPLETE :
			Buck::LOGGER_EVENT_EXECUTE_ERROR    ;

		$this->current_buck->getLogger()->log($event, $result);

		if (!Util::writeObjectToStream($result, $this->output))
			$this->logger->log(self::LOGGER_EVENT_ERROR, 'UNABLE TO WRITE TO STDOUT');

		unset($this->current_buck);
		unset($this->result_options);

		return $result;

	}

	/**
	 * Executes a Buck under a monitored context. This is the only place Bucks should be invoke()d.
	 * @return int The status code of the executed Buck
	 */
	public function execute() {

		if (!$this->current_buck) return -1;

		$this->executeSetup();

		try {
			$this->result_options[Result::DETAIL_RETVAL] = @$this->current_buck->invoke();
		} catch (Exception $ex) {
			$this->result_options[Result::DETAIL_EXCEPTION] = $ex;
		}

		$this->executeTeardown();

		if ($this->current_buck instanceof Notification)
			if ($this->current_buck->isDrawerSignal())
				return (int) $this->current_buck->getNotice();

		return -1;

	}

	/**
	 * Sets up the execution fixture for a Buck
	 */
	private function executeSetup() {
		$pid  = $this->getLoggerId();
		$buck = $this->current_buck;
		$buck->getLogger()->log(Buck::LOGGER_EVENT_EXECUTE_START, $pid);

		$context = $buck->getContext();
		Buck::setThreadContext($pid, $context);

		ob_start();
		@trigger_error('');

		$this->result_options = [Result::DETAIL_RUNTIME => -microtime(true)];

		ini_set('memory_limit',  $buck->getMemoryLimit());
		pcntl_alarm($buck->getTimeLimit());
	}

	/**
	 * Tears down the execution fixture for a Buck
	 */
	private function executeTeardown() {
		pcntl_alarm($this->original_time_limit);
		ini_set('memory_limit', $this->original_memory_limit);

		$error = error_get_last();
		if (isset($error['message']{0}))
			$this->result_options[Result::DETAIL_ERROR] = $error;

		if ($output = ob_get_clean())
			$this->result_options[Result::DETAIL_OUTPUT] = $output;

		$this->result_options[Result::DETAIL_RUNTIME] += microtime(true);
		Buck::unsetThreadContext($this->getLoggerId());
	}

	/**
	 * Called when the script exits, used to log and output any erroneous Buck execution
	 */
	public function shutdown($status_code = -1) {

		// buck has not been unset, something bad happened
		if (isset($this->current_buck)) {
			$this->executeTeardown();
			$result = $this->write();
			$error = $result->getError();
			$this->logger->log(self::LOGGER_EVENT_FATAL, $error);
			$status_code = $result->getErrorType();
		}

		if ($status_code >= 0) {
			$this->logger->log(self::LOGGER_EVENT_EXIT, $status_code);
			exit($status_code);
		}

	}

}