<? require_once dirname(__DIR__) . '/autoload.php';

class TrumanUtil_Test extends PHPUnit_Framework_TestCase {

	public function testIsLocalHost() {
		$this->assertTrue(TrumanUtil::isLocalAddress('::1'));
		$this->assertTrue(TrumanUtil::isLocalAddress('127.0.0.1'));
		$this->assertTrue(TrumanUtil::isLocalAddress('127.0.0.1'));
		$this->assertTrue(TrumanUtil::isLocalAddress('localhost'));
		$this->assertTrue(TrumanUtil::isLocalAddress(gethostname()));
		$this->assertFalse(TrumanUtil::isLocalAddress('foobar.com'));
	}

}