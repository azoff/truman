<? namespace truman\core;

/*
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
*/

/**
 * Class Result Encapsulates the result of a Buck execution
 * @package truman\core
 */
class Result implements \JsonSerializable {

	private $pid;
	private $buck;
	private $error;
	private $retval;
	private $memory;
	private $output;
	private $runtime;
	private $exception;
	private $successful;
	private $memory_base;

	/**
	 * The PID of the PHP process the Buck executed on
	 */
	const DETAIL_PID = 'pid';

	/**
	 * A PHP Error encountered during execution
	 */
	const DETAIL_ERROR = 'error';

	/**
	 * The return value of the executed Buck
	 */
	const DETAIL_RETVAL = 'retval';

	/**
	 * The allocated memory above base script memory
	 */
	const DETAIL_MEMORY = 'memory';

	/**
	 * The output of the executed Buck
	 */
	const DETAIL_OUTPUT = 'output';

	/**
	 * The execution time of the Buck
	 */
	const DETAIL_RUNTIME = 'runtime';

	/**
	 * A PHP Exception encountered during Buck execution
	 */
	const DETAIL_EXCEPTION = 'exception';

	/**
	 * The base memory used for the script
	 */
	const DETAIL_MEMORY_BASE = 'memory_base';

	private static function getDefaultDetails(array $options = []) {
		return $options + [
			self::DETAIL_PID         => getmypid(),
			self::DETAIL_ERROR       => null,
			self::DETAIL_RETVAL      => null,
			self::DETAIL_MEMORY      => Util::getMemoryUsage(),
			self::DETAIL_OUTPUT      => null,
			self::DETAIL_RUNTIME     => 0,
			self::DETAIL_EXCEPTION   => null,
			self::DETAIL_MEMORY_BASE => TRUMAN_BASE_MEMORY,
		];
	}

	/**
	 * Creates a new Result instance
	 * @param Buck $buck The executed Buck
	 * @param array $details Any data to pass along about the executed Buck
	 * @throws Exception if Buck is null
	 */
	function __construct(Buck $buck, array $details = []) {
		if (is_null($this->buck = $buck))
			throw new Exception('Buck must not be null', [
				'context' => $this,
				'method'  => __METHOD__,
				'buck'    => $buck
			]);
		$details = self::getDefaultDetails($details);
		foreach ($details as $property => $value)
			$this->$property = $value;
		$this->successful = !($this->getError() || $this->getException());
	}

	/**
	 * @inheritdoc
	 */
	public function __toString() {
		$buck = $this->getBuck();
		$stat = $this->wasSuccessful() ? 'successful' : 'erroneous';
		return "Result<{$buck} => {$stat}>";
	}

	/**
	 * @inheritdoc
	 */
	public function jsonSerialize() {
		return [
			'success' => $this->wasSuccessful(),
			'buck'    => $this->getBuck()->getUUID(),
			'details' => [
				self::DETAIL_PID         => $this->getPid(),
				self::DETAIL_ERROR       => $this->getError(),
				self::DETAIL_RETVAL      => $this->getRetval(),
				self::DETAIL_MEMORY      => $this->getMemory(),
				self::DETAIL_OUTPUT      => $this->getOutput(),
				self::DETAIL_RUNTIME     => $this->getRuntime(),
				self::DETAIL_EXCEPTION   => $this->getException(),
				self::DETAIL_MEMORY_BASE => $this->getMemoryBase(),
			]
		];
	}

	/**
	 * Gets the executed Buck
	 * @return Buck
	 */
	public function getBuck() {
		return $this->buck;
	}

	/**
	 * Gets an error, should one have occurred during Buck execution
	 * @return array
	 */
	public function getError() {
		return $this->error;
	}

	/**
	 * Gets an error type, should one have occurred during Buck execution
	 * @return array
	 */
	public function getErrorType() {
		if (!is_array($error = $this->getError()))
			return -1;
		return (int) $error['type'];
	}

	/**
	 * Gets an Exception, should one have occurred during Buck execution
	 * @return Exception
	 */
	public function getException() {
		return $this->exception;
	}

	/**
	 * Gets the allocated memory used during Buck execution, in bytes
	 * @return int
	 */
	public function getMemory() {
		return $this->memory;
	}

	/**
	 * Gets the base memory used before Buck execution, in bytes
	 * @return mixed
	 */
	public function getMemoryBase() {
		return $this->memory_base;
	}

	/**
	 * Gets the output of Buck execution
	 * @return mixed
	 */
	public function getOutput() {
		return $this->output;
	}

	/**
	 * Gets the process ID of Buck execution
	 * @return int
	 */
	public function getPid() {
		return $this->pid;
	}

	/**
	 * Gets the return value of Buck execution
	 * @return mixed|null
	 */
	public function getRetval() {
		return $this->retval;
	}

	/**
	 * Gets the execution time of Buck execution, in seconds
	 * @return int
	 */
	public function getRuntime() {
		return $this->runtime;
	}

	/**
	 * Gets whether or not the Buck execution was successful
	 * @return bool
	 */
	public function wasSuccessful() {
		return (bool) $this->successful;
	}

	/**
	 * Gets whether or not the Buck execution failed
	 * @return bool
	 */
	public function wasErroneous() {
		return !$this->wasSuccessful();
	}

}
