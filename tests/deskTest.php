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
		$buck = new Truman_Buck('phpcredits');
		$desk = new Truman_Desk();
		$desk->enqueueBuck($buck);
		$desk->start(function(Truman_Result $result) use ($buck, $test) {
			$test->assertInstanceOf('stdClass', $data = $result->data());
			$test->assertObjectHasAttribute('output', $data = $result->data());
			$test->assertContains('PHP Credits', $data->output);
			return false;
		});
	}

	public function testBuckSocket() {
		$test        = $this;
		$desk_opts   = array('buck_port' => 12345);
		$socket_opts = array('port' => 12345, 'force_mode' => Truman_Socket::MODE_CLIENT);

		$buck = new Truman_Buck('usleep', array(200));
		$desk = new Truman_Desk($desk_opts);
		$client = new Truman_Socket($socket_opts);

		$this->assertTrue($client->sendBuck($buck));

		$desk->start(function(Truman_Result $result) use ($buck, $test) {
			$test->assertInstanceOf('stdClass', $data = $result->data());
			$test->assertObjectHasAttribute('retval', $data);
			$test->assertEquals($buck->invoke(), $data->retval);
			return false;
		});
	}

	public function testStop() {
		$desk    = new Truman_Desk();
		$desk->enqueueBuck(new Truman_Buck('phpcredits'));
		$desk->start(function(Truman_Result $result, Truman_Desk $desk) {
			$desk->stop();
		});
	}

}