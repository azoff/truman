<? require_once dirname(__DIR__) . '/autoload.php';

use truman\Util;
use truman\Drawer;
use truman\Buck;

class Drawer_Test extends PHPUnit_Framework_TestCase {


	private static function getThreadContext() {
		$buck = new Buck();
		return $buck->getContext();
	}

	public function testConstruct() {
		$include = Util::tempPhpFile('function foo(){}');
		$drawer = new Drawer([$include]);
		$this->assertTrue(function_exists('foo'));
		unset($drawer);
	}

	public function testExecute() {
		$context = 'test';
		$buck    = new Buck(['self', 'getThreadContext'], [], ['context' => $context]);
		$drawer  = new Drawer();
		$result  = $drawer->execute($buck);
		$this->assertInstanceOf('stdClass', $data = $result->data());
		$this->assertObjectHasAttribute('result', $data);
		$this->assertEquals($context, $data->result);
	}

	public function poll(array $inputs) {

	}

	private function error($code, $message) {

	}

	private function tick(array $inputs) {

	}



	public static function main(array $argv, $input) {

	}

}