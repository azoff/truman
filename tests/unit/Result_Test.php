<? namespace truman\test\unit;
require_once dirname(dirname(__DIR__)) . '/autoload.php';

use truman\core\Buck;
use truman\core\Result;

class Result_Test extends \PHPUnit_Framework_TestCase {
	
	public function testTrue() {
		$result = new Result(new Buck());
		$this->assertTrue($result->wasSuccessful());
		$this->assertFalse($result->wasErroneous());
	}

	public function testFalse() {
		$result = new Result(new Buck(), [Result::DETAIL_ERROR => true]);
		$this->assertFalse($result->wasSuccessful());
		$this->assertTrue($result->wasErroneous());
	}

	public function testData() {
		$result = new Result(new Buck(), [Result::DETAIL_OUTPUT => $expected = 'test']);
		$this->assertEquals($expected, $result->getOutput());
	}

}