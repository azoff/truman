<? require_once dirname(__DIR__) . '/autoload.php';

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

}