<? require_once dirname(__DIR__) . '/autoload.php';

class TrumanException_Test extends PHPUnit_Framework_TestCase {
	
	public function testThrowNew() {
		$error = null;
		try {
			TrumanException::throwNew($this, 'test');
		} catch(TrumanException $ex) {
			$error = $ex;
		}
		$this->assertInstanceOf('TrumanException', $error);
	}

}