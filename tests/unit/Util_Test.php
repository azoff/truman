<? require_once dirname(dirname(__DIR__)) . '/autoload.php';

use truman\Util;

class Util_Test extends PHPUnit_Framework_TestCase {

	public function testIsLocalHost() {
		$this->assertTrue(Util::isLocalAddress('::1'));
		$this->assertTrue(Util::isLocalAddress('127.0.0.1'));
		$this->assertTrue(Util::isLocalAddress('127.0.0.1'));
		$this->assertTrue(Util::isLocalAddress('localhost'));
		$this->assertTrue(Util::isLocalAddress(gethostname()));
		$this->assertFalse(Util::isLocalAddress('foobar.com'));
	}

	public function testTempFilePath() {
		$path = Util::tempFilePath('prefix_', 'test', '/tmp');
		$this->assertTrue(is_readable($path));
		$this->assertTrue(is_writable($path));
		$this->assertEquals($path, strstr($path, '/tmp/'));
		$this->assertEquals($path, strstr($path, '/tmp/prefix_'));
		$this->assertEquals('.test', strstr($path, '.test'));
	}

	public function testTempPhpFile() {
		$path = Util::tempPhpFile('function foo(){}');
		include_once $path;
		$this->assertTrue(function_exists('foo'));
	}

	public function testTempFifo() {
		$path       = Util::tempFifo();
		$read       = fopen($path, 'r');
		$write      = fopen($path, 'w');
		$object     = Util::writeObjectToStream(new stdClass(), $write);
		$this->assertEquals($object, Util::readObjectFromStream($read));
	}

	public function testIsKeyedArray() {
		$this->assertTrue(Util::isKeyedArray(['test' => 1]));
		$this->assertFalse(Util::isKeyedArray([]));
		$this->assertFalse(Util::isKeyedArray([1]));
	}

}