<? namespace truman\core;

/**
 * Class Channel Used by Clients to represent a cluster of targets.
 * Implements the consistent pairing algorithm used when Clients map Bucks to Desks
 * @package truman\core
 */
class Channel implements \JsonSerializable {

	private $name;
	private $count;
	private $targets;

	/**
	 * Creates a new channel
	 * @param string $name The name of the channel
	 * @param array|string $targets An optional list of targets in the Channel
	 */
	public function __construct($name, $targets = []) {
		$this->count = 0;
		$this->name = $name;
		if (!is_array($targets))
			$targets = [$targets];
		foreach ($targets as $target)
			$this->addTarget($target);
	}

	/**
	 * @inheritdoc
	 */
	public function __toString() {
		return "Channel<{$this->name}>[{$this->count}]";
	}

	/**
	 * @inheritdoc
	 */
	public function jsonSerialize() {
		return $this->__toString();
	}

	/**
	 * Adds a generic target to the Channel
	 * @param mixed $target the generic target to add
	 */
	public function addTarget($target) {
		$this->targets[] = $target;
		$this->count++;
	}

	/**
	 * Consistently fetches the same generic target given a Buck
	 * @param Buck $buck The Buck to pair with a target
	 * @return mixed The target that the Buck maps to
	 */
	public function getTarget(Buck $buck) {
		$hash  = abs(crc32($buck->getUUID()));
		$index = $hash % $this->count;
		return $this->targets[$index];
	}

}