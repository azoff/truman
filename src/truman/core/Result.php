<? namespace truman\core;

class Result implements \JsonSerializable {

	private $data, $successful;

	function __construct($successful = true, $data = null) {
		$this->successful = $successful;
		$this->data       = $data;
	}

	public function __toString() {
		$data = $this->data() ?: new \stdClass();
		$buck = isset($data->buck) ? $data->buck : '(empty)';
		$stat = $this->was_successful() ? 'successful' : 'erroneous';
		return "Result<{$buck} => {$stat}>";
	}

	public function jsonSerialize() {
		return $this->__toString();
	}

	public function data() {
		return $this->data;
	}

	public function was_successful() {
		return (bool) $this->successful;
	}

	public function was_erroneous() {
		return !$this->was_successful();
	}

}
