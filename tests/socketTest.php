<? require_once dirname(__DIR__) . '/autoload.php';

class TrumanSocket_Test extends PHPUnit_Framework_TestCase {

	public function testSendReceive() {
		$message = 'hello world!';
		$server = new TrumanSocket(12345);
		$client = new TrumanSocket(12345, array('force_client_mode' => 1));
		$this->assertEquals(strlen($message), $client->send($message));
		$this->assertEquals($message, $server->receive());
	}

	public function testSendBuck() {
		$buck = new TrumanBuck();
		$server = new TrumanSocket(12345);
		$client = new TrumanSocket(12345, array('force_client_mode' => 1));
		$this->assertTrue($client->sendBuck($buck));
		$this->assertEquals(serialize($buck), $received = $server->receive());
		$receivedBuck = unserialize($received);
		$this->assertEquals($buck, $receivedBuck);
	}

	public function testCallback() {
		$server = new TrumanSocket(12345);
		$client = new TrumanSocket(12345, array('force_client_mode' => 1));
		$client->send('hello');
		$result = $server->receive(function($message) {
			return "{$message} world";
		});
		$this->assertEquals('hello world', $result);
	}

}