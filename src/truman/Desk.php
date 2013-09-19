<? namespace truman;

class Desk implements \JsonSerializable {

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
	private $process_pids;
	private $stdins, $stdouts, $stderrs;
	private $inbound_socket;

	private $log_drawer_errors;
	private $log_socket_errors;
	private $log_client_updates;
	private $log_client_reroute;
	private $log_dropped_bucks;
	private $log_tick_work;

	private $buck_received_handler;
	private $buck_processed_handler;
	private $result_received_handler;

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
		'log_tick_work'      => true,
		'buck_received_handler'   => null,
		'buck_processed_handler'  => null,
		'result_received_handler' => null,
	);

	public function __construct($inbound_host_spec = null, array $options = []) {

		$options += self::$_DEFAULT_OPTIONS;

		$this->id       = uniqid(microtime(true), true);
		$this->tracking = array();
		$this->waiting  = new \SplPriorityQueue();

		if (!is_null($inbound_host_spec)) {
			$this->inbound_socket = new Socket($inbound_host_spec);
			if ($this->inbound_socket->isClient())
				throw new Exception('Inbound socket may not run in client mode', [
					'context' => $this,
					'socket'  => $this->inbound_socket,
					'method'  => __METHOD__
				]);
		}

		if (strlen($sig = $options['client_signature']))
			$this->client = Client::fromSignature($sig);

		$this->log_drawer_errors  = (bool) $options['log_drawer_errors'];
		$this->log_socket_errors  = (bool) $options['log_socket_errors'];
		$this->log_client_updates = (bool) $options['log_client_updates'];
		$this->log_client_reroute = (bool) $options['log_client_reroute'];
		$this->log_dropped_bucks  = (bool) $options['log_dropped_bucks'];
		$this->log_tick_work      = (bool) $options['log_tick_work'];

		if (!is_null($handler = $options['buck_received_handler'])) {
			if (!is_callable($handler))
				throw new Exception('Invalid handler passed for bucks received', [
					'context' => $this,
					'handler'  => $handler,
					'method'  => __METHOD__
				]);
			else $this->buck_received_handler = $handler;
		}
		if (!is_null($handler = $options['buck_processed_handler'])) {
			if (!is_callable($handler))
				throw new Exception('Invalid handler passed for bucks processed', [
					'context' => $this,
					'handler'  => $handler,
					'method'  => __METHOD__
				]);
			else $this->buck_processed_handler = $handler;
		}
		if (!is_null($handler = $options['result_received_handler'])) {
			if (!is_callable($handler))
				throw new Exception('Invalid handler passed for results received', [
					'context' => $this,
					'handler'  => $handler,
					'method'  => __METHOD__
				]);
			else $this->result_received_handler = $handler;
		}

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
		return "Desk<{$this->inbound_socket}>[{$count}]";
	}

	public function jsonSerialize() {
		return $this->__toString();
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

	public function isAlive($key) {
		$status = proc_get_status($this->processes[$key]);
		return (bool) $status['running'];
	}

	public function killDrawer($key) {

		// send SIGTERM to all child processes
		posix_kill($this->process_pids[$key], 15);

		// close all resources pointing at those processes
		fclose($this->stdins[$key]);
		fclose($this->stdouts[$key]);
		fclose($this->stderrs[$key]);
		proc_close($this->processes[$key]);

		// stop tracking the process
		unset($this->process_pids[$key]);
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

	public function activeDrawerCount() {
		$count = $this->drawerCount();
		foreach ($this->drawerKeys() as $key)
			if (!$this->isAlive($key))
				$count--;
		return $count;
	}

	public function reapDrawers() {

		foreach ($this->drawerKeys() as $key) {
			if (!$this->isAlive($key)) {
				if ($this->log_drawer_errors)
					error_log("{$key} is dead, {$this} respawning drawer...");
				$this->killDrawer($key);
				$this->spawnDrawer();
			}
		}

		return true;

	}

	public function receiveBuck($timeout = 0) {

		if (!isset($this->inbound_socket))
			return null;

		$buck = $this->inbound_socket->receive($timeout);
		if ($buck instanceof Buck)
			return $this->enqueueBuck($buck);

		return null;

	}

	public function receiveResult($timeout = 0) {
		if (is_null($result = $this->receiveResultFromStreams($this->stderrs, $timeout)))
			$result = $this->receiveResultFromStreams($this->stdouts, $timeout);
		return $result;
	}

	private function receiveResultFromStreams(array $streams, $timeout = 0) {

		if (!stream_select($outputs = $streams, $i, $j, $timeout))
			return null;

		foreach ($outputs as $output) {

			$key    = array_pop(array_keys($streams, $output));
			$result = Util::readObjectFromStream($output);
			$valid  = $result instanceof Result;

			if (!$valid) continue;

			$data = $result->data();

			if ($this->log_drawer_errors) {
				if ($data) {
					if (isset($data->error)) {
						if (is_array($data->error))
							error_log("{$this} received error with message '{$data->error['message']}' in {$data->error['file']}:{$data->error['line']} from {$key}");
						else
							error_log("{$this} received error with message '{$data->error}' from {$key}");
					}
					if (isset($data->exception))
						error_log("{$this} received {$data->exception} from {$key}");
				} else {
					error_log("{$this} received empty result data from {$key}");
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

		if (!stream_select($i, $streams, $j, $timeout))
			return null;

		// pick a random available drawer
		$key = array_rand($streams, 1);
		$stream = $streams[$key];

		return Util::writeObjectToStream($buck, $stream);

	}

	public function start($timeout = 0, $auto_reap_drawers = true) {
		while($this->tick($timeout, $auto_reap_drawers));
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
			throw new Exception('Unable to open drawer', [
				'context' => $this,
				'command' => $this->command,
				'method'  => __METHOD__
			]);

		// get shell PID
		$status = proc_get_status($process);
		$process_pid = $status['pid'];

		$key = "Drawer<{$process_pid}>";

		if (!is_resource($stdin = $streams[self::STDIN]))
			throw new Exception('Unable to write to drawer STDIN', [
				'context' => $this,
				'drawer'  => $key,
				'method'  => __METHOD__
			]);
		if (!is_resource($stdout = $streams[self::STDOUT]))
			throw new Exception('Unable to read from drawer STDOUT', [
				'context' => $this,
				'drawer'  => $key,
				'method'  => __METHOD__
			]);
		if (!is_resource($stderr = $streams[self::STDERR]))
			throw new Exception('Unable to write from drawer STDERR', [
				'context' => $this,
				'drawer'  => $key,
				'method'  => __METHOD__
			]);

		stream_set_blocking($stdout, 0);
		stream_set_blocking($stderr, 0);

		$this->process_pids[$key] = $process_pid;
		$this->processes[$key]    = $process;
		$this->stdins[$key]       = $stdin;
		$this->stdouts[$key]      = $stdout;
		$this->stderrs[$key]      = $stderr;

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

	public function tick($timeout = 0, $auto_reap_drawers = true) {

		$this->continue = true;

		// ensure that the children streams are still valid
		if ($auto_reap_drawers) $this->reapDrawers();

		if (!is_null($received_buck = $this->receiveBuck($timeout))) {
			if ($this->log_tick_work)
				error_log("{$this} received {$received_buck}");
			if ($this->buck_received_handler)
				call_user_func($this->buck_received_handler, $received_buck, $this);
		}

		if (!is_null($processed_buck = $this->processBuck($timeout))) {
			if ($this->log_tick_work)
				error_log("{$this} processed {$processed_buck}");
			if ($this->buck_processed_handler)
				call_user_func($this->buck_processed_handler, $processed_buck, $this);
		}

		if (!is_null($received_result = $this->receiveResult($timeout))) {
			if ($this->log_tick_work)
				error_log("{$this} received {$received_result}");
			if ($this->result_received_handler)
				call_user_func($this->result_received_handler, $received_result, $this);
		}

		return $this->continue;

	}

	public function waitingCount() {
		return $this->waiting->count();
	}

	public static function startNew($inbound_host_spec = null, array $options = []) {
		$desk = new Desk($inbound_host_spec, $options);
		$desk->start();
		return $desk;
	}

}
