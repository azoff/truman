<? namespace truman;

class Result {

	private $data, $successful;

	function __construct($successful = true, $data = null) {
		$this->successful = $successful;
		$this->data       = $data;
	}

	public function __toString() {
		$data = $this->data() ?: new \stdClass();
		$buck = isset($data->buck) ? $data->buck : '(empty)';
		$stat = $this->is_successful() ? 'successful' : 'erroneous';
		return __CLASS__."<{$buck} => {$stat}>";
	}

	public function data() {
		return $this->data;
	}

	public function was_successful() {
		return (bool) $this->success;
	}

	public function was_erroneous() {
		return !$this->was_successful();
	}

}
