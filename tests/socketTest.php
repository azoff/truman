<? require_once dirname(__DIR__) . '/autoload.php';

class TrumanSocket_Test extends PHPUnit_Framework_TestCase {

	public function testSendReceive() {
		$message = 'hello world!';
		$server = new TrumanSocket(array('port' => 12345));
		$client = new TrumanSocket(array(
			'port'       => 12345,
			'force_mode' => TrumanSocket::MODE_CLIENT
		));
		$this->assertEquals(strlen($message), $client->send($message));
		$this->assertEquals($message, $server->receive());
	}

	public function testSendBuck() {
		$buck = new TrumanBuck();
		$server = new TrumanSocket(array('port' => 12345));
		$client = new TrumanSocket(array(
			'port'       => 12345,
			'force_mode' => TrumanSocket::MODE_CLIENT
		));
		$this->assertTrue($client->sendBuck($buck));
		$this->assertEquals(serialize($buck), $received = $server->receive());
		$receivedBuck = unserialize($received);
		$this->assertEquals($buck, $receivedBuck);
	}

	public function testCallback() {
		$server = new TrumanSocket(array('port' => 12345));
		$client = new TrumanSocket(array(
			'port'       => 12345,
			'force_mode' => TrumanSocket::MODE_CLIENT
		));
		$client->send('hello');
		$result = $server->receive(function($message) {
			return "{$message} world";
		});
		$this->assertEquals('hello world', $result);
	}

}