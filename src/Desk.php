<?

class TrumanDesk {

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
	private $log_tick_work;

	private static $_DESCRIPTORS = array(
		array('pipe', 'r'),
		array('pipe', 'w'),
		array('pipe', 'w')
	);

	private static $_DEFAULT_OPTIONS = array(
		'client_signature'   => '',
		'spawn'              => 3,
		'include'            => array(),
		'buck_port'          => 0,
		'log_drawer_errors'  => true,
		'log_socket_errors'  => true,
		'log_client_updates' => true,
		'log_client_reroute' => true,
		'log_dropped_bucks'  => true,
		'log_tick_work'      => true
	);

	public function __construct(array $options = array()) {

		$options += self::$_DEFAULT_OPTIONS;

		$this->waiting  = new SplPriorityQueue();
		$this->tracking = array();

		$this->buck_socket = new TrumanSocket(array(
			'port' => $options['buck_port']
		));

		if (strlen($sig = $options['client_signature']))
			$this->client = TrumanClient::fromSignature($sig);

		$this->log_drawer_errors  = (bool) $options['log_drawer_errors'];
		$this->log_socket_errors  = (bool) $options['log_socket_errors'];
		$this->log_client_updates = (bool) $options['log_client_updates'];
		$this->log_client_reroute = (bool) $options['log_client_reroute'];
		$this->log_dropped_bucks  = (bool) $options['log_dropped_bucks'];
		$this->log_tick_work      = (bool) $options['log_tick_work'];

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
		unset($this->buck_socket);
		if (count($this->processes))
			foreach ($this->drawerKeys() as $key)
				$this->killDrawer($key);
	}

	public function __toString() {
		$count = $this->drawerCount();
		return __CLASS__."<buck:{$this->buck_socket}>[{$count}]";
	}

	public function drawerCount() {
		return count($this->processes);
	}

	public function drawerKeys() {
		return array_keys($this->processes);
	}

	public function enqueueBuck(TrumanBuck $buck) {
		$uuid = $buck->getUUID();
		if (!array_key_exists($uuid, $this->tracking)) {
			$this->tracking[$uuid] = self::STATE_WAITING;
			$this->waiting->insert($buck, $buck->getPriority());
			return $buck;
		} else if ($this->log_dropped_bucks) {
			error_log("{$this} dropping {$buck} because it is already in the queue");
			return null;
		}
	}

	public function getClient() {
		return $this->client;
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

	public function processBuck($timeout = 0) {

		if ($this->waiting->isEmpty())
			return null;

		$buck = $this->waiting->current();

		if ($buck->hasClientSignature())
			$this->updateClient($buck->getClient());

		// if re-route or sending to stream fails, return null
		if (!$buck->isNoop() && $this->getClient() &&
			!$this->getClient()->isLocalTarget($buck) &&
			!$this->rerouteBuck($buck, $timeout) ||
			!$this->sendBuckToStreams($buck, $this->stdins, $timeout))
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
					error_log("{$this} {$key} is childless, respawning process...");
			} else if ($respawn = $this->isOrphaned($key)) {
				if ($this->log_drawer_errors)
					error_log("{$this} {$key} is orphaned, respawning process...");
			}
			if ($respawn) {
				$this->killDrawer($key);
				$this->spawnDrawer();
			}
		}

