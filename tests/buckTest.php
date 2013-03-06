<? require_once dirname(__DIR__) . '/autoload.php';

class Truman_Buck_Test extends PHPUnit_Framework_TestCase {
	
	public function test_invoke() {
		$buck = new Truman_Buck('is_null', array(null));
		$this->assertTrue($buck->invoke());
	}

	public function test_invoke_args() {
		$buck = new Truman_Buck('ceil', array(10.5));
		$this->assertEquals(11, $buck->invoke());
	}

	public function test_invoke_kwargs() {
		$buck = new Truman_Buck('ceil', array('number' => 10.5));
		$this->assertEquals(11, $buck->invoke());
	}

	public function test_invalid_callable() {
		$error = null;
		try { $buck = new Truman_Buck(false, array()); }
		catch(Exception $ex) { $error = $ex; }
		$this->assertInstanceOf('Truman_Exception', $error);
	}

}