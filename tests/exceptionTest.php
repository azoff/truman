<? require_once dirname(__DIR__) . '/autoload.php';

class Truman_Exception_Test extends PHPUnit_Framework_TestCase {
	
	public function testThrowNew() {
		$error = null;
		try {
			Truman_Exception::throwNew($this, 'test');
		} catch(Truman_Exception $ex) {
			$error = $ex;
		}
		$this->assertInstanceOf('Truman_Exception', $error);
	}

}