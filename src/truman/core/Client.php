<? namespace truman\core;

use truman\interfaces\LoggerContext;

class Client implements \JsonSerializable, LoggerContext {

	const TIMEOUT_DEFAULT = 5;

	const LOGGER_TYPE                  = 'CLIENT';
	const LOGGER_EVENT_INIT            = 'INIT';
	const LOGGER_EVENT_NOTIFY_START    = 'NOTIFY_START';
	const LOGGER_EVENT_NOTIFY_ERROR    = 'NOTIFY_ERROR';
	const LOGGER_EVENT_NOTIFY_COMPLETE = 'NOTIFY_COMPLETE';

	private $logger;
	private $sockets;
	private $options;
	private $channels;
	private $desk_specs;
	private $dirty;
	private $notified;
	private $timestamp;
	private $signature;

	private static $_DEFAULT_OPTIONS = [
		'logger_options' => [],
		'desk_notification_timeout' => 0
	];

	public function __construct($desk_specs = null, array $options = []) {
		$this->sockets    = [];
		$this->channels   = [];
		$this->desk_specs = [];
		$this->options = $options + self::$_DEFAULT_OPTIONS;
		if (is_array($desk_specs) && !Util::isKeyedArray($desk_specs))
			$this->addDeskSpecs($desk_specs, -1);
		else if (!is_null($desk_specs))
			$this->addDeskSpec($desk_specs, -1);
		$this->logger = new Logger($this, $this->options['logger_options']);
		$this->notifyDesks($this->options['desk_notification_timeout']);
	}

	public function __toString() {
		$count = $this->getDeskCount();
		$id    = $this->getLoggerId();
		return "Client<{$id}>[{$count}]";
	}

	public function jsonSerialize() {
		return $this->__toString();
	}

	public function getLogger() {
		return $this->logger;
	}

	public function getLoggerType() {
		return self::LOGGER_TYPE;
	}

	public function getLoggerId() {
		return md5($this->getSignature());
	}

	function __destruct() {
		foreach ($this->sockets as &$socket) {
			$socket->close();
			unset($socket);
		}
		$this->sockets = [];
	}

	public function addDeskSpec($desk_spec = null, $desk_notification_timeout = 0) {
		if (is_numeric($desk_spec))
			$desk_spec = ['port' => $desk_spec];
		if (is_string($desk_spec))
			$desk_spec = parse_url($desk_spec);
		if (is_null($desk_spec) || !is_array($desk_spec))
			throw new Exception('Desk spec must be an int, string, or array', [
				'context'   => $this,
				'desk_spec' => $desk_spec,
				'method'    => __METHOD__
			]);
		if (!isset($desk_spec['host']))
			$desk_spec['host'] = '127.0.0.1';
		if (!isset($desk_spec['channels']))
			$desk_spec['channels'] = [Buck::CHANNEL_DEFAULT];
		if (!is_array($channels = $desk_spec['channels']))
			$channels = [$channels];

		$schannels = serialize($desk_spec['channels']);
		$target = "{$desk_spec['host']}:{$desk_spec['port']}::{$schannels}";
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

	public function addDeskSpecs(array $desk_specs, $desk_notification_timeout = 0) {
		foreach ($desk_specs as $desk_spec)
			$this->addDeskSpec($desk_spec, -1);
		$this->notifyDesks($desk_notification_timeout);
	}

	public function getDeskCount() {
		return count($this->desk_specs);
	}

	public function getDeskSpecs() {
		return array_values($this->desk_specs);
	}

	public function getSignature() {
		$this->updateInternals();
		return $this->signature;
	}

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

	public function getDeskSocket(Buck $buck) {
		$channel_name = $buck->getChannel();
		$channel      = $this->channels[$channel_name];
		$target       = $channel->getTarget($buck);
		$desk_spec    = $this->desk_specs[$target];
		return $this->createOrGetSocket($target, $desk_spec);
	}

	public function getTimestamp() {
		$this->updateInternals();
		return $this->timestamp;
	}

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

	public function sendBuck(Buck $buck, $timeout = 0, Socket $destination = null) {
		$socket = $destination ?: $this->getDeskSocket($buck);
		$url = $socket->getHostAndPort();
		$buck->getLogger()->log(Buck::LOGGER_EVENT_SEND_START, $url);
		if ($socket->send($buck, null, $timeout)) {
			$buck->getLogger()->log(Buck::LOGGER_EVENT_SEND_COMPLETE, $url);
			return $this;
		} else {
			$buck->getLogger()->log(Buck::LOGGER_EVENT_SEND_ERROR, $url);
			return null;
		}
	}

	public function getTopography() {
		$desks = [];
		foreach ($this->desk_specs as $spec)
			$desks[] = "{$spec['host']}:{$spec['port']}";
		return ['desks' => $desks, 'timestamp' => $this->timestamp];
	}

	public function updateInternals($timestamp = 0) {
		if (!isset($this->dirty) || $this->dirty) {
			$this->dirty = false;
			$this->timestamp = number_format($timestamp > 0 ? $timestamp : microtime(1), 4, '.', '');
			$this->signature = self::toSignature($this);
			$this->logger->log(self::LOGGER_EVENT_INIT, $this->getTopography());
		}
	}

	public function createOrGetSocket($target, $desk_spec) {
		if (!isset($this->sockets[$target])) {
			$required = ['force_client_mode' => 1];
			$this->sockets[$target] = new Socket($required + $desk_spec);
		}
		return $this->sockets[$target];
	}

	public static function toSignature(Client $client) {
		$serialized = serialize($client->getDeskSpecs());
		$payload    = base64_encode($serialized);
		return "{$payload}@{$client->timestamp}";
	}

	public static function fromSignature($signature) {
		list($payload, $timestamp) = explode('@', $signature, 2);
		$specs = unserialize(base64_decode($payload));
		$client = new Client($specs, ['desk_notification_timeout' => -1]);
		$client->updateInternals($timestamp);
		return $client;
	}

}