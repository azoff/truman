<? require_once dirname(__DIR__) . '/autoload.php';

class TrumanBuck_Test extends PHPUnit_Framework_TestCase {
	
	public function testInvoke() {
		$buck = new TrumanBuck('is_null', array(null));
		$this->assertTrue($buck->invoke());
	}

	public function testInvokeArgs() {
		$buck = new TrumanBuck('ceil', array(10.5));
		$this->assertEquals(11, $buck->invoke());
	}

	public function testInvokeKwargs() {
		$buck = new TrumanBuck('ceil', array('number' => 10.5));
		$this->assertEquals(11, $buck->invoke());
	}

	public function testInvalidCallable() {
		$error = null;
		try { new TrumanBuck(false); }
		catch(Exception $ex) { $error = $ex; }
		$this->assertInstanceOf('TrumanException', $error);
	}

	public function testDedupe() {
		$buck1 = new TrumanBuck();
		$buck2 = new TrumanBuck();
		$this->assertEquals($buck1->getUUID(), $buck2->getUUID());
	}

}