<? namespace truman\test\integration;
require_once dirname(dirname(__DIR__)) . '/autoload.php';

use truman\core\Client;
use truman\core\Notification;
use truman\core\Util;
use truman\Truman;

class Truman_Test extends \PHPUnit_Framework_TestCase {

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
		$options = $accumulator->optionsExpectedResults(1);
		Truman::setDesk($port, $options);
		Truman::setClient($port);
		$buck = Truman::enqueue('usleep', [100]);
		Truman::listen();
		$result = $accumulator->getResultFirst();
		$this->assertEquals($buck, $result->getBuck());
		Truman::getDesk()->close();
	}

}