<? namespace truman;

class ResultAccumulator {

	private $results;

	public function __construct() {
		$this->reset();
	}

	public function reset() {
		$this->results = [];
	}

	public function getExpectHandler($expected_results = 0) {
		$accumulator = $this;
		return function(Result $result, Desk $desk) use (&$accumulator, $expected_results) {
			$accumulator->results[] = $result;
			if ($accumulator->getCount() >= $expected_results)
				$desk->stop();
		};
	}

	public function getExpectDeskOptions($expected_results = 0, array $desk_options = []) {
		$handler = ['result_received_handler' => $this->getExpectHandler($expected_results)];
		return $handler + $desk_options;
	}

	public function getFirst() {
		return reset($this->getResults());
	}

	public function getCount() {
		return count($this->results);
	}

	public function getResults() {
		return $this->results;
	}

	public function getRetvals() {
		return $this->mapResults(function($result){
			$data = $result->data();
			return isset($data->retval) ? $data->retval : null;
		});
	}

	public function mapResults($mapper) {
		return array_map($mapper, $this->getResults());
	}

}