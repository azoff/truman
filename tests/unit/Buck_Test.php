<? namespace truman\test\unit;
require_once dirname(dirname(__DIR__)) . '/autoload.php';

use truman\core\Buck;
use truman\core\Exception;

class Buck_Test extends \PHPUnit_Framework_TestCase {

	public function testContext() {

		$buck = new Buck();
		$this->assertEquals($buck->getUUID(), $buck->getContext());

		Buck::setThreadContext($pid = getmypid(), 'test');

		$buck = new Buck();
		$this->assertEquals(Buck::getThreadContext($pid), $buck->getContext());

		$buck = new Buck(Buck::CALLABLE_NOOP, [], [Buck::OPTION_CONTEXT => $context = 'foo']);
		$this->assertEquals($context, $buck->getContext());

		$contextBuck = new Buck();

		Buck::unsetThreadContext($pid);

		$buck = new Buck(Buck::CALLABLE_NOOP, [], [Buck::OPTION_CONTEXT => $contextBuck]);
		$this->assertEquals($contextBuck->getContext(), $buck->getContext());

		try {
			new Buck(Buck::CALLABLE_NOOP, [], [Buck::OPTION_CONTEXT => new \stdClass()]);
		} catch (Exception $ex) { }

		$this->assertTrue(isset($ex));

	}

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