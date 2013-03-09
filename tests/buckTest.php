<? require_once dirname(__DIR__) . '/autoload.php';

class Truman_Buck_Test extends PHPUnit_Framework_TestCase {
	
	public function testInvoke() {
		$buck = new Truman_Buck('is_null', array(null));
		$this->assertTrue($buck->invoke());
	}

	public function testInvokeArgs() {
		$buck = new Truman_Buck('ceil', array(10.5));
		$this->assertEquals(11, $buck->invoke());
	}

	public function testInvokeKwargs() {
		$buck = new Truman_Buck('ceil', array('number' => 10.5));
		$this->assertEquals(11, $buck->invoke());
	}

	public function testInvalidCallable() {
		$error = null;
		try { new Truman_Buck(false); }
		catch(Exception $ex) { $error = $ex; }
		$this->assertInstanceOf('Truman_Exception', $error);
	}

	public function testDedupe() {
		$buck1 = new Truman_Buck();
		$buck2 = new Truman_Buck();
		$this->assertEquals($buck1->getUUID(), $buck2->getUUID());
	}

}