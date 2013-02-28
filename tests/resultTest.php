<? require_once dirname(__DIR__) . '/autoload.php';

class Truman_Result_Test extends PHPUnit_Framework_TestCase {
	
	public function test_true() {
		$result = Truman_Result::newInstance(true);
		$this->assertTrue((bool)$result);
	}

	public function test_false() {
		$result = Truman_Result::newInstance(false);
		$this->assertFalse((bool)$result);
	}

	public function test_data() {
		$data = 'hello world';
		$result = Truman_Result::newInstance(true, $data);
		$this->assertEquals($result->data(), $data);
	}

}