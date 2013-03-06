<? require_once dirname(__DIR__) . '/autoload.php';

class Truman_Desk_Test extends PHPUnit_Framework_TestCase {

	public function testBuck() {
		$buck = new Truman_Buck('max', array(1, 2));
		$desk = new Truman_Desk();
		$desk->processBuck($buck);
		$result = $desk->waitForResult();
		$this->assertObjectHasAttribute('buck', $data = $result->data());
		$this->assertEquals($buck, $data->buck);
	}

	public function testRetval() {
		$buck = new Truman_Buck('strlen', array('test'));
		$desk = new Truman_Desk();
		$desk->processBuck($buck);
		$result = $desk->waitForResult();
		$this->assertObjectHasAttribute('retval', $data = $result->data());
		$this->assertEquals($buck->invoke(), $data->retval);
	}

	public function testException() {
		$buck = new Truman_Buck('Truman_Exception::throwNew', array('test', 'test'));
		$desk = new Truman_Desk();
		$desk->processBuck($buck);
		$result = $desk->waitForResult();
		$this->assertObjectHasAttribute('exception', $data = $result->data());
		$this->assertInstanceOf('Truman_Exception', $data->exception);
	}

	public function testError() {
		$buck = new Truman_Buck('fopen');
		$desk = new Truman_Desk();
		$desk->processBuck($buck);
		$result = $desk->waitForResult();
		$this->assertObjectHasAttribute('error', $data = $result->data());
		$this->assertEquals(2, $data->error['type']);
	}

	public function testOutput() {
		$buck = new Truman_Buck('phpinfo');
		$desk = new Truman_Desk();
		$desk->processBuck($buck);
		$result = $desk->waitForResult();
		$this->assertObjectHasAttribute('output', $data = $result->data());
		$this->assertContains('phpinfo()', $data->output);
	}

	public function testInbound() {
		$server_addr = '0.0.0.0:12345';
		$buck = new Truman_Buck('usleep', array(200));
		$desk = new Truman_Desk(array('inbound' => '0.0.0.0:12345'));
		$client = new Truman_Socket($server_addr, array(
			'force_mode' => Truman_Socket::MODE_CLIENT
		));
		$client->send(serialize($buck));
		$desk->waitForData();
		$this->assertEquals(1, $desk->countWaiting());
		$desk->processNextBuck();
		$this->assertEquals(0, $desk->countWaiting());
		$this->assertEquals(1, $desk->countRunning());
		$result = $desk->waitForResult();
		$this->assertObjectHasAttribute('buck', $data = $result->data());
		$this->assertEquals($buck->invoke(), $data->retval);
	}

}