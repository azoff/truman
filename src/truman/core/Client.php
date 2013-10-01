<? namespace truman\core;

use truman\interfaces\LoggerContext;

/**
 * Class Client Sends Bucks to Desks
 * @package truman\core
 */
class Client implements \JsonSerializable, LoggerContext {

	const LOGGER_TYPE = 'CLIENT';

	/**
	 * Occurs when a Client is first instantiated
	 */
	const LOGGER_EVENT_INIT = 'INIT';

	/**
	 * Occurs when a Client starts to notify connected Desks about the network
	 */
	const LOGGER_EVENT_NOTIFY_START    = 'NOTIFY_START';

	/**
	 * Occurs when a Client fails to notify connected Desks about the network
	 */
	const LOGGER_EVENT_NOTIFY_ERROR    = 'NOTIFY_ERROR';

	/**
	 * Occurs when a Client completes notification of connected Desks
	 */
	const LOGGER_EVENT_NOTIFY_COMPLETE = 'NOTIFY_COMPLETE';

	/**
	 * Options to pass to this Client's internal Logger
	 */
	const OPTION_LOGGER_OPTS = 'logger_options';

	/**
	 * Optionally forces the Client's timestamp to some arbitrary time
	 */
	const OPTION_TIMESTAMP = 'timestamp';

	/**
	 * The amount of time to wait until a Desk Socket is ready to receive notifications. Use -1 to avoid notification.
	 */
	const OPTION_DESK_NOTIFICATION_TIMEOUT = 'desk_notification_timeout';

	private $logger;
	private $sockets;
	private $channels;
	private $desk_specs;
	private $dirty;
	private $notified;
	private $timestamp;
	private $signature;

	private static $_DEFAULT_OPTIONS = [
		self::OPTION_LOGGER_OPTS               => [],
		self::OPTION_DESK_NOTIFICATION_TIMEOUT => 0,
		self::OPTION_TIMESTAMP                 => 0
	];

	/**
	 * Creates a new Client instance
	 * @param int|string|array $desk_specs The list of sockets specifications to send Bucks to. Valid values
	 * include anything that can be passed into Socket::__construct(), or a list of such values.
	 * @param array $options Optional settings for the Client. See Client::$_DEFAULT_OPTIONS
	 */
	public function __construct($desk_specs = null, array $options = []) {
		$this->sockets    = [];
		$this->channels   = [];
		$this->desk_specs = [];
		$options += self::$_DEFAULT_OPTIONS;
		if (is_array($desk_specs) && !Util::isKeyedArray($desk_specs))
			$this->addDeskSpecs($desk_specs, -1);
		else if (!is_null($desk_specs))
			$this->addDeskSpec($desk_specs, -1);
		$this->logger = new Logger($this, $options[self::OPTION_LOGGER_OPTS]);
		$this->notifyDesks($options[self::OPTION_DESK_NOTIFICATION_TIMEOUT]);
		$this->updateInternals($options[self::OPTION_TIMESTAMP]);
	}

	/**
	 * @inheritdoc
	 */
	public function __toString() {
		$count = $this->getDeskCount();
		$id    = $this->getLoggerId();
		return "Client<{$id}>[{$count}]";
	}

	/**
	 * @inheritdoc
	 */
	public function jsonSerialize() {
		return $this->__toString();
	}

	/**
	 * @inheritdoc
	 */
	public function getLogger() {
		return $this->logger;
	}

	/**
	 * @inheritdoc
	 */
	public function getLoggerType() {
		return self::LOGGER_TYPE;
	}

	/**
	 * @inheritdoc
	 */
	public function getLoggerId() {
		return md5($this->getSignature());
	}

	/**
	 * Closes all of this Client's open Socket connections
	 */
	public function close() {
		foreach ($this->sockets as &$socket) {
			$socket->close();
			unset($socket);
		}
		$this->sockets = [];
	}

	/**
	 * Destroys this Client by closing all Socket connections
	 */
	public function __destruct() {
		$this->close();
	}

