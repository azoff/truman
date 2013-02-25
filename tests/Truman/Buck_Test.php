<? require_once(dirname(dirname(__DIR__)).'/autoload.php');

class Truman_Buck_Test extends PHPUnit_Framework_TestCase {
	
	public function test_invoke() {
		$buck = new Truman_Buck('is_null');
		$this->assertTrue($buck->invoke());
	}

	public function test_invoke_args() {
		$args = array(10.5);
		$buck = new Truman_Buck('ceil', $args);
		$this->assertEquals(11, $buck->invoke());
	}

	public function test_invoke_kwargs() {
		$args = array('number' => 10.5);
		$buck = new Truman_Buck('ceil', $args);
		$this->assertEquals(11, $buck->invoke());
	}

	public function test_invalid_callable() {
		$error = null;
		try {
			$buck = new Truman_Buck(false, array());
		} catch(Exception $ex) {
			$error = $ex;	
		}
		$this->assertInstanceOf('Truman_Exception', $error);
	}

	public function test_non_strict_mode() {
		$error = null;
		try {
			$buck = new Truman_Buck('some_undefined_function', array(), false);
		} catch(Exception $ex) {
			$error = $ex;	
		}
		$this->assertNull($error);
	}

	public function test_strict_mode() {
		$error = null;
		try {
			$buck = new Truman_Buck('some_undefined_function', array(), true);
		} catch(Exception $ex) {
			$error = $ex;	
		}
		$this->assertInstanceOf('Truman_Exception', $error);
	}

}