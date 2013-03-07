<?

class Truman_Desk {

	const STDIN  = 0;
	const STDOUT = 1;
	const STDERR = 2;

	private $options;
	private $waiting;
	private $inbound;
	private $continue;
	private $processes;
	private $shell_pids, $php_pids;
	private $stdins, $stdouts, $stderrs;

	private static $_DESCRIPTORS = array(
		array('pipe', 'r'),
		array('pipe', 'w'),
		array('pipe', 'w')
	);

	private static $_DEFAULT_OPTIONS = array(
		'spawn'   => 3,
		'include' => array(),
		'inbound' => '',
		'log_drawer_errors' => true,
		'log_socket_errors' => true
	);

	public function __construct(array $options = array()) {
		$this->options = $options + self::$_DEFAULT_OPTIONS;
		$this->waiting = new SplPriorityQueue();
		$this->inbound = new Truman_Socket($this->options['inbound']);
		$spawn = $this->options['spawn'];
		while ($spawn-- > 0) $this->spawnDrawer();
	}

	public function __destruct() {

		$this->stop();

		foreach ($this->drawerKeys() as $key)
			$this->killDrawer($key);

		// garbage collect
		unset($this->php_pids);
		unset($this->shell_pids);
		unset($this->processes);
		unset($this->stdins);
		unset($this->stdouts);
		unset($this->stderrs);
		unset($this->inbound);
		gc_collect_cycles();

	}

	public function isChildless($key) {
		$pid = $this->shell_pids[$key];
		return !is_numeric(posix_getsid($pid));
	}

	public function isOrphaned($key) {
		$pid = $this->php_pids[$key];
		return !is_numeric(posix_getsid($pid));
	}

	public function drawerCount() {
		return count($this->processes);
	}

	public function drawerKeys() {
		return array_keys($this->processes);
	}

	public function enqueueBuck(Truman_Buck $buck) {
		$this->waiting->insert($buck, $buck->getPriority());
	}

	public function killDrawer($key) {

		// send SIGTERM to all child processes
		posix_kill($this->shell_pids[$key], SIGTERM);
		posix_kill($this->php_pids[$key], SIGTERM);

		// close all resources pointing at those processes
		fclose($this->stdins[$key]);
		fclose($this->stdouts[$key]);
		fclose($this->stderrs[$key]);
		proc_close($this->processes[$key]);

		// stop tracking the process
		unset($this->shell_pids[$key]);
		unset($this->php_pids[$key]);
		unset($this->stdins[$key]);
		unset($this->stdouts[$key]);
		unset($this->stderrs[$key]);
		unset($this->processes[$key]);

	}

	public function processBuck() {

		if ($this->waiting->isEmpty())
			return null;

		$buck = $this->waiting->current();
		if ($buck instanceof Truman_Signal) {
			$this->stop();
			return null;
		}

		return $this->sendBuckToStreams(
			$this->waiting->extract(),
			$this->stdins
		);

	}

	public function reapDrawers() {

		if (!$this->continue)
			return false;

		foreach ($this->drawerKeys() as $key) {
			if ($respawn = $this->isChildless($key)) {
				if ($this->options['log_drawer_errors'])
					error_log("{$key} is childless, respawning process...");
			} else if ($respawn = $this->isOrphaned($key)) {
				if ($this->options['log_drawer_errors'])
					error_log("{$key} is orphaned, respawning process...");
			}
			if ($respawn) {
				$this->killDrawer($key);
				$this->spawnDrawer();
			}
		}

		return true;

	}

	public function receiveBuck() {
		$desk = $this;
		$this->inbound->receive(function($serialized) use (&$desk, &$buck) {
			$buck = @unserialize($serialized);
			if ($buck instanceof Truman_Buck)
				$desk->enqueueBuck($buck);
			else if ($this->options['log_socket_errors'])
				error_log("Inbound@{$this->options['inbound']}: '{$serialized}' is not a serialize()'d Truman_Buck");
		});
		return $buck;
	}

	public function receiveResult() {
		if (!is_null($result = $this->receiveResultFromStreams($this->stderrs)))
			return $result;
		if (!is_null($result = $this->receiveResultFromStreams($this->stdouts)))
			return $result;
		return null;
	}

