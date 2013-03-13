<?

class TrumanClient {

	const TIMEOUT_DEFAULT = 5;

	private static $sockets = array();
	private static $local_ip;
	private static $public_ip;

	private $channels;
	private $desk_specs;
	private $dirty;
	private $notified;
	private $timestamp;
	private $signature;

	public function __construct($desk_specs = array(), $notify_desks = true) {
		$this->channels = array();
		$this->desk_specs = array();
		if (is_array($desk_specs) && !TrumanUtil::isKeyedArray($desk_specs))
			$this->addDeskSpecs($desk_specs, $notify_desks);
		else
			$this->addDeskSpec($desk_specs, $notify_desks);
	}

	public function __toString() {
		$count = $this->getDeskCount();
		$sig = $this->getSignature();
		return __CLASS__."<{$sig}>[{$count}]";
	}

	public function addDeskSpec($desk_spec = null, $notify_desks = true) {
		if (is_null($desk_spec))
			TrumanException::throwNew($this, 'null desc_spec is not allowed');
		if (!is_array($desk_spec))
			$desk_spec = parse_url($desk_spec);
		if (!isset($desk_spec['host']))
			$desk_spec['host'] = '127.0.0.1';
		if (!isset($desk_spec['channels']))
			$desk_spec['channels'] = array(TrumanBuck::CHANNEL_DEFAULT);
		if (!is_array($channels = $desk_spec['channels']))
			$channels = array($channels);

		$target = "{$desk_spec['host']}:{$desk_spec['port']}";
		$this->desk_specs[$target] = $desk_spec;

		foreach ($channels as $channel) {
			if (!isset($this->channels[$channel]))
				$this->channels[$channel] = new TrumanChannel($channel, $target);
			else
				$this->channels[$channel]->addTarget($target);
		}

		$this->dirty    = true;
		$this->notified = false;

		if ($notify_desks)
			$this->notifyDesks();
	}

	public function addDeskSpecs(array $desk_specs, $notify_desks = true) {
		foreach ($desk_specs as $desk_spec)
			$this->addDeskSpec($desk_spec, false);
		if ($notify_desks)
			$this->notifyDesks();
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

	public function getSocket(TrumanBuck $buck) {
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

	public function isLocalTarget(TrumanBuck $buck) {
		$socket = $this->getSocket($buck);
		$desk_spec = $socket->getHostSpec();
		if (TrumanSocket::isLocal($desk_spec))
			return true;
		$ip_address = $desk_spec['host'];
		if ($ip_address === self::localIpAddress())
			return true;
		if ($ip_address === self::publicIpAddress())
			return true;
		return false;
	}

	public function newNotificationBuck() {
		return new TrumanBuck(TrumanBuck::CALLABLE_NOOP, array(), array(
			'client_signature' => $this->getSignature(),
			'priority'         => TrumanBuck::PRIORITY_URGENT
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
					TrumanException::throwNew($this, "unable to notify {$socket} about new client signature");
			}
			$this->notified = true;
		}
	}

	public function sendBuck(TrumanBuck $buck, $timeout = 0) {
		if (!$this->notified)
			$this->notifyDesks();

		$socket = $this->getSocket($buck);
		if (!$socket->sendBuck($buck, null, $timeout))
			TrumanException::throwNew($this, "Unable to send {$buck} to {$socket}");

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
			self::$sockets[$target] = new TrumanSocket(array(
				'force_mode' => TrumanSocket::MODE_CLIENT
			) + $desk_spec);
		return self::$sockets[$target];
	}

	public static function toSignature(TrumanClient $client) {
		$serialized = serialize($client->getDeskSpecs());
		$payload    = base64_encode($serialized);
		return "{$payload}@{$client->timestamp}";
	}

	public static function fromSignature($signature) {
		list($payload, $timestamp) = explode('@', $signature, 2);
		$specs = @unserialize(base64_decode($payload));
		$client = new TrumanClient($specs, false);
		$client->updateInternals($timestamp);
		return $client;
	}

	public static function localIpAddress($interface = 'eth0') {
		if (!isset(self::$local_ip)) {
			// TODO: this sucks.
			$status = shell_exec("ifconfig {$interface}");
			if (preg_match("#inet addr:(.+?)\s#", $status, $matches))
				self::$local_ip = $matches[1];
		}
		return self::$local_ip;
	}

	public static function publicIpAddress() {
		if (!isset(self::$public_ip))
			// TODO: this sucks worse.
			self::$public_ip = file_get_contents('http://icanhazip.com/');
		return self::$public_ip;
	}

}