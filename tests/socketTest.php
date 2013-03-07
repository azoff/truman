<? require_once dirname(__DIR__) . '/autoload.php';

class Truman_Socket_Test extends PHPUnit_Framework_TestCase {

	public function testSend() {
		$socket = new Truman_Socket('0.0.0.0:22', array(
			'force_mode' => Truman_Socket::MODE_CLIENT
		));
		$this->assertEquals(
			strlen($msg = 'foobars'),
			$socket->send($msg)
		);
	}

	public function testClientMode() {
		$test = $this;
		$socket = new Truman_Socket('0.0.0.0:22', array(
			'force_mode' => Truman_Socket::MODE_CLIENT
		));
		$socket->send('What are you?');
		$socket->receive(function($header) use (&$test) {
			$test->assertRegExp('#SSH#', $header);
		});
	}

	public function testServerMode() {

		$server = new Truman_Socket('0.0.0.0:12345');
		$client = new Truman_Socket('0.0.0.0:12345', array(
			'force_mode' => Truman_Socket::MODE_CLIENT
		));

		$ticks = 5;
		$tocks = 0;

		$client->send('1');
		$client->send('1');
		$client->send('1');
		$client->send('1');
		$client->send('1');

		do $server->receive(function($msg) use (&$ticks, &$tocks) {
			$tocks += intval($msg);
			$ticks--;
		});

		while ($ticks > 0);

		$this->assertEquals(5, $tocks);

	}

}