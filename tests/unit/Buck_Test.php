<? require_once dirname(dirname(__DIR__)) . '/autoload.php';

use truman\Buck;

class Buck_Test extends PHPUnit_Framework_TestCase {
	
	public function testInvoke() {
		$buck = new Buck('is_null', [null]);
		$this->assertTrue($buck->invoke());
	}

	public function testInvokeArgs() {
		$buck = new Buck('ceil', [10.5]);
		$this->assertEquals(11, $buck->invoke());
	}

	public function testInvokeKwargs() {
		$buck = new Buck('ceil', ['number' => 10.5]);
		$this->assertEquals(11, $buck->invoke());
	}

	public function testInvalidCallable() {
		$error = null;
		try { new Buck(false); }
		catch(Exception $ex) { $error = $ex; }
		$this->assertInstanceOf('Exception', $error);
	}

	public function testDedupe() {
		$buck1 = new Buck();
		$buck2 = new Buck();
		$this->assertEquals($buck1->getUUID(), $buck2->getUUID());
	}

}