		return true;

	}

	public function receiveBuck($timeout = 0) {

		if (!strlen($serialized = $this->buck_socket->receive(null, $timeout)))
			return null;

		$buck = @unserialize($serialized);
		if (!($buck instanceof TrumanBuck)) {
			if ($this->log_socket_errors)
				error_log("{$this} {$this->buck_socket}, '{$serialized}' is not a serialize()'d TrumanBuck");
			return null;
		}

		return $this->enqueueBuck($buck);

	}

	public function receiveResult($timeout = 0) {
		if (is_null($result = $this->receiveResultFromStreams($this->stderrs, $timeout)))
			$result = $this->receiveResultFromStreams($this->stdouts, $timeout);
		return $result;
	}

	private function receiveResultFromStreams(array $streams, $timeout = 0) {

		if (!($r = stream_select($inputs = $streams, $i, $j, $timeout)))
			return null;

		foreach ($inputs as $input) {

			if (!strlen($xml = trim(fgets($input)))) {
				error_log("result: {$xml}");
				continue;
			}

			$key = array_pop(array_keys($streams, $input));

			try {
				$result = new TrumanResult($xml);
			} catch(Exception $ex) {
				$result = TrumanResult::newInstance(false, (object) array(
					'error' => $xml
				));
			}

			$data = $result->data();

			if ($this->log_drawer_errors) {
				if ($data) {
					if (isset($data->error)) {
						if (is_array($data->error))
							error_log("{$this} {$key}, received error with message '{$data->error['message']}' in {$data->error['file']}:{$data->error['line']}");
						else
							error_log("{$this} {$key}, received error with message '{$data->error}'");
					}
					if (isset($data->exception))
						error_log("{$this} {$key}, received {$data->exception}");
				} else {
					error_log("{$this} {$key}, received empty result data");
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

	public function rerouteBuck(TrumanBuck $buck, $timeout = 0) {

		if ($this->log_client_reroute) {
			$socket = $this->getClient()->getSocket($buck);
			error_log("{$this} rerouting {$buck} to {$socket}");
		}

		if (!$this->getClient()->sendBuck($buck, $timeout))
			return null;

		return $buck;

	}

	private function sendBuckToStreams(TrumanBuck $buck, array $streams, $timeout = 0) {

		if (!stream_select($i, $outputs = $streams, $j, $timeout))
			return null;

		foreach ($outputs as $output) {

			$data     = serialize($buck) . "\n";
			$expected = strlen($data);
			$actual   = fputs($output, $data);

			if ($actual !== $expected) {
				if ($this->log_drawer_errors){
					$key = array_pop(array_keys($streams, $output));
					error_log("{$this} {$key}, unable to write {$buck}");
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
			TrumanException::throwNew($this, 'Invalid callback');
		$cycles = array();
		$this->continue = true;
		do $cycles = array_merge($cycles, $this->tick($callback));
		while($this->continue);
		return $cycles;
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
			TrumanException::throwNew($this, 'Unable to open drawer');

		// get shell PID
		$status = proc_get_status($process);
		$shell_pid = $status['pid'];

		$key = "drawer.php<{$shell_pid}";

		if (!is_resource($stdin = $streams[self::STDIN]))
			TrumanException::throwNew($this, "{$key}>, Unable to write input");
		if (!is_resource($stdout = $streams[self::STDOUT]))
			TrumanException::throwNew($this, "{$key}>, Unable to read output");
		if (!is_resource($stderr = $streams[self::STDERR]))
			TrumanException::throwNew($this, "{$key}>, Unable to read errors");

		stream_set_blocking($stdout, 0);
		stream_set_blocking($stderr, 0);

		// get php PID
		$getmypid = new TrumanBuck();
		if (is_null($buck = $this->sendBuckToStreams($getmypid, array($stdin), 5)))
			TrumanException::throwNew($this, "{$key}>, Unable to write {$buck}");
		if (is_null($result = $this->receiveResultFromStreams(array($stdout), 5)))
			TrumanException::throwNew($this, "{$key}>, Unable to read result");
		$php_pid = $result->data()->pid;

		$key = "$key,{$php_pid}>";

		$this->shell_pids[$key] = $shell_pid;
		$this->php_pids[$key]   = $php_pid;
		$this->processes[$key]  = $process;
		$this->stdins[$key]     = $stdin;
		$this->stdouts[$key]    = $stdout;
		$this->stderrs[$key]    = $stderr;

	}

	public function updateClient(TrumanClient $client) {

		if (!$this->getClient()) {
			$new_client = $client;
		} else if ($this->getClient()->getSignature() === $client->getSignature()) {
			if ($this->log_client_updates)
				error_log("{$this} ignoring client update because signatures match; against {$client}");
		} else if ($client->getTimestamp() <= $this->getClient()->getTimestamp()) {
			if ($this->log_client_updates)
				error_log("{$this} ignoring client update because local client is newer; against {$client}");
		} else {
			$new_client = $client;
		}

		if (!isset($new_client))
			return null;

		if ($this->log_client_updates)
			error_log("{$this} updating client using {$client}");

		return $this->client = $new_client;

	}

	public function tick($callback = null, $timeout = 0) {

		$this->reapDrawers();

		$cycles = array();

		do {

			$args = array($this);

			if ($still = !is_null($received_buck = $this->receiveBuck($timeout))) {
				if ($this->log_tick_work)
					error_log("{$this} received {$received_buck}");
			}

			$args[] = $received_buck;

			if ($doing = !is_null($processed_buck = $this->processBuck($timeout))) {
				if ($this->log_tick_work)
					error_log("{$this} processed {$processed_buck}");
			}

			$args[] = $processed_buck;

			if ($work = !is_null($received_result = $this->receiveResult($timeout))) {
				if ($this->log_tick_work)
					error_log("{$this} received " . $received_result->__toString());
			}

			$args[] = $received_result;

			if ($continue = $still || $doing || $work) {
				if (is_callable($callback))
					$cycles[] = call_user_func_array($callback, $args);
				else
					$cycles[] = $args;
			}

		} while ($continue && $this->continue);

		return $cycles;

	}

	public function waitingCount() {
		return $this->waiting->count();
	}

}
