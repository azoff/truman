<? namespace truman\core;

/**
 * Class Result Encapsulates the result of a Buck execution
 * @package truman\core
 */
class Result implements \JsonSerializable {

	private $data, $successful;

	/**
	 * Creates a new Result instance
	 * @param bool $successful true if the execution was successful, otherwise false
	 * @param mixed|null $data Any data to pass along about the executed Buck
	 */
	function __construct($successful = true, $data = null) {
		$this->successful = $successful;
		$this->data       = $data;
	}

	/**
	 * @inheritdoc
	 */
	public function __toString() {
		$data = $this->getData() ?: new \stdClass();
		$buck = isset($data->buck) ? $data->buck : '(empty)';
		$stat = $this->wasSuccessful() ? 'successful' : 'erroneous';
		return "Result<{$buck} => {$stat}>";
	}

	/**
	 * @inheritdoc
	 */
	public function jsonSerialize() {
		return $this->__toString();
	}

	/**
	 * Gets information about the executed Buck
	 * @return mixed|null
	 */
	public function getData() {
		return $this->data;
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
