<? namespace truman;

use truman\core\Buck;
use truman\core\Client;
use truman\core\Desk;

class Truman {

	const DEFAULT_SPEC = 12345;

	private static $client = null;

	public static function setClient($desk_specs = self::DEFAULT_SPEC, array $options = []) {
		return self::$client = new Client($desk_specs, $options);
	}

	public static function getClient() {
		return isset(self::$client) ? self::$client : self::setClient();
	}

	public static function enqueue($callable = Buck::CALLABLE_NOOP, array $args = [], array $options = []) {
		$buck = new Buck($callable, $args, $options);
		return self::getClient()->sendBuck($buck);
	}

	public static function listen($inbound_socket_spec = self::DEFAULT_SPEC, array $options = []) {
		$desk = new Desk($inbound_socket_spec, $options);
		$desk->start();
	}

}