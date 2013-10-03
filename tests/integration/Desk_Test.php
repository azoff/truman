<? namespace truman\test\integration;
require_once dirname(dirname(__DIR__)) . '/autoload.php';

use truman\core\Buck;
use truman\core\Desk;
use truman\core\Drawer;
use truman\core\Notification;
use truman\core\Socket;
use truman\core\Client;
use truman\core\Util;

class Desk_Test extends \PHPUnit_Framework_TestCase {

	public function testScaleDrawers() {
		$accumulator = new DeskCallbackAccumulator();
		$options[Desk::OPTION_DRAWER_COUNT] = $expected_count = 1;
		$options = $accumulator->optionsExpectedResults(0, $options);
		$desk = new Desk(null, $options);
		$this->assertEquals($expected_count, $desk->getActiveDrawerCount());
		$notif = new Notification(Notification::TYPE_DESK_SCALE_UP, $increment = 2);
		$desk->enqueueBuck($notif); $desk->start();
		$this->assertEquals($expected_count += $increment, $desk->getActiveDrawerCount());
		$notif = new Notification(Notification::TYPE_DESK_SCALE_DOWN, $decrement = 1);
		$desk->enqueueBuck($notif); $desk->start();
		$this->assertEquals($expected_count - $decrement, $desk->getActiveDrawerCount());
		$desk->close();
	}

	public function testDelayBuck() {
		$accumulator = new DeskCallbackAccumulator();
		$options = $accumulator->optionsExpectedBucksOut(3, [Desk::OPTION_DRAWER_COUNT => 3]);
		$desk = new Desk(null, $options);
		$disabler = new Notification(Notification::TYPE_DESK_CONTEXT_DISABLE, $disabled_context = 'delay me');
		$disabled = new Buck(Buck::CALLABLE_NOOP, [1], [Buck::OPTION_CONTEXT => $disabled_context]);
		$enabled  = new Buck(Buck::CALLABLE_NOOP, [2], [Buck::OPTION_CONTEXT => 'don\'t delay me']);
		$desk->enqueueBuck($disabler);
		$desk->enqueueBuck($disabled);
		$desk->enqueueBuck($enabled);

		$this->assertEquals(3, count($desk->processBucks()));
		$this->assertEquals(0, $desk->getQueueSize());
		$this->assertEquals(1, $desk->getDelayedBuckCount());
		$this->assertEquals(1, $desk->getDelayedBuckCount($disabled_context));

		$enabler = new Notification(Notification::TYPE_DESK_CONTEXT_ENABLE, $disabled_context);
		$desk->enqueueBuck($enabler);

		$this->assertEquals($enabler, $desk->processBuck());
		$this->assertEquals(1, $desk->getQueueSize());

		$desk->close();
	}

	public function testRetryBuck() {
		$buck = new Buck('sleep', ['5']);
		$desk = new Desk(null, [Desk::OPTION_DRAWER_COUNT => 1]);
		$desk->enqueueBuck($buck);
		$this->assertEquals(1, $desk->getQueueSize());
		$this->assertEquals($buck, $desk->processBuck());
		$this->assertEquals(0, $desk->getQueueSize());
		$desk->killDrawers();
		$this->assertEquals(1, $desk->getQueueSize());
		$this->assertEquals($buck, $desk->nextBuck());
		$desk->close();
	}

	public function testInclude() {
		$includes[] = Util::tempPhpFile('function a(){ return "a"; }');
		$includes[] = Util::tempPhpFile('function b(){ return "b"; }');
		$accumulator = new DeskCallbackAccumulator();
		$options[Desk::OPTION_INCLUDE] = $includes;
		$options = $accumulator->optionsExpectedResults(2, $options);
		$desk = new Desk(null, $options);
		$desk->enqueueBuck(new Buck('a'));
		$desk->enqueueBuck(new Buck('b'));
		$desk->start();
		$retvals = $accumulator->getResultRetvals();
		$this->assertContains('a', $retvals);
		$this->assertContains('b', $retvals);
		$desk->close();
	}

	public function testDeDupe() {
		$desk   = new Desk();
		$first  = $desk->enqueueBuck(new Buck('usleep', [100]));
		$second = $desk->enqueueBuck(new Buck('usleep', [100]));
		$this->assertNotNull($first);
		$this->assertNull($second);
		$this->assertEquals(1, $desk->getQueueSize());
		$desk->close();
	}

	public function testPriority() {
		$desk = new Desk();
		$low = $desk->enqueueBuck(new Buck('usleep', [100], [Buck::OPTION_PRIORITY => Buck::PRIORITY_LOW]));
		$medium = $desk->enqueueBuck(new Buck('usleep', [101], [Buck::OPTION_PRIORITY => Buck::PRIORITY_MEDIUM]));
		$high = $desk->enqueueBuck(new Buck('usleep', [102], [Buck::OPTION_PRIORITY => Buck::PRIORITY_HIGH]));
		$this->assertNotNull($low);
		$this->assertNotNull($medium);
		$this->assertNotNull($high);
		$this->assertEquals($high, $desk->processBuck());
		$this->assertEquals($medium, $desk->processBuck());
		$this->assertEquals($low, $desk->processBuck());
		$desk->close();
	}

