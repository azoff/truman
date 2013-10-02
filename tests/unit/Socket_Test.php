<? namespace truman\test\unit;
require_once dirname(dirname(__DIR__)) . '/autoload.php';

use truman\core\Buck;
use truman\core\Socket;
use truman\core\Util;

class Socket_Test extends \PHPUnit_Framework_TestCase {

	public function testSendReceive() {
		$message = 'hello world!';
		$server = new Socket(12345);
		$client = new Socket(12345, [Socket::OPTION_FORCE_CLIENT_MODE => 1]);
		$this->assertTrue($client->send($message));
		$this->assertEquals($message, $server->receive());
		$client->close();
		$server->close();
	}

	public function testSendReceiveObject() {
		$timer = (object)['time' => time()];
		$server = new Socket(12345);
		$client = new Socket(12345, [Socket::OPTION_FORCE_CLIENT_MODE => 1]);
		$this->assertTrue($client->send($timer));
		$this->assertEquals($timer, $server->receive());
		$client->close();
		$server->close();
	}

}