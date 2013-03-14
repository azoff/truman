<? require_once dirname(__DIR__) . '/autoload.php';

class TrumanClient_Test extends PHPUnit_Framework_TestCase {

	public function testSignature() {
		$clientA = new TrumanClient(array(), 0);
		$clientB = new TrumanClient($spec = array('port' => 12345), false);
		$this->assertNotEmpty($sig = $clientA->getSignature());
		$clientA->addDeskSpec($spec, false);
		$this->assertNotEquals($sig, $clientA->getSignature());
		$this->assertEquals($clientB->getDeskSpecs(), $clientA->getDeskSpecs());
	}

	public function testNotifyDesks() {

		$desk = new TrumanDesk(array('buck_port' => 12345));

		// create an "outdated" client by not notifying desks
		$spec = array('127.0.0.1:12345', 'localhost:12345');
		$clientA = new TrumanClient($spec, false);
		$clientA->updateInternals();

		// new clients should auto notify any connected desks
		$clientB = new TrumanClient('127.0.0.1:12345');
		while($desk->tick());
		$this->assertEquals($clientB->getSignature(), $desk->getClient()->getSignature());

		// outdated clients shouldn't override newer clients
		$clientA->notifyDesks();
		while($desk->tick());
		$this->assertNotEquals($clientA->getSignature(), $desk->getClient()->getSignature());

		// existing client updates should be reflected
		$spec['host'] = '127.0.0.1';
		$clientB->addDeskSpec('localhost:12345');
		while($desk->tick());
		$this->assertEquals(
			$clientB->getDeskCount(),
			$desk->getClient()->getDeskCount()
		);

	}

	public function testChannels() {

		$specs[] = array(
			'port' => 12346,
			'channels' => array('channelA', 'channelB')
		);

		$specs[] = array(
			'port' => 12347,
			'channels' => array('channelC')
		);

		$bucks[] = new TrumanBuck('foo', array(), array(
			'channel' => $specs[0]['channels'][0]
		));

		$bucks[] = new TrumanBuck('bar', array(), array(
			'channel' => $specs[0]['channels'][1]
		));

		$bucks[] = new TrumanBuck('poo', array(), array(
			'channel' => $specs[1]['channels'][0]
		));

		foreach ($specs as $spec)
			$desks[] = new TrumanDesk(array('buck_port' => $spec['port']));

		$client = new TrumanClient($specs);

		foreach ($bucks as $buck)
			$client->sendBuck($buck);

		foreach ($desks as $i => $desk) {
			$expected = count($specs[$i]['channels']);
			do if (!is_null($desk->receiveBuck()))
				$expected--;
			while($expected > 0);
		}

	}

}