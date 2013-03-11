<? require_once dirname(__DIR__) . '/autoload.php';

class TrumanDesk_Test extends PHPUnit_Framework_TestCase {

	public function testBuck() {
		$test = $this;
		$buck = new TrumanBuck('max', array(1, 2));
		$desk = new TrumanDesk();
		$desk->enqueueBuck($buck);
		$desk->start(function(TrumanResult $result) use ($buck, $test) {
			$test->assertInstanceOf('stdClass', $data = $result->data());
			$test->assertObjectHasAttribute('buck', $data);
			$test->assertEquals($buck, $data->buck);
			return false;
		});
	}

	public function testRetval() {
		$test = $this;
		$buck = new TrumanBuck('strlen', array('test'));
		$desk = new TrumanDesk();
		$desk->enqueueBuck($buck);
		$desk->start(function(TrumanResult $result) use ($buck, $test) {
			$test->assertInstanceOf('stdClass', $data = $result->data());
			$test->assertObjectHasAttribute('retval', $data);
			$test->assertEquals($buck->invoke(), $data->retval);
			return false;
		});
	}

	public function testException() {
		$test = $this;
		$buck = new TrumanBuck('TrumanException::throwNew', array('test', 'test'));
		$desk = new TrumanDesk(array('log_drawer_errors' => false));
		$desk->enqueueBuck($buck);
		$desk->start(function(TrumanResult $result) use ($buck, $test) {
			$test->assertInstanceOf('stdClass', $data = $result->data());
			$test->assertObjectHasAttribute('exception', $data);
			$test->assertInstanceOf('TrumanException', $data->exception);
			return false;
		});
	}

	public function testError() {
		$test = $this;
		$buck = new TrumanBuck('fopen');
		$desk = new TrumanDesk(array('log_drawer_errors' => false));
		$desk->enqueueBuck($buck);
		$desk->start(function(TrumanResult $result) use ($buck, $test) {
			$test->assertInstanceOf('stdClass', $data = $result->data());
			$test->assertObjectHasAttribute('error', $data);
			$test->assertEquals(2, $data->error['type']);
			return false;
		});
	}

	public function testOutput() {
		$test = $this;
		$buck = new TrumanBuck('phpcredits');
		$desk = new TrumanDesk();
		$desk->enqueueBuck($buck);
		$desk->start(function(TrumanResult $result) use ($buck, $test) {
			$test->assertInstanceOf('stdClass', $data = $result->data());
			$test->assertObjectHasAttribute('output', $data = $result->data());
			$test->assertContains('PHP Credits', $data->output);
			return false;
		});
	}

	public function testBuckSocket() {
		$test        = $this;
		$desk_opts   = array('buck_port' => 12345);
		$socket_opts = array('port' => 12345, 'force_mode' => TrumanSocket::MODE_CLIENT);

		$buck = new TrumanBuck('usleep', array(200));
		$desk = new TrumanDesk($desk_opts);
		$client = new TrumanSocket($socket_opts);

		$this->assertTrue($client->sendBuck($buck));

		$desk->start(function(TrumanResult $result) use ($buck, $test) {
			$test->assertInstanceOf('stdClass', $data = $result->data());
			$test->assertObjectHasAttribute('retval', $data);
			$test->assertEquals($buck->invoke(), $data->retval);
			return false;
		});
	}

	public function testStop() {
		$desk    = new TrumanDesk();
		$desk->enqueueBuck(new TrumanBuck('phpcredits'));
		$desk->start(function(TrumanResult $result, TrumanDesk $desk) {
			$desk->stop();
		});
	}

}