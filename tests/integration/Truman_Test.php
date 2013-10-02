<? require_once dirname(dirname(__DIR__)) . '/autoload.php';

use truman\core\Client;
use truman\core\Notification;
use truman\core\Util;
use truman\Truman;
use truman\test\integration\DeskCallbackAccumulator;

class Truman_Test extends PHPUnit_Framework_TestCase {

	public function testClient() {
		$this->assertEquals(
			Truman::setClient(null)->getSignature(),
			Truman::getClient()->getSignature()
		);
		Truman::getClient()->close();
	}

	public function testServer() {
		$port = 12345;
		$accumulator = new DeskCallbackAccumulator();
		$options = $accumulator->optionsExpectedResults(2);
		Truman::setDesk($port, $options);
		Truman::setClient($port);
		$buck = Truman::enqueue('usleep', [100]);
		Truman::listen();
		$results = $accumulator->getResults();
		foreach ($results as $result) {
			$test = $result->getBuck();
			if ($test instanceof Notification) continue;
			else $this->assertEquals($buck, $test);
		}
		Truman::getDesk()->close();
	}

}