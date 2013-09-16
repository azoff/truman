<? require_once dirname(__DIR__) . '/autoload.php';

use truman\Client;
use truman\Buck;
use truman\Desk;

class Client_Test extends PHPUnit_Framework_TestCase {

	public function testSignature() {
		$clientA = new Client([], 0);
		$clientB = new Client($port = 12345, false);
		$this->assertNotEmpty($sig = $clientA->getSignature());
		$clientA->addDeskSpec($port, false);
		$this->assertNotEquals($sig, $clientA->getSignature());
		$this->assertEquals($clientB->getDeskSpecs(), $clientA->getDeskSpecs());
	}

	public function testNotifyDesks() {

		$desk = new Desk($port = 12345);

		// create an "outdated" client by not notifying desks
		$spec = ["127.0.0.1:{$port}", "localhost:{$port}"];
		$clientA = new Client($spec, false);
		$clientA->updateInternals();

		// new clients should auto notify any connected desks
		$clientB = new Client("127.0.0.1:{$port}");
		while($desk->tick());
		$this->assertEquals($clientB->getSignature(), $desk->getClient()->getSignature());

		// outdated clients shouldn't override newer clients
		$clientA->notifyDesks();
		while($desk->tick());
		$this->assertNotEquals($clientA->getSignature(), $desk->getClient()->getSignature());

		// existing client updates should be reflected
		$spec['host'] = '127.0.0.1';
		$clientB->addDeskSpec("localhost:{$port}");
		while($desk->tick());
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

		$client = new Client($specs, false);

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
		preg_match_all("#inet addr:\s*([^\s/]+)#", shell_exec('ifconfig'), $interfaces);

		$port = 12345;

		// add the first interface twice, to test caching
		array_unshift($interfaces[1], $interfaces[1][0]);

		// build specifications
		$specs = array();
		foreach ($interfaces[1] as $i => $interface)
			$specs[] = array(
				'host' => $interface,
				'port' => $port,
				'channels' => "channel_{$i}"
			);

		// listening on all interfaces
		$desk = new Desk($port);

		// explicitly enumerates all interfaces
		$client = new Client($specs);

		// send a buck to each interface
		foreach ($specs as $spec) {
			$options = ['channel' => $spec['channels']];
			$buck = new Buck('gethostname', [$spec['host']], $options);
			$client->sendBuck($buck);
		}

		// capture all the results
		$results = array();
		$expected = count($specs);
		$desk->start(function($desk, $in, $out, $result) use (&$results, $expected) {
			if (!is_null($result))
				$results[] = $result;
			if (count($results) >= $expected)
				$desk->stop();
		});

		$desk->__destruct();

	}

}