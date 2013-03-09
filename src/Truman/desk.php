<?

class Truman_Desk {

	const STATE_WAITING = 'waiting';
	const STATE_RUNNING = 'running';

	const STDIN  = 0;
	const STDOUT = 1;
	const STDERR = 2;

	private $client;
	private $waiting;
	private $tracking;
	private $command;
	private $continue;
	private $processes;
	private $shell_pids, $php_pids;
	private $stdins, $stdouts, $stderrs;
	private $buck_socket;

	private $log_drawer_errors;
	private $log_socket_errors;
	private $log_client_updates;
	private $log_client_reroute;
	private $log_dropped_bucks;

	private static $_DESCRIPTORS = array(
		array('pipe', 'r'),
		array('pipe', 'w'),
		array('pipe', 'w')
	);

	private static $_DEFAULT_OPTIONS = array(
		'client_signature'  => '',
		'spawn'             => 3,
		'include'           => array(),
		'buck_port'         => 0,
		'log_drawer_errors' => true,
		'log_socket_errors' => true,
		'log_client_updates' => true,
		'log_client_reroute' => true,
		'log_dropped_bucks' => true
	);

	public function __construct(array $options = array()) {

		$options += self::$_DEFAULT_OPTIONS;

		$this->waiting  = new SplPriorityQueue();
		$this->tracking = array();

		$this->buck_socket = new Truman_Socket(array(
			'port' => $options['buck_port']
		));

		if (strlen($sig = $options['client_signature']))
			$this->client = Truman_Client::fromSignature($sig);

		$this->log_drawer_errors  = (bool) $options['log_drawer_errors'];
		$this->log_socket_errors  = (bool) $options['log_socket_errors'];
		$this->log_client_updates = (bool) $options['log_client_updates'];
		$this->log_client_reroute = (bool) $options['log_client_reroute'];
		$this->log_dropped_bucks  = (bool) $options['log_dropped_bucks'];

		if (!is_array($includes = $options['include']))
			$includes = array($includes);

		$this->command = implode(' ', array_merge(
			array('php bin/drawer.php'),
			array_filter($includes, 'is_readable')
		));

		while ($options['spawn']-- > 0)
			$this->spawnDrawer();
	}

	public function __destruct() {
		$this->stop();
		foreach ($this->drawerKeys() as $key)
			$this->killDrawer($key);
	}

	public function drawerCount() {
		return count($this->processes);
	}

	public function drawerKeys() {
		return array_keys($this->processes);
	}

	public function enqueueBuck(Truman_Buck $buck) {
		$uuid = $buck->getUUID();
		if (!array_key_exists($uuid, $this->tracking)) {
			$this->tracking[$uuid] = self::STATE_WAITING;
			$this->waiting->insert($buck, $buck->getPriority());
		} else if ($this->log_dropped_bucks) {
			error_log("dropping {$buck} because it is already in the queue");
		}
	}

	public function isChildless($key) {
		$pid = $this->shell_pids[$key];
		return !is_numeric(posix_getsid($pid));
	}

	public function isOrphaned($key) {
		$pid = $this->php_pids[$key];
		return !is_numeric(posix_getsid($pid));
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

		if ($buck->hasClientSignature())
			$this->updateClient($buck);

		if ($buck->isNoop())
			return $this->waiting->extract();

		// if re-route or sending to stream fails, return null
		if ($this->client &&
			!$this->client->isLocalTarget($buck) &&
			!$this->rerouteBuck($buck) ||
			!$this->sendBuckToStreams($buck, $this->stdins))
			return null;

		// otherwise, mark the job as running and remove it
		// from the priority queue
		$uuid = $buck->getUUID();
		$this->tracking[$uuid] = self::STATE_RUNNING;
		return $this->waiting->extract();

	}

