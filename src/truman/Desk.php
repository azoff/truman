<? namespace truman;

class Desk {

	const STATE_WAITING = 'waiting';
	const STATE_RUNNING = 'running';

	const STDIN  = 0;
	const STDOUT = 1;
	const STDERR = 2;

	private $id;
	private $client;
	private $waiting;
	private $tracking;
	private $command;
	private $continue;
	private $processes;
	private $shell_pids, $php_pids;
	private $stdins, $stdouts, $stderrs;
	private $inbound_socket;

	private $log_drawer_errors;
	private $log_socket_errors;
	private $log_client_updates;
	private $log_client_reroute;
	private $log_dropped_bucks;
	private $log_tick_work;

	private static $_KNOWN_HOSTS = array();

	private static $_DESCRIPTORS = array(
		['pipe', 'r'],
		['pipe', 'w'],
		['pipe', 'w']
	);

	private static $_DEFAULT_OPTIONS = array(
		'client_signature'   => '',
		'spawn'              => 3,
		'include'            => array(),
		'log_drawer_errors'  => true,
		'log_socket_errors'  => true,
		'log_client_updates' => true,
		'log_client_reroute' => true,
		'log_dropped_bucks'  => true,
		'log_tick_work'      => true
	);

	public function __construct($inbound_host_spec = null, array $options = []) {

		$options += self::$_DEFAULT_OPTIONS;

		$this->id       = uniqid(microtime(true), true);
		$this->tracking = array();
		$this->waiting  = new \SplPriorityQueue();

		if (!is_null($inbound_host_spec)) {
			$this->inbound_socket = new Socket($inbound_host_spec);
			if ($this->inbound_socket->isClient())
				Exception::throwNew($this, 'inbound socket may not run in client mode');
		}

		if (strlen($sig = $options['client_signature']))
			$this->client = Client::fromSignature($sig);

		$this->log_drawer_errors  = (bool) $options['log_drawer_errors'];
		$this->log_socket_errors  = (bool) $options['log_socket_errors'];
		$this->log_client_updates = (bool) $options['log_client_updates'];
		$this->log_client_reroute = (bool) $options['log_client_reroute'];
		$this->log_dropped_bucks  = (bool) $options['log_dropped_bucks'];
		$this->log_tick_work      = (bool) $options['log_tick_work'];

		if (!is_array($includes = $options['include']))
			$includes = [$includes];

		$this->command = implode(' ', array_merge(
			['php bin/drawer.php'],
			array_filter($includes, 'is_readable')
		));

		while ($options['spawn']-- > 0)
			$this->spawnDrawer();

		register_shutdown_function([$this, '__destruct']);

	}

	public function __destruct() {
		$this->stop();
		if (isset($this->inbound_socket)) {
			$this->inbound_socket->__destruct();
			unset($this->inbound_socket);
		}
		if (count($this->processes))
			foreach ($this->drawerKeys() as $key)
				$this->killDrawer($key);
	}

	public function __toString() {
		$count = $this->drawerCount();
		return __CLASS__."<{$this->inbound_socket}>[{$count}]";
	}

	public function drawerCount() {
		return count($this->processes);
	}

	public function drawerKeys() {
		return array_keys($this->processes);
	}

