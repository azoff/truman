<? require_once dirname(__DIR__) . '/autoload.php';

class TrumanHash_Test extends PHPUnit_Framework_TestCase {

	public function testHash() {
		$buckA = new TrumanBuck();
		$buckB = new TrumanBuck();
		$hash = new TrumanChannel(array('a', 'b', 'c'));
		$this->assertEquals(
			$hash->getTarget($buckA),
			$hash->getTarget($buckB)
		);
	}

}