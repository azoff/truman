<? require_once dirname(dirname(__DIR__)) . '/autoload.php';

use truman\core\Client;
use truman\Truman;
use truman\test\integration\DeskCallbackAccumulator;

class Truman_Test extends PHPUnit_Framework_TestCase {

	public function testClient() {
		$this->assertEquals(
			Truman::setClient(null)->getSignature(),
			Truman::getClient()->getSignature()
		);
	}

	public function testServer() {
		$port = 12345;
		$accumulator = new DeskCallbackAccumulator();
		$options = $accumulator->optionsExpectedResults(2);
		$desk = Truman::listen($port, $options, false);
		Truman::setClient($port);
		$buck = Truman::enqueue('usleep', [100]);
		$desk->start();
		$results = $accumulator->getResults();
		$data = $results[1]->data();
		$this->assertEquals($buck, $data->buck);
	}

}