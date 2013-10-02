<? namespace truman\test\integration;
require_once dirname(dirname(__DIR__)) . '/autoload.php';

use truman\core\Desk;
use truman\core\Notification;
use truman\core\Util;
use truman\core\Drawer;
use truman\core\Buck;

class Drawer_Test extends \PHPUnit_Framework_TestCase {

	public function testConstruct() {
		$include = Util::tempPhpFile('function foobs(){}');
		$drawer = new Drawer([$include]);
		$this->assertTrue(function_exists('foobs'));
		unset($drawer);
	}

	public function testExecute() {
		$context = 'test';
		$include = Util::tempPhpFile('function subContext() { return (new truman\core\Buck)->getContext(); }');
		$buck    = new Buck('subContext', [], [Buck::OPTION_CONTEXT => $context]);
		$drawer  = new Drawer([$include]);
		$drawer->setBuck($buck);
		$drawer->execute();
		$this->assertNotNull($result = $drawer->getResult());
		$this->assertEquals($context, $result->getRetval());
	}

	public function testTick() {

		// $test => $buck => fifo => $drawer($buck) => $result => $fifo => $test

		$killer = new Notification(Notification::TYPE_DRAWER_SIGNAL, 0);
		$path   = Util::tempFifo();
		$drawer = new Drawer([], [
			Drawer::OPTION_STREAM_INPUT  => $read  = fopen($path, 'r'),
			Drawer::OPTION_STREAM_OUTPUT => $write = fopen($path, 'w')
		]);
		Util::writeObjectToStream($killer, $write);
		$this->assertEquals(0, $drawer->tick());
		$result = Util::readObjectFromStream($read);
		$this->assertInstanceOf('truman\core\Result', $result);
		$this->assertEquals($killer, $result->getBuck());
		fclose($read);
		fclose($write);
		unlink($path);

	}

	public function testPoll() {
		$killer = new Notification(Notification::TYPE_DRAWER_SIGNAL, 0);
		$path   = Util::tempFifo();
		$drawer = new Drawer([], [
			Drawer::OPTION_STREAM_INPUT  => $read  = fopen($path, 'r'),
			Drawer::OPTION_STREAM_OUTPUT => $write = fopen($path, 'w')
		]);
		Util::writeObjectToStream($killer, $write);
		$this->assertEquals(0, $drawer->poll());
		$result = Util::readObjectFromStream($read);
		$this->assertInstanceOf('truman\core\Result', $result);
		$this->assertEquals($killer, $result->getBuck());
		fclose($read);
		fclose($write);
		unlink($path);
	}

}