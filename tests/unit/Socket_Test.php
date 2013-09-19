<? require_once dirname(dirname(__DIR__)) . '/autoload.php';

use truman\Buck;
use truman\Socket;
use truman\Util;

class Socket_Test extends PHPUnit_Framework_TestCase {

	public function testSendReceive() {
		$message = 'hello world!';
		$server = new Socket(12345);
		$client = new Socket(12345, ['force_client_mode' => 1]);
		$this->assertTrue($client->send($message));
		$this->assertEquals($message, $server->receive());
	}

	public function testSendReceiveObject() {
		$buck = new Buck();
		$server = new Socket(12345);
		$client = new Socket(12345, ['force_client_mode' => 1]);
		$this->assertTrue($client->send($buck));
		$this->assertEquals($buck, $server->receive());
	}

}