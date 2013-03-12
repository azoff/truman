<? require_once dirname(__DIR__) . '/autoload.php';

class TrumanClient_Test extends PHPUnit_Framework_TestCase {

	public function testSignature() {
		$clientA = new TrumanClient(array(), false);
		$clientB = new TrumanClient($spec = array('port' => 12345), false);
		$this->assertNotEmpty($sig = $clientA->getSignature());
		$clientA->addDeskSpec($spec, false);
		$this->assertNotEquals($sig, $clientA->getSignature());
		$this->assertEquals($clientB->getDeskSpecs(), $clientA->getDeskSpecs());
	}

	public function testNotifyDesks() {

		$stopAfterResult = function(){ return false; };
		$desk = new TrumanDesk(array('buck_port' => 12345));

		// create an "outdated" client by not notifying desks
		$spec = array('127.0.0.1:12345', 'localhost:12345');
		$clientA = new TrumanClient($spec, false);
		$clientA->updateInternals();

		// new clients should auto notify any connected desks
		$clientB = new TrumanClient('127.0.0.1:12345');
		$desk->start($stopAfterResult);
		$this->assertEquals($clientB->getSignature(), $desk->getClient()->getSignature());

		// outdated clients shouldn't override newer clients
		$clientA->notifyDesks();
		$desk->start($stopAfterResult);
		$this->assertNotEquals($clientA->getSignature(), $desk->getClient()->getSignature());

		// existing client updates should be reflected
		$spec['host'] = '127.0.0.1';
		$clientB->addDeskSpec('localhost:12345');
		$desk->start($stopAfterResult);
		$this->assertEquals(
			$clientB->getDeskCount(),
			$desk->getClient()->getDeskCount()
		);

	}

}