	public function enqueueBuck(Buck $buck) {
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

	public function dequeueBuck(Buck $buck, $running = false) {

		$extracted = $buck;
		$uuid = $buck->getUUID();

		if (!array_key_exists($uuid, $this->tracking))
			return null;

		if ($running) {
			$this->tracking[$uuid] = self::STATE_RUNNING;
			$extracted = $this->waiting->extract();
		} else {
			// if it's running, it's already been extracted
			if ($this->tracking[$uuid] !== self::STATE_RUNNING)
				$extracted = $this->waiting->extract();
			unset($this->tracking[$uuid]);
		}

		if ($extracted->getUUID() !== $uuid)
			error_log("{$this} dequeue mismatch: expected {$buck}, dequeued {$extracted}");

		return $extracted;

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

	public function ownsBuck(Buck $buck) {

		// assume ownership if no client is available
		if (!($client = $this->getClient()))
			return true;

		// assume ownership if no inbound socket exists
		if (!isset($this->inbound_socket))
			return true;

		// ensure that the outbound and inbound ports match
		$desk_spec = $client->getDeskSpec($buck);
		if ($this->inbound_socket->getPort() !== intval($desk_spec['port']))
			return false;

		// check to see if we've seen this host before
		$desk_host = gethostbyname($desk_spec['host']);
		if (in_array($desk_host, self::$_KNOWN_HOSTS))
			return true;

		// check to see if we routed this buck to ourselves
		if ($buck->getRoutingDeskId() === $this->id) {
			if ($this->log_client_reroute)
				error_log("{$this} now accepts bucks on interface {$desk_host}");
			self::$_KNOWN_HOSTS[] = $desk_host; // cache it!
			return true;
		}

		// check to see if hosts match by naive comparison
		if ($this->inbound_socket->getHost() === $desk_host)
			return true;

		// if that fails, see if the outbound host maps one of the local host's addresses
		if (Util::isLocalAddress($desk_host))
			return true;

		return false;

	}

	public function processBuck($timeout = 0) {

		if ($this->waiting->isEmpty())
			return null;

		$buck = $this->waiting->top();

		// update client signature, if one exists
		if ($buck->hasClientSignature())
			$this->updateClient($buck->getClient());

		// always process noop bucks locally
		if ($buck->isNoop())
			return $this->dequeueBuck($buck);

		// check to see if the client agrees that buck belongs here
		if (!$this->ownsBuck($buck)) {
			// reroute if it doesn't belong here
			if ($this->rerouteBuck($buck, $timeout))
				return $this->dequeueBuck($buck);
			return null;
		}

		// if sending to stream fails, return null
		if (!$this->sendBuckToStreams($buck, $this->stdins, $timeout))
			return null;

		// otherwise, mark the job as running and remove it from the queue
		return $this->dequeueBuck($buck, true);

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

		if (!isset($this->inbound_socket))
			return null;

		if (!strlen($serialized = $this->inbound_socket->receive(null, $timeout)))
			return null;

		$buck = @unserialize($serialized);
		if (!($buck instanceof Buck)) {
			if ($this->log_socket_errors)
				error_log("{$this} {$this->inbound_socket}, '{$serialized}' is not a serialize()'d Buck");
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

			if (!strlen($serialized = trim(fgets($input)))) {
				error_log("result: {$serialized}");
				continue;
			}

			$key = array_pop(array_keys($streams, $input));
			$result = @unserialize($serialized);
			if (!$result)
				$result = new Result(false, (object) ['error' => $serialized]);

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

			if ($data && isset($data->buck))
				$this->dequeueBuck($data->buck);

			return $result;

		}

		return null;

	}

	public function rerouteBuck(Buck $buck, $timeout = 0) {

		if ($this->log_client_reroute) {
			$spec = $this->getClient()->getDeskSpec($buck);
			error_log("{$this} rerouting {$buck} to Desk<{$spec['host']}:{$spec['port']}>");
		}

		$buck->setRoutingDeskId($this->id);

		if (!$this->getClient()->sendBuck($buck, $timeout))
			return null;

		return $buck;

	}

	private function sendBuckToStreams(Buck $buck, array $streams, $timeout = 0) {

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

	public function start($callback = null, $timeout = 0) {
		if (is_null($callback))
			$callback = function() { return true; };
		else if (!is_callable($callback))
			Exception::throwNew($this, 'Invalid callback');
		$cycles = array();
		$this->continue = true;
		do $cycles = array_merge($cycles, $this->tick($callback, $timeout));
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
			Exception::throwNew($this, 'Unable to open drawer');

		// get shell PID
		$status = proc_get_status($process);
		$shell_pid = $status['pid'];

		$key = "drawer.php<{$shell_pid}";

		if (!is_resource($stdin = $streams[self::STDIN]))
			Exception::throwNew($this, "{$key}>, Unable to write input");
		if (!is_resource($stdout = $streams[self::STDOUT]))
			Exception::throwNew($this, "{$key}>, Unable to read output");
		if (!is_resource($stderr = $streams[self::STDERR]))
			Exception::throwNew($this, "{$key}>, Unable to read errors");

		stream_set_blocking($stdout, 0);
		stream_set_blocking($stderr, 0);

		// get php PID
		$getmypid = new Buck();
		if (is_null($buck = $this->sendBuckToStreams($getmypid, [$stdin], 5)))
			Exception::throwNew($this, "{$key}>, Unable to write {$buck}");
		if (is_null($result = $this->receiveResultFromStreams([$stdout], 5)))
			Exception::throwNew($this, "{$key}>, Unable to read result");
		$php_pid = $result->data()->pid;

		$key = "$key,{$php_pid}>";

		$this->shell_pids[$key] = $shell_pid;
		$this->php_pids[$key]   = $php_pid;
		$this->processes[$key]  = $process;
		$this->stdins[$key]     = $stdin;
		$this->stdouts[$key]    = $stdout;
		$this->stderrs[$key]    = $stderr;

	}

	public function updateClient(Client $client) {

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

		$callback = is_callable($callback) ? $callback : null;

		do {

			$args = [$this];

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
					error_log("{$this} received {$received_result}");
			}

			$args[] = $received_result;

			if ($continue = $still || $doing || $work) {
				if ($callback)
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