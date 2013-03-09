<? require_once dirname(__DIR__) . '/autoload.php';

class Truman_Hash_Test extends PHPUnit_Framework_TestCase {

	public function testHash() {
		$buckA = new Truman_Buck();
		$buckB = new Truman_Buck();
		$hash = new Truman_Channel(array('a', 'b', 'c'));
		$this->assertEquals(
			$hash->getTarget($buckA),
			$hash->getTarget($buckB)
		);
	}

}