	/**
	 * @param int|string|array $desk_spec A Desk Socket specification. Can be any value accepted by Socket::__construct()
	 * @param int $desk_notification_timeout The amount of time to wait until Desks can receive notifications. Use -1 to
	 * disable automatic Desk notification.
	 * @throws Exception If the Desk
	 */
	public function addDeskSpec($desk_spec, $desk_notification_timeout = 0) {

		$desk_spec = Util::normalizeSocketSpec($desk_spec, Desk::DEFAULT_HOST, Desk::DEFAULT_PORT);

		if (!isset($desk_spec[Socket::SPEC_CHANNELS]))
			$desk_spec[Socket::SPEC_CHANNELS] = [Buck::CHANNEL_DEFAULT];

		if (!is_array($channels = $desk_spec[Socket::SPEC_CHANNELS]))
			$channels = [$channels];

		$schannels = serialize($desk_spec[Socket::SPEC_CHANNELS]);
		$target = "{$desk_spec[Socket::SPEC_HOST]}:{$desk_spec[Socket::SPEC_PORT]}::{$schannels}";
		$this->desk_specs[$target] = $desk_spec;

		foreach ($channels as $channel) {
			if (!isset($this->channels[$channel]))
				$this->channels[$channel] = new Channel($channel, $target);
			else
				$this->channels[$channel]->addTarget($target);
		}

		$this->dirty    = true;
		$this->notified = false;
		$this->notifyDesks($desk_notification_timeout);
	}

	/**
	 * @param array $desk_specs A list Desk Socket specification. Each element in the list can be any value accepted by
	 * Socket::__construct()
	 * @param int $desk_notification_timeout The amount of time to wait until Desks can receive notifications. Use -1 to
	 * disable automatic Desk notification.
	 */
	public function addDeskSpecs(array $desk_specs, $desk_notification_timeout = 0) {
		foreach ($desk_specs as $desk_spec)
			$this->addDeskSpec($desk_spec, -1);
		$this->notifyDesks($desk_notification_timeout);
	}

	/**
	 * Gets the number of Desks defined by specifications in this Client
	 * @return int
	 */
	public function getDeskCount() {
		return count($this->desk_specs);
	}

	/**
	 * Gets the list of Desk Socket specifications used by this Client
	 * @return array
	 */
	public function getDeskSpecs() {
		return array_values($this->desk_specs);
	}

	/**
	 * Gets the unique signature for this Client. The signature can be used to recreate this Client
	 * @return string
	 */
	public function getSignature() {
		$this->updateInternals();
		return $this->signature;
	}

	/**
	 * Uses an internal Channel to determine which Desk Socket specification a Buck maps to
	 * @param Buck $buck The Buck to pair with a Desk
	 * @return array The Socket specification for the paired Desk
	 * @throws Exception If unable to find a matching Channel for the Buck
	 */
	public function getDeskSpec(Buck $buck) {

		if (!isset($this->channels[$channel_name = $buck->getChannel()]))
			throw new Exception('Unable to find definition for channel', [
				'channel' => $channel_name,
				'context' => $this,
				'method'  => __METHOD__,
				'buck'    => $buck
			]);

		$channel = $this->channels[$channel_name];
		$target  = $channel->getTarget($buck);
		return $this->desk_specs[$target];
	}

	/**
	 * Like getDeskSpec, but gets the Desk Socket using the specification
	 * @param Buck $buck The Buck to pair with a Desk Socket
	 * @return Socket The Desk Socket to send the Buck to
	 */
	public function getDeskSocket(Buck $buck) {
		$channel_name = $buck->getChannel();
		$channel      = $this->channels[$channel_name];
		$target       = $channel->getTarget($buck);
		$desk_spec    = $this->desk_specs[$target];
		return $this->createOrGetSocket($target, $desk_spec);
	}

	/**
	 * Gets the timestamp of the last time this Client was updated
	 * @return int Seconds between the Unix epoch and the last update time
	 */
	public function getTimestamp() {
		$this->updateInternals();
		return $this->timestamp;
	}

