<? namespace truman;

class DeskAccumulator {

	private $results;
	private $bucks_in;

	public function __construct() {
		$this->reset();
	}

	public function reset() {
		$this->results  = [];
		$this->bucks_in = [];
	}

	public function fnExpectedResults($expected_results = 0) {
		$accumulator = $this;
		return function(Result $result, Desk $desk) use (&$accumulator, $expected_results) {
			$accumulator->results[] = $result;
			if ($accumulator->getResultCount() >= $expected_results)
				$desk->stop();
		};
	}

	public function optionsExpectedResults($expected_results = 0, array $desk_options = []) {
		$handler = ['result_received_handler' => $this->fnExpectedResults($expected_results)];
		return $handler + $desk_options;
	}

	public function fnExpectedBucksIn($expected_bucks_in = 0) {
		$accumulator = $this;
		return function(Buck $buck_in, Desk $desk) use (&$accumulator, $expected_bucks_in) {
			$accumulator->bucks_in[] = $buck_in;
			if ($accumulator->getBuckInCount() >= $expected_bucks_in)
				$desk->stop();
		};
	}

	public function optionsExpectedBucksIn($expected_bucks_in = 0, array $desk_options = []) {
		$handler = ['buck_received_handler' => $this->fnExpectedBucksIn($expected_bucks_in)];
		return $handler + $desk_options;
	}

	public function getResultFirst() {
		return reset($this->getResults());
	}

	public function getResultCount() {
		return count($this->results);
	}

	public function getBuckInCount() {
		return count($this->bucks_in);
	}

	public function getResults() {
		return $this->results;
	}

	public function getBucksIn() {
		return $this->bucks_in;
	}

	public function getResultRetvals() {
		return $this->mapResults(function($result){
			$data = $result->data();
			return isset($data->retval) ? $data->retval : null;
		});
	}

	public function mapResults($mapper) {
		return array_map($mapper, $this->getResults());
	}

}