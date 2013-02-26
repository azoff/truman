<?

class Courier {

	private $bucks;

	public function __construct($port, $workers = 1) {
		$this->bucks = new SplPriorityQueue();

	}

	public function dequeue() {
		return $this->bucks->extract();
	}

	public function enqueue(Truman_Buck $buck, $priority = 0) {
		$this->bucks->insert($buck, $priority);
	}

	public function next() {
		return $this->bucks->next();
	}

}
