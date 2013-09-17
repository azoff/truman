<? namespace truman;

class Client {

	const TIMEOUT_DEFAULT = 5;

	private $sockets;
	private $options;
	private $channels;
	private $desk_specs;
	private $dirty;
	private $notified;
	private $timestamp;
	private $signature;

	private static $_DEFAULT_OPTIONS = [
		'log_sends' => true,
		'desk_notification_timeout' => 0
	];

	public function __construct($desk_specs = null, array $options = []) {
		$this->sockets    = [];
		$this->channels   = [];
		$this->desk_specs = [];
		$this->options = $options + self::$_DEFAULT_OPTIONS;
		$desk_notification_timeout = $this->options['desk_notification_timeout'];
		if (is_array($desk_specs) && !Util::isKeyedArray($desk_specs))
			$this->addDeskSpecs($desk_specs, $desk_notification_timeout);
		else if (!is_null($desk_specs))
			$this->addDeskSpec($desk_specs, $desk_notification_timeout);
	}

	public function __toString() {
		$count = $this->getDeskCount();
		$sig = substr($this->getSignature(), -22);
		return "Client<{$sig}>[{$count}]";
	}

	function __destruct() {
		foreach ($this->sockets as &$socket) {
			$socket->__destruct();
			unset($socket);
		}
		$this->sockets = [];
	}

	public function addDeskSpec($desk_spec = null, $desk_notification_timeout = 0) {
		if (is_int($desk_spec))
			$desk_spec = ['port' => $desk_spec];
		if (is_string($desk_spec))
			$desk_spec = parse_url($desk_spec);
		if (is_null($desk_spec) || !is_array($desk_spec))
			Exception::throwNew($this, 'desc_spec must be an int, string, or array');
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
		$channel_name = $buck->getChannel();
		$channel      = $this->channels[$channel_name];
		$target       = $channel->getTarget($buck);
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

	public function newNotificationBuck() {
		$signature = $this->getSignature();
		return new Buck(Buck::CALLABLE_NOOP, [$signature], array(
			'client_signature' => $signature,
			'priority'         => Buck::PRIORITY_URGENT
		));
	}

	public function notifyDesks($timeout = 0) {
		if ($timeout < 0) return;
		if (isset($this->notified) && !$this->notified) {
			$buck = $this->newNotificationBuck();
			$message = serialize($buck);
			$expected = strlen($message);
			foreach ($this->desk_specs as $target => $desk_spec) {
				$socket = $this->createOrGetSocket($target, $desk_spec);
				if ($expected !== $socket->send($message, null, $timeout))
					Exception::throwNew($this, "unable to notify {$socket} about new client signature");
			}
			$this->notified = true;
		}
	}

	public function sendBuck(Buck $buck, $timeout = 0) {
		$socket = $this->getDeskSocket($buck);
		if (!$socket->sendBuck($buck, null, $timeout))
			Exception::throwNew($this, "Unable to send {$buck} to {$socket}");
		else if ($this->options['log_sends'])
			error_log("{$this} sent {$buck} to {$socket}");
		return $buck;
	}

	public function updateInternals($timestamp = 0) {
		if (!isset($this->dirty) || $this->dirty) {
			$this->dirty = false;
			$this->timestamp = $timestamp > 0 ? $timestamp : microtime(1);
			$this->signature = self::toSignature($this);
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