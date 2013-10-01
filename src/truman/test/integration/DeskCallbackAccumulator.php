<? namespace truman\test\integration;

use truman\core\Desk;
use truman\core\Buck;
use truman\core\Result;

/**
 * Class DeskCallbackAccumulator Extracts Bucks and Results from Desk callbacks
 * @package truman\test\integration
 */
class DeskCallbackAccumulator {

	private $results;
	private $bucks_in;
	private $bucks_out;

	/**
	 * Creates a new DeskCallbackAccumulator instance
	 */
	public function __construct() {
		$this->reset();
	}

	/**
	 * Resets the accumulated Bucks and Results back to 0
	 */
	public function reset() {
		$this->results   = [];
		$this->bucks_in  = [];
		$this->bucks_out = [];
	}

	/**
	 * Gets a function reference to stop a Desk after some expected results
	 * @param int $expected_results The number of Results expected
	 * @return callable
	 */
	public function fnExpectedResults($expected_results = 0) {
		$accumulator = $this;
		return function(Result $result, Desk $desk) use (&$accumulator, $expected_results) {
			$accumulator->results[] = $result;
			if ($accumulator->getResultCount() >= $expected_results)
				$desk->stop();
		};
	}

	/**
	 * Gets options for a Desk that includes the handler for expected Results
	 * @param int $expected_results The number of Results expected
	 * @param array $desk_options Optional settings for the Desk. See Desk::$_DEFAULT_OPTIONS
	 * @return array
	 */
	public function optionsExpectedResults($expected_results = 0, array $desk_options = []) {
		$handler = [Desk::OPTION_RESULT_RECEIVED_HANDLER => $this->fnExpectedResults($expected_results)];
		return $handler + $desk_options;
	}

	/**
	 * Gets a function reference to stop a Desk after some expected Bucks received
	 * @param int $expected_bucks_in The number of received Bucks expected
	 * @return callable
	 */
	public function fnExpectedBucksIn($expected_bucks_in = 0) {
		$accumulator = $this;
		return function(Buck $buck_in, Desk $desk) use (&$accumulator, $expected_bucks_in) {
			$accumulator->bucks_in[] = $buck_in;
			if ($accumulator->getBuckInCount() >= $expected_bucks_in)
				$desk->stop();
		};
	}

	/**
	 * Gets options for a Desk that includes the handler for expected Bucks received
	 * @param int $expected_bucks_in The number of expected Bucks received
	 * @param array $desk_options Optional settings for the Desk. See Desk::$_DEFAULT_OPTIONS
	 * @return array
	 */
	public function optionsExpectedBucksIn($expected_bucks_in = 0, array $desk_options = []) {
		$handler = [Desk::OPTION_BUCK_RECEIVED_HANDLER => $this->fnExpectedBucksIn($expected_bucks_in)];
		return $handler + $desk_options;
	}

	/**
	 * Gets a function reference to stop a Desk after some expected Bucks processed
	 * @param int $expected_bucks_out The number of processed Bucks expected
	 * @return callable
	 */
	public function fnExpectedBucksOut($expected_bucks_out = 0) {
		$accumulator = $this;
		return function(Buck $bucks_out, Desk $desk) use (&$accumulator, $expected_bucks_out) {
			$accumulator->bucks_out[] = $bucks_out;
			if ($accumulator->getBuckOutCount() >= $expected_bucks_out)
				$desk->stop();
		};
	}

	/**
	 * Gets options for a Desk that includes the handler for expected Bucks processed
	 * @param int $expected_bucks_out The number of expected Bucks processed
	 * @param array $desk_options Optional settings for the Desk. See Desk::$_DEFAULT_OPTIONS
	 * @return array
	 */
	public function optionsExpectedBucksOut($expected_bucks_out = 0, array $desk_options = []) {
		$handler = [Desk::OPTION_BUCK_PROCESSED_HANDLER => $this->fnExpectedBucksOut($expected_bucks_out)];
		return $handler + $desk_options;
	}

	/**
	 * Gets the first Result in the list of Results accumulated
	 * @return Result
	 */
	public function getResultFirst() {
		return reset($this->getResults());
	}

	/**
	 * Gets the number of Results accumulated
	 * @return int
	 */
	public function getResultCount() {
		return count($this->results);
	}

	/**
	 * Gets the number of received Bucks accumulated
	 * @return int
	 */
	public function getBuckInCount() {
		return count($this->bucks_in);
	}

	/**
	 * Gets the number of processed bucks accumulated
	 * @return int
	 */
	public function getBuckOutCount() {
		return count($this->bucks_out);
	}

	/**
	 * Gets the accumulated Results
	 * @return array<Result>
	 */
	public function getResults() {
		return $this->results;
	}

	/**
	 * Gets the accumulated received Bucks
	 * @return array<Buck>
	 */
	public function getBucksIn() {
		return $this->bucks_in;
	}

	/**
	 * Gets the accumulated processed Bucks
	 * @return array<Buck>
	 */
	public function getBucksOut() {
		return $this->bucks_out;
	}

	/**
	 * Gets the accumulated Results' return values
	 * @return array
	 */
	public function getResultRetvals() {
		return $this->mapResults(function($result){
			$data = $result->getData();
			return isset($data->retval) ? $data->retval : null;
		});
	}

	/**
	 * Maps the accumulated Results to some other values
	 * @param callable $mapper The method to use as a mapper
	 * @return array The mapped Results
	 */
	public function mapResults($mapper) {
		return array_map($mapper, $this->getResults());
	}

}