<? require_once dirname(__DIR__) . '/autoload.php';

class Truman_Desk_Test extends PHPUnit_Framework_TestCase {

	public function test_buck() {
		$buck = new Truman_Buck('max', array(1, 2));
		$desk = new Truman_Desk();
		$desk->write($buck);
		$result = $desk->poll();
		$this->assertObjectHasAttribute('buck', $data = $result->data());
		$this->assertEquals($buck, $data->buck);
	}

	public function test_retval() {
		$buck = new Truman_Buck('strlen', array('test'));
		$desk = new Truman_Desk();
		$desk->write($buck);
		$result = $desk->poll();
		$this->assertObjectHasAttribute('retval', $data = $result->data());
		$this->assertEquals($buck->invoke(), $data->retval);
	}

	public function test_exception() {
		$buck = new Truman_Buck('Truman_Exception::throwNew', array('test', 'test'));
		$desk = new Truman_Desk();
		$desk->write($buck);
		$result = $desk->poll();
		$this->assertObjectHasAttribute('exception', $data = $result->data());
		$this->assertInstanceOf('Truman_Exception', $data->exception);
	}

	public function test_error() {
		$buck = new Truman_Buck('fopen');
		$desk = new Truman_Desk();
		$desk->write($buck);
		$result = $desk->poll();
		$this->assertObjectHasAttribute('error', $data = $result->data());
		$this->assertEquals(2, $data->error['type']);
	}

	public function test_output() {
		$buck = new Truman_Buck('phpinfo');
		$desk = new Truman_Desk();
		$desk->write($buck);
		$result = $desk->poll();
		$this->assertObjectHasAttribute('output', $data = $result->data());
		$this->assertContains('phpinfo()', $data->output);
	}

}