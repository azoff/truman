<? require_once dirname(__DIR__) . '/autoload.php';

class Truman_Socket_Test extends PHPUnit_Framework_TestCase {

	public function testSendReceive() {
		$message = 'hello world!';
		$server = new Truman_Socket(array('port' => 12345));
		$client = new Truman_Socket(array(
			'port'       => 12345,
			'force_mode' => Truman_Socket::MODE_CLIENT
		));
		$this->assertEquals(strlen($message), $client->send($message, null, 5));
		$this->assertTrue($server->receive()); // accept the connection
		$this->assertEquals($message, $server->receive()); // receive the value
	}

	public function testSendBuck() {
		$buck = new Truman_Buck();
		$server = new Truman_Socket(array('port' => 12345));
		$client = new Truman_Socket(array(
			'port'       => 12345,
			'force_mode' => Truman_Socket::MODE_CLIENT
		));
		$this->assertTrue($client->sendBuck($buck, null, 5));
		$this->assertTrue($server->receive()); // accept the connection
		$received = unserialize($server->receive()); // receive the value
		$this->assertEquals($buck, $received); // compare the unserialized buck
	}

	public function testCallback() {

		$server = new Truman_Socket(array('port' => 12345));
		$client = new Truman_Socket(array(
			'port'       => 12345,
			'force_mode' => Truman_Socket::MODE_CLIENT
		));

		$client->send($sent = 'hello world!');

		while ($server->receive(function($message) use (&$received) {
			$received = $message;
			return false;
		}));

		$this->assertEquals($sent, $received);
	}

}