	public function testRefresh() {

		$expected = 3;
		$includes[Desk::OPTION_DRAWER_COUNT] = $expected;
		$includes[Desk::OPTION_AUTO_REAP_DRAWERS] = false;
		$accumulator = new DeskCallbackAccumulator();
		$options = $accumulator->optionsExpectedResults(1, $includes);

		$desk = new Desk(null, $options);
		$old_keys = $desk->getDrawerKeys();
		$this->assertEquals($expected, $desk->getActiveDrawerCount());
		$desk->enqueueBuck(new Notification(Notification::TYPE_DESK_REFRESH));
		$desk->start();
		$this->assertEquals($expected, $desk->getActiveDrawerCount());
		$this->assertNotEquals($old_keys, $desk->getDrawerKeys());

		$desk->close();

	}

	public function testBuck() {
		$buck = new Buck('max', [1, 2]);
		$result = $this->resultAttributeTest($buck);
		$this->assertEquals($buck, $result->getBuck());
	}

	public function testRetval() {
		$buck = new Buck('strlen', ['test']);
		$result = $this->resultAttributeTest($buck);
		$this->assertEquals($buck->invoke(), $result->getRetval());
	}

	public function testException() {
		// reflectionException is a missing method, causing a Reflection Exception
		// this tests for how missing methods are handled, and how exceptions are returned
		$buck = new Buck('reflectionException', ['test', 'test']);
		$result = $this->resultAttributeTest($buck);
		$this->assertInstanceOf('Exception', $result->getException());
	}

	public function testError() {
		$buck = new Buck('fopen');
		$result = $this->resultAttributeTest($buck);
		$this->assertEquals(E_WARNING, $result->getErrorType());
	}

	public function testMemoryLimit() {
		$buck = new Buck('str_repeat', ['$', 1000000], [Buck::OPTION_MEMORY_LIMIT => 2048]);
		$result = $this->resultAttributeTest($buck);
		$this->assertEquals(E_ERROR, $result->getErrorType());
	}

	public function testTimeLimit() {
		$buck = new Buck('sleep', [1], [Buck::OPTION_TIME_LIMIT => 1]);
		$result = $this->resultAttributeTest($buck);
		$this->assertEquals(E_USER_WARNING, $result->getErrorType());
	}

	public function testOutput() {
		$buck = new Buck('passthru', ['hostname']);
		$result = $this->resultAttributeTest($buck);
		$this->assertContains(gethostname(), $result->getOutput());
	}

	public function testBuckSocket() {
		$port   = 12345;
		$buck   = new Buck('strlen', ['test']);
		$accumulator = new DeskCallbackAccumulator();
		$desk = new Desk($port, $accumulator->optionsExpectedResults(1));
		$client = new Socket($port, [Socket::OPTION_FORCE_CLIENT_MODE => 1]);
		$this->assertTrue($client->send($buck));
		$desk->start();
		$this->assertInstanceOf('truman\core\Result', $result = $accumulator->getResultFirst());
		$this->assertEquals($buck->invoke(), $result->getRetval());
		$desk->close();
	}

	// won't work as long as receiveResults receives multiple results
	public function disabled_testStartStop() {
		$accumulator = new DeskCallbackAccumulator();
		$desk = new Desk(null, $accumulator->optionsExpectedResults());
		$desk->enqueueBuck(new Buck('strlen', ['foo']));
		$desk->enqueueBuck(new Buck('strlen', ['test']));
		$this->assertEquals(1, $accumulator->getResultCount());
		$desk->close();
	}

	public function testCleanReap() {


		$includes = [Desk::OPTION_AUTO_REAP_DRAWERS => false];
		$accumulator = new DeskCallbackAccumulator();
		$options = $accumulator->optionsExpectedResults(1, $includes);

		$desk = new Desk(null, $options);
		$expected = $desk->getDrawerCount();

		$this->assertEquals($expected, $desk->getActiveDrawerCount());

		$killer = new Notification(Notification::TYPE_DRAWER_SIGNAL, 0);
		$desk->enqueueBuck($killer);
		$desk->start();
		usleep(50000); // give it some time to die...

		$this->assertNotEquals($expected, $desk->getActiveDrawerCount());
		$desk->reapDrawers();
		$this->assertEquals($expected, $desk->getActiveDrawerCount());

		$desk->close();

	}

	public function testDirtyReap() {

		$includes[Desk::OPTION_AUTO_REAP_DRAWERS] = false;
		$includes[Desk::OPTION_INCLUDE] = Util::tempPhpFile('function dirtyKill(){ exit(); }');
		$accumulator = new DeskCallbackAccumulator();
		$options = $accumulator->optionsExpectedResults(1, $includes);

		$desk = new Desk(null, $options);
		$expected = $desk->getDrawerCount();

		$this->assertEquals($expected, $desk->getActiveDrawerCount());

		$desk->enqueueBuck(new Buck('dirtyKill'));
		$desk->start();
		usleep(50000); // give it some time to die...

		$this->assertNotEquals($expected, $desk->getActiveDrawerCount());
		$desk->reapDrawers();
		$this->assertEquals($expected, $desk->getActiveDrawerCount());

		$desk->close();

	}

	private function resultAttributeTest(Buck $buck) {
		$accumulator = new DeskCallbackAccumulator();
		$desk = new Desk(null, $accumulator->optionsExpectedResults(1));
		$desk->enqueueBuck($buck);
		$desk->start();
		$this->assertInstanceOf('truman\core\Result', $result = $accumulator->getResultFirst());
		$desk->close();
		return $result;
	}

}