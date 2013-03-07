<? require_once dirname(__DIR__) . '/autoload.php';

class Truman_Desk_Test extends PHPUnit_Framework_TestCase {

	public function testBuck() {
		$test = $this;
		$buck = new Truman_Buck('max', array(1, 2));
		$desk = new Truman_Desk();
		$desk->enqueueBuck($buck);
		$desk->start(function(Truman_Result $result) use ($buck, $test) {
			$test->assertInstanceOf('stdClass', $data = $result->data());
			$test->assertObjectHasAttribute('buck', $data);
			$test->assertEquals($buck, $data->buck);
			return false;
		});
	}

	public function testRetval() {
		$test = $this;
		$buck = new Truman_Buck('strlen', array('test'));
		$desk = new Truman_Desk();
		$desk->enqueueBuck($buck);
		$desk->start(function(Truman_Result $result) use ($buck, $test) {
			$test->assertInstanceOf('stdClass', $data = $result->data());
			$test->assertObjectHasAttribute('retval', $data);
			$test->assertEquals($buck->invoke(), $data->retval);
			return false;
		});
	}

	public function testException() {
		$test = $this;
		$buck = new Truman_Buck('Truman_Exception::throwNew', array('test', 'test'));
		$desk = new Truman_Desk(array('log_drawer_errors' => false));
		$desk->enqueueBuck($buck);
		$desk->start(function(Truman_Result $result) use ($buck, $test) {
			$test->assertInstanceOf('stdClass', $data = $result->data());
			$test->assertObjectHasAttribute('exception', $data);
			$test->assertInstanceOf('Truman_Exception', $data->exception);
			return false;
		});
	}

	public function testError() {
		$test = $this;
		$buck = new Truman_Buck('fopen');
		$desk = new Truman_Desk(array('log_drawer_errors' => false));
		$desk->enqueueBuck($buck);
		$desk->start(function(Truman_Result $result) use ($buck, $test) {
			$test->assertInstanceOf('stdClass', $data = $result->data());
			$test->assertObjectHasAttribute('error', $data);
			$test->assertEquals(2, $data->error['type']);
			return false;
		});
	}

	public function testOutput() {
		$test = $this;
		$buck = new Truman_Buck('phpinfo');
		$desk = new Truman_Desk();
		$desk->enqueueBuck($buck);
		$desk->start(function(Truman_Result $result) use ($buck, $test) {
			$test->assertInstanceOf('stdClass', $data = $result->data());
			$test->assertObjectHasAttribute('output', $data = $result->data());
			$test->assertContains('phpinfo', $data->output);
			return false;
		});
	}

	public function testInboundSocket() {

		$test        = $this;
		$server_addr = '0.0.0.0:12345';
		$desk_opts   = array('inbound' => $server_addr);
		$socket_opts = array('force_mode' => Truman_Socket::MODE_CLIENT);

		$buck = new Truman_Buck('usleep', array(200));
		$desk = new Truman_Desk($desk_opts);
		$client = new Truman_Socket($server_addr, $socket_opts);

		$client->send(serialize($buck));

		$desk->start(function(Truman_Result $result) use ($buck, $test) {
			$test->assertInstanceOf('stdClass', $data = $result->data());
			$test->assertObjectHasAttribute('retval', $data);
			$test->assertEquals($buck->invoke(), $data->retval);
			return false;
		});

	}

	public function testSignal() {
		$results = array();
		$desk    = new Truman_Desk();
		$signal  = new Truman_Signal();
		$desk->enqueueBuck($signal);
		$desk->start(function(Truman_Result $result) use (&$results) {
			$results[] = $result;
		});
		$this->assertEmpty($results);
	}

}