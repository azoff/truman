<? require_once dirname(dirname(__DIR__)) . '/autoload.php';

use truman\core\Result;

class Result_Test extends PHPUnit_Framework_TestCase {
	
	public function testTrue() {
		$result = new Result(true);
		$this->assertTrue($result->wasSuccessful());
		$this->assertFalse($result->wasErroneous());
	}

	public function testFalse() {
		$result = new Result(false);
		$this->assertFalse($result->wasSuccessful());
		$this->assertTrue($result->wasErroneous());
	}

	public function testData() {
		$result = new Result(true, $data = 'hello world');
		$this->assertEquals($result->getData(), $data);
	}

}