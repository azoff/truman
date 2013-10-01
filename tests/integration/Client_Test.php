<? require_once dirname(dirname(__DIR__)) . '/autoload.php';

use truman\core\Client;
use truman\core\Buck;
use truman\core\Desk;
use truman\core\Socket;
use truman\test\integration\DeskCallbackAccumulator;

class Client_Test extends PHPUnit_Framework_TestCase {

	public function testSignature() {
		$options = [Client::OPTION_DESK_NOTIFICATION_TIMEOUT => -1];
		$clientA = new Client([], $options);
		$clientB = new Client($port = 12345, $options);
		$this->assertNotEmpty($sig = $clientA->getSignature());
		$clientA->addDeskSpec($port, -1);
		$this->assertNotEquals($sig, $clientA->getSignature());
		$this->assertEquals($clientB->getDeskSpecs(), $clientA->getDeskSpecs());
		$clientA->close();
		$clientB->close();
	}

	public function testNotifyDesks() {

		$accumulator = new DeskCallbackAccumulator();
		$desk = new Desk($port = Desk::DEFAULT_PORT, $accumulator->optionsExpectedBucksIn());

		// create an "outdated" client by not notifying desks
		$spec = ["127.0.0.1:{$port}", "localhost:{$port}"];
		$clientA = new Client($spec, [Client::OPTION_DESK_NOTIFICATION_TIMEOUT => -1]);
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
		$spec[Socket::SPEC_HOST] = '127.0.0.1';
		$clientB->addDeskSpec("localhost:{$port}");
		$desk->start();
		$this->assertEquals(
			$clientB->getDeskCount(),
			$desk->getClient()->getDeskCount()
		);

		$clientA->close();
		$clientB->close();
		$desk->close();

	}

	public function testChannels() {

		$specs[] = array(
			Socket::SPEC_PORT     => 12346,
			Socket::SPEC_CHANNELS => ['channelA', 'channelB']
		);

		$specs[] = array(
			Socket::SPEC_PORT     => 12347,
			Socket::SPEC_CHANNELS => ['channelC']
		);

		$bucks[] = new Buck('foo', [], [
			Buck::OPTION_CHANNEL => $specs[0][Socket::SPEC_CHANNELS][0]
		]);

		$bucks[] = new Buck('bar', [], [
			Buck::OPTION_CHANNEL => $specs[0][Socket::SPEC_CHANNELS][1]
		]);

		$bucks[] = new Buck('poo', [], [
			Buck::OPTION_CHANNEL => $specs[1][Socket::SPEC_CHANNELS][0]
		]);

		$desks = [];
		foreach ($specs as $spec)
			$desks[] = new Desk($spec);

		$client = new Client($specs, [Client::OPTION_DESK_NOTIFICATION_TIMEOUT => -1]);

		foreach ($bucks as $buck)
			$client->sendBuck($buck);

		foreach ($desks as $i => $desk) {
			$actual = 0;
			$expected = count($specs[$i][Socket::SPEC_CHANNELS]);
			while ($desk->receiveBuck()) $actual++;
			$this->assertEquals($expected, $actual);
			$desk->close();
		}

		$client->close();

	}

	public function testBuckReRouting() {

		// all traffic will be sent to port 12345

		// create a client/server for 127.0.0.1
		$desk   = new Desk(12345);
		$buck   = new Buck('gethostname');
		$client = new Client('127.0.0.1:12345');
		$client->sendBuck($buck);

		// the desk should receive a client notification and the job we made
		while ($desk->receiveBuck());
		$this->assertEquals(2, $desk->getQueueSize());

		// now, have the desk process the client notification
		// so that it knows about the network topography
		$this->assertNull($desk->getClient());
		$this->assertNotNull($notification = $desk->processBuck());
		$this->assertNotEquals($notification->getUUID(), $buck->getUUID());
		$this->assertNotNull($old_client = $desk->getClient());
		$this->assertEquals($client->getTopography(), $old_client->getTopography());

		// change the network topography by using the intranet IP.
		// this will implicitly send another client update to the desk
		$intranet_ip = exec('ifconfig eth0| grep \'inet addr:\' | cut -d: -f2 | awk \'{ print $1}\'');
		$client = new Client("{$intranet_ip}:12345");
		while ($desk->receiveBuck());
		$this->assertNotNull($notification = $desk->processBuck());
		$this->assertNotEquals($notification->getUUID(), $buck->getUUID());
		$this->assertNotNull($new_client = $desk->getClient());
		$this->assertNotEquals($old_client->getTopography(), $new_client->getTopography());
		$this->assertEquals($client->getTopography(), $new_client->getTopography());

		// now process the buck, it should be rerouted and dequeued
		$this->assertNotNull($routed = $desk->processBuck());
		$this->assertEquals($routed->getUUID(), $buck->getUUID());

		// now receive and process the buck over the localhost interface
		while ($desk->receiveBuck());
		$this->assertGreaterThan(0, $desk->getQueueSize());
		$this->assertEquals($buck->getUUID(), $desk->processBuck()->getUUID());
		$this->assertLessThan(1, $desk->getQueueSize());

		$client->close();
		$desk->close();

	}

}