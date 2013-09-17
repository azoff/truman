<? require_once dirname(dirname(__DIR__)) . '/autoload.php';

use truman\Buck;
use truman\Channel;

class Hash_Test extends PHPUnit_Framework_TestCase {

	public function testHash() {
		$buckA = new Buck();
		$buckB = new Buck();
		$hash = new Channel('test', ['a', 'b', 'c']);
		$this->assertEquals(
			$hash->getTarget($buckA),
			$hash->getTarget($buckB)
		);
	}

}