	/**
	 * Attempts to notify all connected Desks about this client
	 * @param int $timeout The amount of time to wait until a Desk is ready to receive the notification. Use -1 to
	 * disable the notification system.
	 * @throws Exception If unable to notify one or more connected Desks about this Client
	 */
	public function notifyDesks($timeout = 0) {
		if ($timeout < 0) return;
		if (isset($this->notified) && !$this->notified) {
			$signature    = $this->getSignature();
			$notification = new Notification(Notification::TYPE_CLIENT_UPDATE, $signature);
			$this->logger->log(self::LOGGER_EVENT_NOTIFY_START, $signature);
			foreach ($this->desk_specs as $target => $desk_spec) {
				$socket = $this->createOrGetSocket($target, $desk_spec);
				if (!$this->sendBuck($notification, $timeout, $socket)) {
					$this->logger->log(self::LOGGER_EVENT_NOTIFY_ERROR, $socket->getHostAndPort());
					throw new Exception('Unable to notify socket about new client signature', [
						'context' => $this,
						'socket'  => $socket,
						'method'  => __METHOD__
					]);
				}
			}
			$this->logger->log(self::LOGGER_EVENT_NOTIFY_COMPLETE);
			$this->notified = true;
		}
	}

	/**
	 * Sends a Buck to a Desk Socket chosen by this Client
	 * @param Buck $buck The Buck to send
	 * @param int $timeout The time to wait until a Desk Socket is ready to receive
	 * @param Socket $destination Overrides the Client algorithm and forces delivery to this Socket
	 * @return null|Buck The sent Buck if successful, otherwise null
	 */
	public function sendBuck(Buck $buck, $timeout = 0, Socket $destination = null) {
		$socket = $destination ?: $this->getDeskSocket($buck);
		$url = $socket->getHostAndPort();
		$buck->getLogger()->log(Buck::LOGGER_EVENT_SEND_START, $url);
		if ($socket->send($buck, null, $timeout)) {
			$buck->getLogger()->log(Buck::LOGGER_EVENT_SEND_COMPLETE, $url);
			return $buck;
		} else {
			$buck->getLogger()->log(Buck::LOGGER_EVENT_SEND_ERROR, $url);
			return null;
		}
	}

	/**
	 * A human-readable array of Desks Socket specifications, along with the last updated timestamp
	 * @return array
	 */
	public function getTopography() {
		$desks = [];
		foreach ($this->desk_specs as $spec)
			$desks[] = "{$spec[Socket::SPEC_HOST]}:{$spec[Socket::SPEC_PORT]}";
		return ['desks' => $desks, 'timestamp' => $this->timestamp];
	}

	/**
	 * Updates the timestamp and signature for this Client
	 * @param int $timestamp Forces a timestamp time other than the current.
	 */
	public function updateInternals($timestamp = 0) {
		if (!isset($this->dirty) || $this->dirty) {
			$this->dirty = false;
			$this->timestamp = number_format($timestamp > 0 ? $timestamp : microtime(1), 4, '.', '');
			$this->signature = self::toSignature($this);
			$this->logger->log(self::LOGGER_EVENT_INIT, $this->getTopography());
		}
	}

	/**
	 * Creates a Desk Socket given a specification
	 * @param string $target A cache key to use so that we don't create duplicate Sockets
	 * @param int|string|array $desk_spec A Desk Socket specification. Can be any value accepted by Socket::__construct()
	 * @return Socket The Socket used to send Bucks to a Desk
	 */
	public function createOrGetSocket($target, $desk_spec) {
		if (!isset($this->sockets[$target])) {
			$required = [Socket::OPTION_FORCE_CLIENT_MODE => 1];
			$this->sockets[$target] = new Socket($desk_spec, $required);
		}
		return $this->sockets[$target];
	}

	/**
	 * Converts a client into a string signature
	 * @param Client $client The client to convert into a signature
	 * @return string The client signature
	 */
	public static function toSignature(Client $client) {
		$serialized = serialize($client->getDeskSpecs());
		$payload    = base64_encode($serialized);
		return "{$payload}@{$client->timestamp}";
	}

	/**
	 * Converts a Client signature into a Client instance
	 * @param string $signature The signature to convert into a Client
	 * @return Client|null The converted Client
	 */
	public static function fromSignature($signature) {
		list($payload, $timestamp) = explode('@', $signature, 2);
		$specs = unserialize(base64_decode($payload));
		$client = new Client($specs, [
			self::OPTION_DESK_NOTIFICATION_TIMEOUT => -1,
			self::OPTION_TIMESTAMP => $timestamp
		]);
		return $client;
	}

}