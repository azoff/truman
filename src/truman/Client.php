<? namespace truman;

class Client {

	const TIMEOUT_DEFAULT = 5;

	private static $sockets = array();

	private $channels;
	private $desk_specs;
	private $dirty;
	private $notified;
	private $timestamp;
	private $signature;

	public function __construct($desk_specs = [], $notify_desks = 1) {
		$this->channels = array();
		$this->desk_specs = array();
		if (is_array($desk_specs) && !Util::isKeyedArray($desk_specs))
			$this->addDeskSpecs($desk_specs, $notify_desks);
		else
			$this->addDeskSpec($desk_specs, $notify_desks);
	}

	public function __toString() {
		$count = $this->getDeskCount();
		$sig = substr($this->getSignature(), 24);
		return "Client<{$sig}>[{$count}]";
	}

	public function addDeskSpec($desk_spec = null, $notify_desks = 1) {
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

		if ($notify_desks > 0)
			$this->notifyDesks($notify_desks);
	}

	public function addDeskSpecs(array $desk_specs, $notify_desks = 1) {
		foreach ($desk_specs as $desk_spec)
			$this->addDeskSpec($desk_spec, 0);
		if ($notify_desks > 0)
			$this->notifyDesks($notify_desks);
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
		return self::createOrGetSocket($target, $desk_spec);
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

	public function notifyDesks($timeout = 5) {
		if (isset($this->notified) && !$this->notified) {
			$buck = $this->newNotificationBuck();
			$message = serialize($buck);
			$expected = strlen($message);
			foreach ($this->desk_specs as $target => $desk_spec) {
				$socket = self::createOrGetSocket($target, $desk_spec);
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
		return $buck;
	}

	public function updateInternals($timestamp = 0) {
		if (!isset($this->dirty) || $this->dirty) {
			$this->dirty = false;
			$this->timestamp = $timestamp > 0 ? $timestamp : microtime(1);
			$this->signature = self::toSignature($this);
		}
	}

	public static function createOrGetSocket($target, $desk_spec) {
		if (!isset(self::$sockets[$target]))
			self::$sockets[$target] = new Socket(array(
				'force_client_mode' => 1
			) + $desk_spec);
		return self::$sockets[$target];
	}

	public static function toSignature(Client $client) {
		$serialized = serialize($client->getDeskSpecs());
		$payload    = base64_encode($serialized);
		return "{$payload}@{$client->timestamp}";
	}

	public static function fromSignature($signature) {
		list($payload, $timestamp) = explode('@', $signature, 2);
		$specs = unserialize(base64_decode($payload));
		$client = new Client($specs, false);
		$client->updateInternals($timestamp);
		return $client;
	}

}