<? require_once dirname(__DIR__) . '/autoload.php';

use truman\Util;
use truman\Drawer;

class Drawer_Test extends PHPUnit_Framework_TestCase {


	function testConstruct() {
		$include = Util::tempPhpFile('function foo(){}');
		$drawer = new Drawer([$include]);
		$this->assertTrue(function_exists('foo'));
		unset($drawer);
	}

	public function poll(array $inputs) {

	}

	private function error($code, $message) {

	}

	private function tick(array $inputs) {

	}

	private function execute(Buck $buck) {

	}

	public static function main(array $argv, $input) {

	}

}