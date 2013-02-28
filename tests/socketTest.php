<? require_once dirname(__DIR__) . '/autoload.php';

class Truman_Socket_Test extends PHPUnit_Framework_TestCase {

	public function test_send() {
		$socket = new Truman_Socket('0.0.0.0:22', array(
			'force_mode' => Truman_Socket::MODE_CLIENT
		));
		$this->assertEquals(
			strlen($msg = 'foobars'),
			$socket->send($msg)
		);
	}

	public function test_client_mode() {
		$socket = new Truman_Socket('0.0.0.0:22', array(
			'force_mode' => Truman_Socket::MODE_CLIENT
		));
		$socket->send('What are you?');
		$socket->receive(function($header){
			$this->assertRegExp('#SSH#', $header);
		});
	}

	public function test_server_mode() {
		$server = new Truman_Socket('0.0.0.0:12345');
		$client = new Truman_Socket('0.0.0.0:12345', array(
			'force_mode' => Truman_Socket::MODE_CLIENT
		));
		$client->send('wait for it...');
		$client->send('wait for it...');
		$client->send('wait for it...');
		$client->send('wait for it...');
		$client->send('go!');
		$server->listen(function($msg){
			return $msg !== 'go!';
		});
	}

}