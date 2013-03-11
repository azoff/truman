<? require_once dirname(__DIR__) . '/autoload.php';

class TrumanResult_Test extends PHPUnit_Framework_TestCase {
	
	public function testTrue() {
		$result = TrumanResult::newInstance(true);
		$this->assertTrue((bool)$result);
	}

	public function testFalse() {
		$result = TrumanResult::newInstance(false);
		$this->assertFalse((bool)$result);
	}

	public function testData() {
		$data = 'hello world';
		$result = TrumanResult::newInstance(true, $data);
		$this->assertEquals($result->data(), $data);
	}

}