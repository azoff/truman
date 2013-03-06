<? require_once dirname(__DIR__) . '/autoload.php';

class Truman_Result_Test extends PHPUnit_Framework_TestCase {
	
	public function testTrue() {
		$result = Truman_Result::newInstance(true);
		$this->assertTrue((bool)$result);
	}

	public function testFalse() {
		$result = Truman_Result::newInstance(false);
		$this->assertFalse((bool)$result);
	}

	public function testData() {
		$data = 'hello world';
		$result = Truman_Result::newInstance(true, $data);
		$this->assertEquals($result->data(), $data);
	}

}