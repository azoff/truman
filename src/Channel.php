<?

class TrumanChannel {

	private $count;
	private $targets;

	public function __construct($targets = array()) {
		$this->count = 0;
		if (!is_array($targets))
			$targets = array($targets);
		foreach ($targets as $target)
			$this->addTarget($target);
	}

	public function addTarget($target) {
		$this->targets[] = $target;
		$this->count++;
	}

	public function getTarget(TrumanBuck $buck) {
		$hash  = abs(crc32($buck->getUUID()));
		$index = $hash % $this->count;
		return $this->targets[$index];
	}

}