<? require_once dirname(__DIR__) . '/autoload.php';

class TrumanDesk_Test extends PHPUnit_Framework_TestCase {

	public function stopOnResult($desk, $in, $out, $result) {
		if (!is_null($result))
			$desk->stop();
		return $result;
	}

	public function testBuck() {
		$buck = new TrumanBuck('max', array(1, 2));
		$desk = new TrumanDesk();
		$desk->enqueueBuck($buck);
		$results = $desk->start(array($this, 'stopOnResult'));
		$this->assertInstanceOf('stdClass', $data = array_pop($results)->data());
		$this->assertObjectHasAttribute('buck', $data);
		$this->assertEquals($buck, $data->buck);
	}

	public function testRetval() {
		$buck = new TrumanBuck('strlen', array('test'));
		$desk = new TrumanDesk();
		$desk->enqueueBuck($buck);
		$results = $desk->start(array($this, 'stopOnResult'));
		$this->assertInstanceOf('stdClass', $data = array_pop($results)->data());
		$this->assertObjectHasAttribute('retval', $data);
		$this->assertEquals($buck->invoke(), $data->retval);
	}

	public function testException() {
		$buck = new TrumanBuck('TrumanException::throwNew', array('test', 'test'));
		$desk = new TrumanDesk();
		$desk->enqueueBuck($buck);
		$results = $desk->start(array($this, 'stopOnResult'));
		$this->assertInstanceOf('stdClass', $data = array_pop($results)->data());
		$this->assertObjectHasAttribute('exception', $data);
		$this->assertInstanceOf('TrumanException', $data->exception);
	}

	public function testError() {
		$buck = new TrumanBuck('fopen');
		$desk = new TrumanDesk();
		$desk->enqueueBuck($buck);
		$results = $desk->start(array($this, 'stopOnResult'));
		$this->assertInstanceOf('stdClass', $data = array_pop($results)->data());
		$this->assertObjectHasAttribute('error', $data);
		$this->assertEquals(2, $data->error['type']);
	}

	public function testOutput() {
		$buck = new TrumanBuck('phpcredits');
		$desk = new TrumanDesk();
		$desk->enqueueBuck($buck);
		$results = $desk->start(array($this, 'stopOnResult'));
		$this->assertInstanceOf('stdClass', $data = array_pop($results)->data());
		$this->assertObjectHasAttribute('output', $data);
		$this->assertContains('PHP Credits', $data->output);
	}

	public function testBuckSocket() {
		$test        = $this;
		$desk_opts   = array('buck_port' => 12345);
		$socket_opts = array('port' => 12345, 'force_mode' => TrumanSocket::MODE_CLIENT);

		$buck = new TrumanBuck('usleep', array(200));
		$desk = new TrumanDesk($desk_opts);
		$client = new TrumanSocket($socket_opts);

		$this->assertTrue($client->sendBuck($buck));

		$results = $desk->start(array($this, 'stopOnResult'));
		$this->assertInstanceOf('stdClass', $data = array_pop($results)->data());
		$test->assertObjectHasAttribute('retval', $data);
		$test->assertEquals($buck->invoke(), $data->retval);
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
	}

}