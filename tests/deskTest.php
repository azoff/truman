<? require_once dirname(__DIR__) . '/autoload.php';

use truman\Buck;
use truman\Desk;
use truman\Socket;
use truman\Client;

class Desk_Test extends PHPUnit_Framework_TestCase {

	private function newInclude($content) {
		$dir = '/tmp';
		$prefix = 'phpunit_desktest_';
		$path = tempnam($dir, $prefix);
		rename($path, $path = "{$path}.php");
		file_put_contents($path, "<?php {$content} ?>");
		return $path;
	}

	public function stopOnResult($desk, $in, $out, $result) {
		if (!is_null($result))
			$desk->stop();
		return $result;
	}

	public function testInclude() {
		$includes[] = $this->newInclude('function a(){ return "a"; }');
		$includes[] = $this->newInclude('function b(){ return "b"; }');
		$desk = new Desk(null, array(
			'include' => $includes
		));
		$desk->enqueueBuck(new Buck('a'));
		$desk->enqueueBuck(new Buck('b'));
		$count = 2;
		$results = $desk->start(function($desk, $i, $o, $result) use (&$count) {
			if (!is_null($result)) {
				if (--$count <= 0) $desk->stop();
				return $result->data()->retval;
			}
		});
		$this->assertContains('a', $results);
		$this->assertContains('b', $results);
		foreach ($includes as $include)
			unlink($include);
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

	public function testBuck() {
		$buck = new Buck('max', [1, 2]);
		$desk = new Desk();
		$desk->enqueueBuck($buck);
		$results = $desk->start([$this, 'stopOnResult']);
		$this->assertInstanceOf('stdClass', $data = array_pop($results)->data());
		$this->assertObjectHasAttribute('buck', $data);
		$this->assertEquals($buck, $data->buck);
		$desk->__destruct();
	}

	public function testRetval() {
		$buck = new Buck('strlen', ['test']);
		$desk = new Desk();
		$desk->enqueueBuck($buck);
		$results = $desk->start([$this, 'stopOnResult']);
		$this->assertInstanceOf('stdClass', $data = array_pop($results)->data());
		$this->assertObjectHasAttribute('retval', $data);
		$this->assertEquals($buck->invoke(), $data->retval);
		$desk->__destruct();
	}

	public function testException() {
		$buck = new Buck('Exception::throwNew', ['test', 'test']);
		$desk = new Desk();
		$desk->enqueueBuck($buck);
		$results = $desk->start([$this, 'stopOnResult']);
		$this->assertInstanceOf('stdClass', $data = array_pop($results)->data());
		$this->assertObjectHasAttribute('exception', $data);
		$this->assertInstanceOf('Exception', $data->exception);
		$desk->__destruct();
	}

	public function testError() {
		$buck = new Buck('fopen');
		$desk = new Desk();
		$desk->enqueueBuck($buck);
		$results = $desk->start([$this, 'stopOnResult']);
		$this->assertInstanceOf('stdClass', $data = array_pop($results)->data());
		$this->assertObjectHasAttribute('error', $data);
		$this->assertEquals(2, $data->error['type']);
		$desk->__destruct();
	}

	public function testOutput() {
		$buck = new Buck('phpcredits');
		$desk = new Desk();
		$desk->enqueueBuck($buck);
		$results = $desk->start([$this, 'stopOnResult']);
		$this->assertInstanceOf('stdClass', $data = array_pop($results)->data());
		$this->assertObjectHasAttribute('output', $data);
		$this->assertContains('PHP Credits', $data->output);
		$desk->__destruct();
	}

	public function testBuckSocket() {
		$port = 12345;
		$buck = new Buck('usleep', [300]);
		$desk = new Desk($port);
		$client = new Socket($port, ['force_client_mode' => 1]);

		$this->assertTrue($client->sendBuck($buck));

		$results = $desk->start([$this, 'stopOnResult']);
		$this->assertInstanceOf('stdClass', $data = array_pop($results)->data());
		$this->assertObjectHasAttribute('retval', $data);
		$this->assertEquals($buck->invoke(), $data->retval);
		$desk->__destruct();
	}

	public function testStartStop() {
		$count = 0;
		$desk = new Desk();
		$desk->enqueueBuck(new Buck());
		$desk->enqueueBuck(new Buck());
		$desk->start(function(Desk $desk) use (&$count) {
			$count++;
			$desk->stop();
		});
		$this->assertEquals(1, $count);
		$desk->__destruct();
	}

}