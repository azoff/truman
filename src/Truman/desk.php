<?

class Truman_Desk {

	const STDIN  = 0;
	const STDOUT = 1;
	const STDERR = 2;

	private $stdins, $stdouts, $stderrs;
	private $processes;

	private static $_DESCRIPTORS = array(
		array('pipe', 'r'),
		array('pipe', 'w'),
		array('pipe', 'w')
	);

	private static $_DEFAULT_OPTIONS = array(
		'includes' => array()
	);

	public function __construct(array $options = array()) {
		$options += self::$_DEFAULT_OPTIONS;
		$this->spawn($options);
	}

	public function __destruct() {
		$this->destroy();
	}

	public function destroy() {

		if (!isset($this->processes))
			return false;

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
		gc_collect_cycles();

		return true;

	}

	public function drawer_count() {
		return isset($this->processes) ? count($this->processes) : 0;
	}

	public function poll() {
		do $result = $this->read();
		while(is_null($result));
		return $result;
	}

	public function read() {

		if (!isset($this->processes))
			return null;

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
		}

		return $result;

	}

	public function spawn(array $options = array()) {

		$command = $options['includes'];
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

	public function write(Truman_Buck $buck) {

		if (!isset($this->processes))
			return false;

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

}
