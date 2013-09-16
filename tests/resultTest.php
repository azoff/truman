<? require_once dirname(__DIR__) . '/autoload.php';

use truman\Result;

class Result_Test extends PHPUnit_Framework_TestCase {
	
	public function testTrue() {
		$result = new Result(true);
		$this->assertTrue((bool)$result->was_successful());
		$this->assertFalse((bool)$result->was_erroneous());
	}

	public function testFalse() {
		$result = new Result(false);
		$this->assertFalse((bool)$result->was_successful());
		$this->assertTrue((bool)$result->was_erroneous());
	}

	public function testData() {
		$result = new Result(true, $data = 'hello world');
		$this->assertEquals($result->data(), $data);
	}

}