	private function receiveResultFromStreams(array $streams) {

		if (!stream_select($inputs = $streams, $i, $j, 0))
			return null;

		foreach ($inputs as $input) {

			if (!strlen($xml = trim(fgets($input))))
				continue;

			try {
				$result = new Truman_Result($xml);
			} catch(Exception $ex) {
				$result = Truman_Result::newInstance(false, (object) array(
					'exception' => $ex,
					'error' => $xml
				));
			}

			if ($this->options['log_drawer_errors']) {
				$key = array_pop(array_keys($streams, $input));
				if ($data = $result->data()) {
					if (isset($data->error))
						error_log("{$key}: Received error with message '{$data->error['message']}' in {$data->error['file']}:{$data->error['line']}");
					if (isset($data->exception))
						error_log("{$key}: Received {$data->exception}");
				} else {
					error_log("{$key}: Received empty result data");
				}
			}

			return $result;

		}

		return null;

	}

	private function sendBuckToStreams(Truman_Buck $buck, array $streams) {

		if (!stream_select($i, $outputs = $streams, $j, 0))
			return null;

		foreach ($outputs as $output) {

			$data     = serialize($buck)."\n";
			$expected = strlen($data);
			$actual   = fputs($output, $data);

			if ($actual !== $expected) {
				if ($this->options['log_drawer_errors']){
					$key = array_pop(array_keys($streams, $output));
					error_log("{$key}: Write error");
				}
				continue;
			}

			return $buck;

		}

		return null;

	}

	public function start($callback = null) {
		if (is_null($callback))
			$callback = function() { return true; };
		else if (!is_callable($callback))
			Truman_Exception::throwNew($this, 'Invalid callback');
		$this->continue = true;
		do {
			$this->tick($in, $out, $results);
			foreach($results as $result) {
				if ($callback($result, $this) === false)
					break(2);
			}
		} while($this->continue);
	}

	public function stop() {
		return $this->continue = false;
	}

	public function spawnDrawer() {

		$command = $this->options['include'];
		array_unshift($command, 'bin/drawer.php');
		array_unshift($command, 'php');
		$command = implode(' ', $command);

		$process = proc_open(
			$command, self::$_DESCRIPTORS,
			$streams, TRUMAN_HOME
		);

		if (!is_resource($process))
			Truman_Exception::throwNew($this, 'Unable to open drawer');

		$key = str_replace('Resource id', 'Drawer Resource', $process);

		if (!is_resource($stdin = $streams[self::STDIN]))
			Truman_Exception::throwNew($this, "{$key}: Unable to write input");
		if (!is_resource($stdout = $streams[self::STDOUT]))
			Truman_Exception::throwNew($this, "{$key}: Unable to read output");
		if (!is_resource($stderr = $streams[self::STDERR]))
			Truman_Exception::throwNew($this, "{$key}: Unable to read errors");

		stream_set_blocking($stdout, 0);
		stream_set_blocking($stderr, 0);

		$this->processes[$key] = $process;
		$this->stdins[$key]    = $stdin;
		$this->stdouts[$key]   = $stdout;
		$this->stderrs[$key]   = $stderr;

		// get shell PID
		$status = proc_get_status($process);
		$this->shell_pids[$key] = $status['pid'];

		// get php PID
		$getmypid = new Truman_Buck('getmypid');
		do $buck = $this->sendBuckToStreams($getmypid, array($stdin));
		while (is_null($buck));
		do $result = $this->receiveResultFromStreams(array($stdout));
		while(is_null($result));
		$this->php_pids[$key] = $result->data()->retval;

	}

	public function tick(array &$received_bucks = null, array &$sent_bucks = null, array &$received_results = null) {
		$this->reapDrawers();
		$received_bucks = $sent_bucks = $received_results = array();
		while(!is_null($buck = $this->receiveBuck()))
			$received_bucks[] = $buck;
		while(!is_null($buck = $this->processBuck()))
			$sent_bucks[] = $buck;
		while(!is_null($result = $this->receiveResult()))
			$received_results[] = $result;
	}

}
