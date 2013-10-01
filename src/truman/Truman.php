<? namespace truman;

use truman\core\Buck;
use truman\core\Client;
use truman\core\Desk;

/**
 * Class Truman A convenience wrapper over the core Truman classes.
 * Allows for easy enqueuing and consumption of Bucks.
 * @package truman
 */
class Truman {

	private static $client = null;
	private static $desk   = null;

	/**
	 * Sets the static Client to be used to enqueue Bucks
	 * @param int|string|array $desk_specs The list of sockets specifications to send Bucks to. Valid values
	 * include anything that can be passed into Socket::__construct(), or a list of such values.
	 * @param array $options Optional settings for the Client. See Client::$_DEFAULT_OPTIONS
	 * @return Client The static client
	 */
	public static function setClient($desk_specs = Desk::DEFAULT_PORT, array $options = []) {
		return self::$client = new Client($desk_specs, $options);
	}

	/**
	 * Gets the static Client to be used to enqueue Bucks
	 * @return Client The static Client
	 */
	public static function getClient() {
		return isset(self::$client) ? self::$client : self::setClient();
	}

	/**
	 * Sets the static Desk to be used to process Bucks
	 * @param int|string|array $inbound_socket_spec The socket specification to receive Bucks over the network. Can be
	 * anything accepted in Socket::__construct()
	 * @param array $options Optional settings for the Desk. See Desk::$_DEFAULT_OPTIONS
	 * @return Desk The static Desk
	 */
	public static function setDesk($inbound_socket_spec = Desk::DEFAULT_PORT, array $options = []) {
		return self::$desk = new Desk($inbound_socket_spec, $options);
	}

	/**
	 * Gets the static Desk to be used to process Bucks
	 * @return Desk The static Desk
	 */
	public static function getDesk() {
		return isset(self::$desk) ? self::$desk : self::setDesk();
	}

	/**
	 * Creates a Buck and sends it to a Desk for processing
	 * @param callable|string $callable The remote method to execute
	 * @param array $args Optional args to pass to the method. Can be a keyed array for named arguments.
	 * @param array $options Optional settings for the Buck. See Buck::$_DEFAULT_OPTIONS
	 * @return null|Buck The created Buck if the Client was able to send, otherwise null is returned.
	 */
	public static function enqueue($callable = Buck::CALLABLE_NOOP, array $args = [], array $options = []) {
		$buck = new Buck($callable, $args, $options);
		return self::getClient()->sendBuck($buck);
	}

	/**
	 * Starts the Desk listening for inbound Bucks over the network
	 * @param int $timeout The amount of time to wait, in between cycles, for Bucks to come in
	 * @return int 0 if the Desk stops correctly, other values indicate error
	 */
	public static function listen($timeout = 0) {
		return self::getDesk()->start($timeout);
	}

}