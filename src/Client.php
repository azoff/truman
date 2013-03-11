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

	public function __construct($desk_specs) {
		$this->channels = array();
		$this->desk_specs = array();
		if (is_array($desk_specs))
			$this->addDeskSpecs($desk_specs);
		else
			$this->addDeskSpec($desk_specs);
	}

	public function addDeskSpec($desk_spec, $notify_desks = true) {
		if (!is_array($desk_spec))
			$desk_spec = parse_url($desk_spec);
		if (!isset($desk_spec['channels']))
			$desk_spec['channels'] = array(TrumanBuck::CHANNEL_DEFAULT);
		if (!is_array($channels = $desk_spec['channels']))
			$channels = array($channels);

		$target = "{$desk_spec['host']}:{$desk_spec['port']}";
		$this->desk_specs[$target] = $desk_spec;

		foreach ($channels as $channel) {
			if (!isset($this->channels[$channel]))
				$this->channels[$channel] = new TrumanChannel($target);
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

	public function notifyDesks() {
		$buck = new TrumanBuck(TrumanBuck::CALLABLE_NOOP, array(
			'client_signature' => $this->getSignature(),
			'priority'         => TrumanBuck::PRIORITY_URGENT
		));
		$message = serialize($buck);
		$expected = strlen($message);
		foreach ($this->desk_specs as $target => $desk_spec) {
			$socket = self::createOrGetSocket($target, $desk_spec);
			if ($expected !== $socket->send($message, null, 5))
				TrumanException::throwNew($this, "unable to notify {$socket} about new client signature");
		}
		$this->notified = true;
	}

	public function send(TrumanBuck $buck) {

		if (!$this->notified)
			$this->notifyDesks();

		$socket = $this->getSocket($buck);
		$timeout = isset($desk_spec['timeout']) ? $desk_spec['timeout'] : self::TIMEOUT_DEFAULT;
		if (!$socket->sendBuck($buck, null, $timeout))
			TrumanException::throwNew($this, "Unable to send {$buck} to {$socket}");
	}

	private function updateInternals() {
		if ($this->dirty) {
			$this->dirty = false;
			$this->timestamp = microtime(1);
			$this->signature = base64_encode(serialize($this));
		}
	}

	public static function createOrGetSocket($target, $desk_spec) {
		if (!isset(self::$sockets[$target]))
			self::$sockets[$target] = new TrumanSocket($desk_spec, array(
				'force_mode' => TrumanSocket::MODE_CLIENT
			));
		return self::$sockets[$target];
	}

	public static function fromSignature($signature) {
		return @unserialize(base64_decode($signature));
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