	public function reapDrawers() {

		if (!$this->continue)
			return false;

		foreach ($this->drawerKeys() as $key) {
			if ($respawn = $this->isChildless($key)) {
				if ($this->log_drawer_errors)
					error_log("{$key} is childless, respawning process...");
			} else if ($respawn = $this->isOrphaned($key)) {
				if ($this->log_drawer_errors)
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
		$this->buck_socket->receive(function($serialized) use (&$desk, &$buck) {
			$buck = @unserialize($serialized);
			if ($buck instanceof Truman_Buck)
				$desk->enqueueBuck($buck);
			else if ($this->log_socket_errors)
				error_log("{$this->buck_socket}, '{$serialized}' is not a serialize()'d Truman_Buck");
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

			$key = array_pop(array_keys($streams, $input));

			try {
				$result = new Truman_Result($xml);
			} catch(Exception $ex) {
				$result = Truman_Result::newInstance(false, (object) array(
					'error' => $xml
				));
			}

			$data = $result->data();

			if ($this->log_drawer_errors) {
				if ($data) {
					if (isset($data->error)) {
						if (is_array($data->error))
							error_log("{$key}, received error with message '{$data->error['message']}' in {$data->error['file']}:{$data->error['line']}");
						else
							error_log("{$key}, received error with message '{$data->error}'");
					}
					if (isset($data->exception))
						error_log("{$key}, received {$data->exception}");
				} else {
					error_log("{$key}, received empty result data");
				}
			}

			if ($data && isset($data->buck)) {
				$uuid = $data->buck->getUUID();
				unset($this->tracking[$uuid]);
			}

			return $result;

		}

		return null;

	}

	public function rerouteBuck(Truman_Buck $buck) {

		if ($this->log_client_reroute) {
			$socket = $this->client->getSocket($buck);
			error_log("rerouting {$buck} to {$socket}");
		}

		if (!$this->client->send($buck))
			return null;

		return $buck;

	}

	private function sendBuckToStreams(Truman_Buck $buck, array $streams) {

		if (!stream_select($i, $outputs = $streams, $j, 0))
			return null;

		foreach ($outputs as $output) {

			$data     = serialize($buck) . "\n";
			$expected = strlen($data);
			$actual   = fputs($output, $data);

			if ($actual !== $expected) {
				if ($this->log_drawer_errors){
					$key = array_pop(array_keys($streams, $output));
					error_log("{$key}, unable to write {$buck}");
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

		$process = proc_open(
			$this->command,
			self::$_DESCRIPTORS,
			$streams, TRUMAN_HOME
		);

		if (!is_resource($process))
			Truman_Exception::throwNew($this, 'Unable to open drawer');

		// get shell PID
		$status = proc_get_status($process);
		$shell_pid = $status['pid'];

		$key = "drawer.php<{$shell_pid}";

		if (!is_resource($stdin = $streams[self::STDIN]))
			Truman_Exception::throwNew($this, "{$key}>, Unable to write input");
		if (!is_resource($stdout = $streams[self::STDOUT]))
			Truman_Exception::throwNew($this, "{$key}>, Unable to read output");
		if (!is_resource($stderr = $streams[self::STDERR]))
			Truman_Exception::throwNew($this, "{$key}>, Unable to read errors");

		stream_set_blocking($stdout, 0);
		stream_set_blocking($stderr, 0);

		// get php PID
		$getmypid = new Truman_Buck();
		do $buck = $this->sendBuckToStreams($getmypid, array($stdin));
		while (is_null($buck));
		do $result = $this->receiveResultFromStreams(array($stdout));
		while(is_null($result));
		$php_pid = $result->data()->pid;

		$key = "$key,{$php_pid}>";

		$this->shell_pids[$key] = $shell_pid;
		$this->php_pids[$key]   = $php_pid;
		$this->processes[$key]  = $process;
		$this->stdins[$key]     = $stdin;
		$this->stdouts[$key]    = $stdout;
		$this->stderrs[$key]    = $stderr;

	}

	public function updateClient(Truman_Buck $buck) {

		if (!$buck->hasClientSignature())
			return null;

		if (!$this->client) {
			$new_client = $buck->getClient();
		} else if ($this->client->getSignature() !== $buck->getClientSignature()) {
			$potential_client = $buck->getClient();
			if ($this->client->getTimestamp() < $potential_client->getTimestamp())
				$new_client = $potential_client;
		}

		if (!isset($new_client))
			return null;

		if ($this->log_client_updates) {
			$signature = $new_client->getSignature();
			error_log("updating client, {$signature}");
		}

		return $this->client = $new_client;

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
