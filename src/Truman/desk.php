<?

class Truman_Desk {

	const STDIN  = 0;
	const STDOUT = 1;
	const STDERR = 2;

	private $inbound;
	private $processes;
	private $waiting, $running;
	private $stdins, $stdouts, $stderrs;

	private static $_DESCRIPTORS = array(
		array('pipe', 'r'),
		array('pipe', 'w'),
		array('pipe', 'w')
	);

	private static $_DEFAULT_OPTIONS = array(
		'spawn'   => 5,
		'include' => array(),
		'inbound' => ''
	);

	public function __construct(array $options = array()) {

		$options += self::$_DEFAULT_OPTIONS;

		$this->running = array();
		$this->waiting = new SplPriorityQueue();
		$this->inbound = new Truman_Socket($options['inbound']);

		while ($options['spawn']-- > 0)
			$this->spawnDrawer($options);

	}

	public function __destruct() {
		foreach ($this->processes as $key => $process) {
			@fputs($this->stdins[$key], "close\n");
			@fclose($this->stdins[$key]);
			@fclose($this->stdouts[$key]);
			@fclose($this->stderrs[$key]);
			@proc_close($process);
		}
		unset($this->processes);
		unset($this->stdins);
		unset($this->stdouts);
		unset($this->stderrs);
		unset($this->inbound);
		gc_collect_cycles();
	}

	public function countWaiting() {
		return count($this->waiting);
	}

	public function countRunning() {
		return count($this->running);
	}

	public function enqueueBuck(Truman_Buck $buck) {
		$this->waiting->insert($buck, $buck->getPriority());
	}

	public function fetchResult() {

		$result = null;
		$stdouts = $this->stdouts;
		$stderrs = $this->stderrs;

		if (stream_select($stderrs, $k, $l, 0)) {
			$stderr = array_pop($stderrs);
			$error = trim(fgets($stderr));
			Truman_Exception::throwNew($this, "Drawer error: {$error}");
		}

		if (stream_select($stdouts, $i, $j, 0)) {
			$stdout = array_pop($stdouts);
			$xml    = trim(fgets($stdout));
			$result = new Truman_Result($xml);
			if ($buck = $result->data()->buck) {
				$uuid = $buck->getUUID();
				unset($this->running[$uuid]);
			}
		}

		return $result;

	}

	public function parseData($data) {

		if ($data === 'close')
			return false;

		$buck = @unserialize($data);
		if ($buck instanceof Truman_Buck)
			$this->enqueueBuck($buck);

		return true;

	}

	public function processBuck(Truman_Buck $buck) {

		$stdins = $this->stdins;

		if (stream_select($i, $stdins, $j, 0)) {
			$stdin    = array_pop($stdins);
			$data     = serialize($buck)."\n";
			$expected = strlen($data);
			$actual   = fputs($stdin, $data);
			return $actual === $expected;
		}

		return false;

	}

	public function processNextBuck() {

		if ($this->waiting->isEmpty())
			return false;

		$buck = $this->waiting->current();
		if ($this->processBuck($buck)) {
			$uuid = $buck->getUUID();
			$this->running[$uuid] = $this->waiting->extract();
			return true;
		}

		return false;

	}

	public function recieveData() {
		return $this->inbound->receive(array($this, 'parseData'));
	}

	public function run() {
		while($this->tick());
	}

	public function spawnDrawer(array $options = array()) {

		$command = $options['include'];
		array_unshift($command, 'bin/drawer.php');
		array_unshift($command, 'php');
		$command = implode(' ', $command);

		$process = proc_open(
			$command, self::$_DESCRIPTORS,
			$streams, TRUMAN_HOME
		);

		if (!is_resource($process))
			Truman_Exception::throwNew($this, 'Unable to open drawer');
		if (!is_resource($stdin = $streams[self::STDIN]))
			Truman_Exception::throwNew($this, 'Unable to write input to drawer');
		if (!is_resource($stdout = $streams[self::STDOUT]))
			Truman_Exception::throwNew($this, 'Unable to read output from drawer');
		if (!is_resource($stderr = $streams[self::STDERR]))
			Truman_Exception::throwNew($this, 'Unable to read errors from drawer');

		stream_set_blocking($stdout, 0);
		stream_set_blocking($stderr, 0);

		$key = (string) $process;

		$this->processes[$key] = $process;
		$this->stdins[$key]    = $stdin;
		$this->stdouts[$key]   = $stdout;
		$this->stderrs[$key]   = $stderr;

	}

	public function tick() {
		$continue = $this->recieveData();
		$this->processNextBuck();
		$this->fetchResult();
		return $continue;
	}

	public function waitForResult() {
		do $result = $this->fetchResult();
		while(is_null($result));
		return $result;
	}

	public function waitForData() {
		do $continue = $this->recieveData();
		while($continue && $this->countWaiting() <= 0);
	}

}
