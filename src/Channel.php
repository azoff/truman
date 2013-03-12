<?

class TrumanChannel {

	private $name;
	private $count;
	private $targets;

	public function __construct($name, $targets = array()) {
		$this->count = 0;
		$this->name = $name;
		if (!is_array($targets))
			$targets = array($targets);
		foreach ($targets as $target)
			$this->addTarget($target);
	}

	public function __toString() {
		return __CLASS__."<{$this->name}>[{$this->count}]";
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