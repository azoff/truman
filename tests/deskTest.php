<? require_once dirname(__DIR__) . '/autoload.php';

class TrumanDesk_Test extends PHPUnit_Framework_TestCase {

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
		$desk = new TrumanDesk(null, array(
			'include' => $includes
		));
		$desk->enqueueBuck(new TrumanBuck('a'));
		$desk->enqueueBuck(new TrumanBuck('b'));
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
		$desk   = new TrumanDesk();
		$first  = $desk->enqueueBuck(new TrumanBuck('usleep', array(100)));
		$second = $desk->enqueueBuck(new TrumanBuck('usleep', array(100)));
		$this->assertNotNull($first);
		$this->assertNull($second);
		$this->assertEquals(1, $desk->waitingCount());
	}

	public function testPriority() {
		$desk = new TrumanDesk();
		$low = $desk->enqueueBuck(new TrumanBuck('usleep', array(100), array('priority' => TrumanBuck::PRIORITY_LOW)));
		$medium = $desk->enqueueBuck(new TrumanBuck('usleep', array(101), array('priority' => TrumanBuck::PRIORITY_MEDIUM)));
		$high = $desk->enqueueBuck(new TrumanBuck('usleep', array(102), array('priority' => TrumanBuck::PRIORITY_HIGH)));
		$this->assertNotNull($low);
		$this->assertNotNull($medium);
		$this->assertNotNull($high);
		$this->assertEquals($high, $desk->processBuck());
		$this->assertEquals($medium, $desk->processBuck());
		$this->assertEquals($low, $desk->processBuck());
	}

	public function testBuck() {
		$buck = new TrumanBuck('max', array(1, 2));
		$desk = new TrumanDesk();
		$desk->enqueueBuck($buck);
		$results = $desk->start(array($this, 'stopOnResult'));
		$this->assertInstanceOf('stdClass', $data = array_pop($results)->data());
		$this->assertObjectHasAttribute('buck', $data);
		$this->assertEquals($buck, $data->buck);
		$desk->__destruct();
	}

	public function testRetval() {
		$buck = new TrumanBuck('strlen', array('test'));
		$desk = new TrumanDesk();
		$desk->enqueueBuck($buck);
		$results = $desk->start(array($this, 'stopOnResult'));
		$this->assertInstanceOf('stdClass', $data = array_pop($results)->data());
		$this->assertObjectHasAttribute('retval', $data);
		$this->assertEquals($buck->invoke(), $data->retval);
		$desk->__destruct();
	}

	public function testException() {
		$buck = new TrumanBuck('TrumanException::throwNew', array('test', 'test'));
		$desk = new TrumanDesk();
		$desk->enqueueBuck($buck);
		$results = $desk->start(array($this, 'stopOnResult'));
		$this->assertInstanceOf('stdClass', $data = array_pop($results)->data());
		$this->assertObjectHasAttribute('exception', $data);
		$this->assertInstanceOf('TrumanException', $data->exception);
		$desk->__destruct();
	}

	public function testError() {
		$buck = new TrumanBuck('fopen');
		$desk = new TrumanDesk();
		$desk->enqueueBuck($buck);
		$results = $desk->start(array($this, 'stopOnResult'));
		$this->assertInstanceOf('stdClass', $data = array_pop($results)->data());
		$this->assertObjectHasAttribute('error', $data);
		$this->assertEquals(2, $data->error['type']);
		$desk->__destruct();
	}

	public function testOutput() {
		$buck = new TrumanBuck('phpcredits');
		$desk = new TrumanDesk();
		$desk->enqueueBuck($buck);
		$results = $desk->start(array($this, 'stopOnResult'));
		$this->assertInstanceOf('stdClass', $data = array_pop($results)->data());
		$this->assertObjectHasAttribute('output', $data);
		$this->assertContains('PHP Credits', $data->output);
		$desk->__destruct();
	}

	public function testBuckSocket() {
		$port = 12345;
		$buck = new TrumanBuck('usleep', array(300));
		$desk = new TrumanDesk($port);
		$client = new TrumanSocket($port, array('force_client_mode' => 1));

		$this->assertTrue($client->sendBuck($buck));

		$results = $desk->start(array($this, 'stopOnResult'));
		$this->assertInstanceOf('stdClass', $data = array_pop($results)->data());
		$this->assertObjectHasAttribute('retval', $data);
		$this->assertEquals($buck->invoke(), $data->retval);
		$desk->__destruct();
	}

	public function testStartStop() {
		$count = 0;
		$desk = new TrumanDesk();
		$desk->enqueueBuck(new TrumanBuck());
		$desk->enqueueBuck(new TrumanBuck());
		$desk->start(function(TrumanDesk $desk) use (&$count) {
			$count++;
			$desk->stop();
		});
		$this->assertEquals(1, $count);
		$desk->__destruct();
	}

}