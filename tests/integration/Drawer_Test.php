<? require_once dirname(dirname(__DIR__)) . '/autoload.php';

use truman\Desk;
use truman\Util;
use truman\Drawer;
use truman\Buck;

function getThreadContext() {
	$buck = new Buck();
	return $buck->getContext();
}

function killDrawer() {
	return Drawer::KILLCODE;
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
		$buck    = new Buck('getThreadContext', [], ['context' => $context]);
		$drawer  = new Drawer();
		$result  = $drawer->execute($buck);
		$this->assertInstanceOf('stdClass', $data = $result->data());
		$this->assertObjectHasAttribute('retval', $data);
		$this->assertEquals($context, $data->retval);
	}

	public function testTick() {

		// $test => $buck => fifo => $drawer($buck) => $result => $fifo => $test

		$buck   = new Buck('killDrawer');
		$path   = Util::tempFifo();
		$drawer = new Drawer([], [
			'stream_input'  => $read  = fopen($path, 'r'),
			'stream_output' => $write = fopen($path, 'w')
		]);
		Util::sendPhpObjectToStream($buck, $write);
		$this->assertEquals(0, $drawer->tick());
		$this->assertNotEmpty($serialized = stream_get_contents($read));
		$this->assertInstanceOf('truman\Result', $result = unserialize($serialized));
		$this->assertInstanceOf('stdClass', $data = $result->data());
		$this->assertObjectHasAttribute('buck', $data);
		$this->assertEquals($buck, $data->buck);
		fclose($read);
		fclose($write);
		unlink($path);

	}

	public function testPoll() {
		$buck   = new Buck('killDrawer');
		$path   = Util::tempFifo();
		$drawer = new Drawer([], [
			'stream_input'  => $read  = fopen($path, 'r'),
			'stream_output' => $write = fopen($path, 'w')
		]);
		Util::sendPhpObjectToStream($buck, $write);
		$this->assertEquals(0, $drawer->poll());
		$this->assertNotEmpty($serialized = stream_get_contents($read));
		$this->assertInstanceOf('truman\Result', $result = unserialize($serialized));
		$this->assertInstanceOf('stdClass', $data = $result->data());
		$this->assertObjectHasAttribute('buck', $data);
		$this->assertEquals($buck, $data->buck);
		fclose($read);
		fclose($write);
		unlink($path);
	}

	public function testMain() {
		$buck   = new Buck('killDrawer');
		$path   = Util::tempFifo();
		$read  = fopen($path, 'r');
		$write = fopen($path, 'w');
		Util::sendPhpObjectToStream($buck, $write);
		$this->assertEquals(0, Drawer::main([], [
			'stream_input'  => $read,
			'stream_output' => $write
		]));
		$this->assertNotEmpty($serialized = stream_get_contents($read));
		$this->assertInstanceOf('truman\Result', $result = unserialize($serialized));
		$this->assertInstanceOf('stdClass', $data = $result->data());
		$this->assertObjectHasAttribute('buck', $data);
		$this->assertEquals($buck, $data->buck);
		fclose($read);
		fclose($write);
		unlink($path);
	}

}