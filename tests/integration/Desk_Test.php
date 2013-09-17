<? require_once dirname(dirname(__DIR__)) . '/autoload.php';

use truman\Buck;
use truman\Desk;
use truman\ResultAccumulator;
use truman\Socket;
use truman\Client;
use truman\Util;

class Desk_Test extends PHPUnit_Framework_TestCase {

	public function testInclude() {
		$includes[] = Util::tempPhpFile('function a(){ return "a"; }');
		$includes[] = Util::tempPhpFile('function b(){ return "b"; }');
		$accumulator = new ResultAccumulator();
		$options = ['include' => $includes];
		$options = $accumulator->getExpectDeskOptions(2, $options);
		$desk = new Desk(null, $options);
		$desk->enqueueBuck(new Buck('a'));
		$desk->enqueueBuck(new Buck('b'));
		$retvals = $accumulator->getRetvals();
		$this->assertContains('a', $retvals);
		$this->assertContains('b', $retvals);
	}

	public function testDeDupe() {
		$desk   = new Desk();
		$first  = $desk->enqueueBuck(new Buck('usleep', [100]));
		$second = $desk->enqueueBuck(new Buck('usleep', [100]));
		$this->assertNotNull($first);
		$this->assertNull($second);
		$this->assertEquals(1, $desk->waitingCount());
	}

	public function testPriority() {
		$desk = new Desk();
		$low = $desk->enqueueBuck(new Buck('usleep', [100], ['priority' => Buck::PRIORITY_LOW]));
		$medium = $desk->enqueueBuck(new Buck('usleep', [101], ['priority' => Buck::PRIORITY_MEDIUM]));
		$high = $desk->enqueueBuck(new Buck('usleep', [102], ['priority' => Buck::PRIORITY_HIGH]));
		$this->assertNotNull($low);
		$this->assertNotNull($medium);
		$this->assertNotNull($high);
		$this->assertEquals($high, $desk->processBuck());
		$this->assertEquals($medium, $desk->processBuck());
		$this->assertEquals($low, $desk->processBuck());
	}

	private function resultAttributeTest(Buck $buck, $attribute) {
		$accumulator = new ResultAccumulator();
		$desk = new Desk(null, $accumulator->getExpectDeskOptions(1));
		$desk->enqueueBuck($buck);
		$desk->start();
		$this->assertInstanceOf('truman\Result', $result = $accumulator->getFirst());
		$this->assertInstanceOf('stdClass', $data = $result->data());
		$this->assertObjectHasAttribute($attribute, $data);
		$desk->__destruct();
		return $data;
	}

	public function testBuck() {
		$buck = new Buck('max', [1, 2]);
		$data = $this->resultAttributeTest($buck, 'buck', $buck);
		$this->assertEquals($buck, $data->buck);
	}

	public function testRetval() {
		$buck = new Buck('strlen', ['test']);
		$data = $this->resultAttributeTest($buck, 'retval', $buck->invoke());
		$this->assertEquals($buck->invoke(), $data->retval);
	}

	public function testException() {
		$buck = new Buck('truman\Exception::throwNew', ['test', 'test']);
		$data = $this->resultAttributeTest($buck, 'exception');
		$this->assertInstanceOf('Exception', $data->exception);
	}

	public function testError() {
		$buck = new Buck('fopen');
		$data = $this->resultAttributeTest($buck, 'error');
		$this->assertEquals(2, $data->error['type']);
	}

	public function testOutput() {
		$buck = new Buck('phpcredits');
		$data = $this->resultAttributeTest($buck, 'output');
		$this->assertContains('PHP Credits', $data->output);
	}

	public function testBuckSocket() {
		$port   = 12345;
		$buck   = new Buck('strlen', ['test']);
		$client = new Socket($port, ['force_client_mode' => 1]);
		$this->assertTrue($client->sendBuck($buck));
		$accumulator = new ResultAccumulator();
		$desk = new Desk($port, $accumulator->getExpectDeskOptions(1));
		$desk->start();
		$this->assertInstanceOf('Result', $result = $accumulator->getFirst());
		$this->assertInstanceOf('stdClass', $data = $result->data());
		$this->assertObjectHasAttribute('retval', $data);
		$this->assertEquals($buck->invoke(), $data->retval);
		$desk->__destruct();
	}

	public function testStartStop() {
		$accumulator = new ResultAccumulator();
		$desk = new Desk(null, $accumulator->getExpectDeskOptions());
		$desk->enqueueBuck(new Buck('strlen', ['foo']));
		$desk->enqueueBuck(new Buck('strlen', ['test']));
		$desk->start();
		$this->assertEquals(1, $accumulator->getCount());
		$desk->__destruct();
	}

}