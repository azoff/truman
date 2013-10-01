<? require_once dirname(dirname(__DIR__)) . '/autoload.php';

use truman\core\Desk;
use truman\core\Notification;
use truman\core\Util;
use truman\core\Drawer;
use truman\core\Buck;

function getThreadContext() {
	$buck = new Buck();
	return $buck->getContext();
}

class Drawer_Test extends PHPUnit_Framework_TestCase {

	public function testConstruct() {
		$include = Util::tempPhpFile('function foobs(){}');
		$drawer = new Drawer([$include]);
		$this->assertTrue(function_exists('foobs'));
		unset($drawer);
	}

	public function testExecute() {
		$context = 'test';
		$buck    = new Buck('getThreadContext', [], [Buck::OPTION_CONTEXT => $context]);
		$drawer  = new Drawer();
		$result  = $drawer->execute($buck);
		$this->assertInstanceOf('stdClass', $data = $result->getData());
		$this->assertObjectHasAttribute('retval', $data);
		$this->assertEquals($context, $data->retval);
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
		$this->assertInstanceOf('stdClass', $data = $result->getData());
		$this->assertObjectHasAttribute('buck', $data);
		$this->assertEquals($killer, $data->buck);
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
		$this->assertInstanceOf('stdClass', $data = $result->getData());
		$this->assertObjectHasAttribute('buck', $data);
		$this->assertEquals($killer, $data->buck);
		fclose($read);
		fclose($write);
		unlink($path);
	}

}