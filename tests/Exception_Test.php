<? require_once dirname(__DIR__) . '/autoload.php';

use truman\Buck;
use truman\Exception;

class Exception_Test extends PHPUnit_Framework_TestCase {
	
	public function testThrowNew() {
		$error = null;
		try {
			Exception::throwNew(new Buck, 'test');
		} catch(Exception $ex) {
			$error = $ex;
		}
		$this->assertInstanceOf('Exception', $error);
	}

}