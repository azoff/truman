<? require_once dirname(dirname(__DIR__)) . '/autoload.php';

use truman\Client;
use truman\Buck;
use truman\Desk;
use truman\DeskAccumulator;

class Client_Test extends PHPUnit_Framework_TestCase {

	public function testSignature() {
		$options = ['desk_notification_timeout' => -1];
		$clientA = new Client([], $options);
		$clientB = new Client($port = 12345, $options);
		$this->assertNotEmpty($sig = $clientA->getSignature());
		$clientA->addDeskSpec($port, -1);
		$this->assertNotEquals($sig, $clientA->getSignature());
		$this->assertEquals($clientB->getDeskSpecs(), $clientA->getDeskSpecs());
	}

	public function testNotifyDesks() {

		$accumulator = new DeskAccumulator();
		$desk = new Desk($port = 12345, $accumulator->optionsExpectedBucksIn());

		// create an "outdated" client by not notifying desks
		$spec = ["127.0.0.1:{$port}", "localhost:{$port}"];
		$clientA = new Client($spec, ['desk_notification_timeout' => -1]);
		$clientA->updateInternals();

		// new clients should auto notify any connected desks
		$clientB = new Client("127.0.0.1:{$port}");
		$desk->start();
		$this->assertEquals($clientB->getSignature(), $desk->getClient()->getSignature());

		// outdated clients shouldn't override newer clients
		$clientA->notifyDesks();
		$desk->start();
		$this->assertNotEquals($clientA->getSignature(), $desk->getClient()->getSignature());

		// existing client updates should be reflected
		$spec['host'] = '127.0.0.1';
		$clientB->addDeskSpec("localhost:{$port}");
		$desk->start();
		$this->assertEquals(
			$clientB->getDeskCount(),
			$desk->getClient()->getDeskCount()
		);

		$desk->__destruct();

	}

	public function testChannels() {

		$specs[] = array(
			'port' => 12346,
			'channels' => ['channelA', 'channelB']
		);

		$specs[] = array(
			'port' => 12347,
			'channels' => ['channelC']
		);

		$bucks[] = new Buck('foo', array(), array(
			'channel' => $specs[0]['channels'][0]
		));

		$bucks[] = new Buck('bar', array(), array(
			'channel' => $specs[0]['channels'][1]
		));

		$bucks[] = new Buck('poo', array(), array(
			'channel' => $specs[1]['channels'][0]
		));

		foreach ($specs as $spec)
			$desks[] = new Desk($spec);

		$client = new Client($specs, ['desk_notification_timeout' => -1]);

		foreach ($bucks as $buck)
			$client->sendBuck($buck);

		foreach ($desks as $i => $desk) {
			$actual = 0;
			$expected = count($specs[$i]['channels']);
			while ($desk->receiveBuck()) $actual++;
			$this->assertEquals($expected, $actual);
			$desk->__destruct();
		}

	}

	public function testBuckReRouting() {

		// get all network addresses
		$port = 12345;

		// add the first interface twice, to test caching
		$interfaces = ['localhost', '127.0.0.1'];

		// build specifications
		$specs = array();
		foreach ($interfaces as $i => $interface)
			$specs[] = array(
				'host' => $interface,
				'port' => $port,
				'channels' => "channel_{$i}"
			);

		// start the desk
		$expected = count($specs);
		$accumulator = new DeskAccumulator();
		$options = $accumulator->optionsExpectedResults($expected);
		$desk = new Desk($port, $options);

		// start the client
		$client = new Client($specs);

		// send a buck to each interface
		foreach ($specs as $spec) {
			$options = ['channel' => $spec['channels']];
			$buck = new Buck('gethostname', [$spec['host']], $options);
			$client->sendBuck($buck);
		}

		$desk->start();

		$desk->__destruct();

	}

}