<? namespace truman;

use truman\interfaces\LoggerContext;

class Desk implements \JsonSerializable, LoggerContext {

	const STATE_ENQUEUED  = 'ENQUEUED';
	const STATE_DELEGATED = 'DELEGATED';
	const STATE_MISSING   = 'MISSING';

	const LOGGER_TYPE                = 'DESK';
	const LOGGER_EVENT_INIT          = 'INIT';
	const LOGGER_EVENT_START         = 'START';
	const LOGGER_EVENT_STOP          = 'STOP';
	const LOGGER_EVENT_REAPED        = 'REAPED';
	const LOGGER_EVENT_BUCK_REROUTE  = 'BUCK_REROUTE';
	const LOGGER_EVENT_CLIENT_IGNORE = 'CLIENT_IGNORE';
	const LOGGER_EVENT_CLIENT_UPDATE = 'CLIENT_UPDATE';

	const OPTION_DRAWER_COUNT            = 'drawer_count';
	const OPTION_BUCK_RECEIVED_HANDLER   = 'buck_received_handler';
	const OPTION_BUCK_PROCESSED_HANDLER  = 'buck_processed_handler';
	const OPTION_RESULT_RECEIVED_HANDLER = 'result_received_handler';

	const STDIN  = 0;
	const STDOUT = 1;
	const STDERR = 2;

	private $id;
	private $client;
	private $logger;
	private $waiting;
	private $tracking;
	private $command;
	private $continue;
	private $processes;
	private $process_pids;
	private $process_ready;
	private $stdins, $stdouts, $stderrs;
	private $inbound_socket;

	private $buck_received_handler;
	private $buck_processed_handler;
	private $result_received_handler;

	private static $_KNOWN_HOSTS = [];

	private static $_DEFAULT_OPTIONS = [
		'client_signature'                   => '',
		'include'                            => [],
		'logger_options'                     => true,
		self::OPTION_DRAWER_COUNT            => 3,
		self::OPTION_BUCK_RECEIVED_HANDLER   => null,
		self::OPTION_BUCK_PROCESSED_HANDLER  => null,
		self::OPTION_RESULT_RECEIVED_HANDLER => null,
	];

	public function __construct($inbound_host_spec = null, array $options = []) {

		$options += self::$_DEFAULT_OPTIONS;

		$this->id       = uniqid(microtime(true), true);
		$this->tracking = [];
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

		if (!is_null($handler = $options[self::OPTION_BUCK_RECEIVED_HANDLER])) {
			if (!is_callable($handler))
				throw new Exception('Invalid handler passed for bucks received', [
					'context' => $this,
					'handler'  => $handler,
					'method'  => __METHOD__
				]);
			else $this->buck_received_handler = $handler;
		}
		if (!is_null($handler = $options[self::OPTION_BUCK_PROCESSED_HANDLER])) {
			if (!is_callable($handler))
				throw new Exception('Invalid handler passed for bucks processed', [
					'context' => $this,
					'handler'  => $handler,
					'method'  => __METHOD__
				]);
			else $this->buck_processed_handler = $handler;
		}
		if (!is_null($handler = $options[self::OPTION_RESULT_RECEIVED_HANDLER])) {
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

		while ($options[self::OPTION_DRAWER_COUNT]-- > 0)
			$this->spawnDrawer();

		register_shutdown_function([$this, '__destruct']);

		$this->logger = new Logger($this, $options['logger_options']);
		$this->logger->log(self::LOGGER_EVENT_INIT, array_values($this->process_pids));

	}

	public function __destruct() {
		$this->stop();
		if (isset($this->inbound_socket)) {
			$this->inbound_socket->__destruct();
			unset($this->inbound_socket);
		}
		if (count($this->processes))
			foreach ($this->getDrawerKeys() as $key)
				$this->killDrawer($key);
	}

	public function __toString() {
		$id = $this->getLoggerId();
		$name = $id ? "<{$id}>" : '';
		$count = $this->getDrawerCount();
		return "Desk{$name}[{$count}]";
	}

	public function jsonSerialize() {
		return $this->__toString();
	}

	public function getLoggerType() {
		return self::LOGGER_TYPE;
	}

	public function getLoggerId() {
		if (isset($this->inbound_socket))
			return $this->inbound_socket->getHostAndPort();
		return '';
	}

	public function getLogger() {
		return $this->logger;
	}

	public function getDrawerCount() {
		return count($this->processes);
	}

	public function getDrawerKeys() {
		return array_keys($this->processes);
	}

	public function enqueueBuck(Buck $buck) {
		if (!$this->isTrackingBuck($buck)) {
			$this->waiting->insert($buck, $priority = $buck->getPriority());
			$buck->getLogger()->log(Buck::LOGGER_EVENT_ENQUEUED, $priority);
			$this->trackBuck($buck, self::STATE_ENQUEUED);
			return $buck;
		} else {
			$buck->getLogger()->log(Buck::LOGGER_EVENT_DEDUPED, $this->getBuckState($buck));
			return null;
		}
	}

	private function dequeueBuck() {
		if ($this->waiting->isEmpty()) return null;
		if ($buck = $this->untrackBuck($this->waiting->extract()))
			$buck->getLogger()->log(Buck::LOGGER_EVENT_DEQUEUED);
		return $buck;
	}

	public function trackBuck(Buck $buck, $status) {
		$this->tracking[$buck->getUUID()] = $status;
		return $buck;
	}

	public function untrackBuck(Buck $buck) {
		if (!$this->isTrackingBuck($buck)) return null;
		unset($this->tracking[$buck->getUUID()]);
		return $buck;
	}

	public function isTrackingBuck(Buck $buck) {
		return isset($this->tracking[$buck->getUUID()]);
	}

	public function getBuckState(Buck $buck) {
		if ($this->isTrackingBuck($buck))
			return $this->tracking[$buck->getUUID()];
		return self::STATE_MISSING;
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
		unset($this->process_ready[$key]);
		unset($this->stdins[$key]);
		unset($this->stdouts[$key]);
		unset($this->stderrs[$key]);
		unset($this->processes[$key]);

	}

	public function ownsBuck(Buck $buck) {

		// always own noop bucks
		if ($buck->isNoop())
			return true;

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

		$buck  = $this->dequeueBuck();
		$valid = $buck instanceof Buck;
		if (!$valid) return null;

		// update client signature, if one exists
		if ($buck->hasClientSignature())
			$this->updateClient($buck->getClient());

		// check ownership, try to reroute, reenqueue if reroute failed
		if (!$this->ownsBuck($buck) && !$this->rerouteBuck($buck, $timeout)) {
			$this->enqueueBuck($buck);
			return null;
		}

		// try to send the buck to the streams, or reenqueue if sending failed
		if (!$this->sendBuckToStreams($buck, $this->stdins, $timeout)) {
			$this->enqueueBuck($buck);
			return null;
		}

		if ($this->buck_processed_handler)
			call_user_func($this->buck_processed_handler, $buck, $this);

		return $buck;

	}

	public function getActiveDrawerCount() {
		$count = $this->getDrawerCount();
		foreach ($this->getDrawerKeys() as $key)
			if (!$this->isAlive($key))
				$count--;
		return $count;
	}

	public function reapDrawers() {

		foreach ($this->getDrawerKeys() as $key) {
			if (!$this->isAlive($key)) {
				$this->logger->log(self::LOGGER_EVENT_REAPED, $this->process_pids[$key]);
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
		$valid = $buck instanceof Buck;
		if (!$valid) return null;

		$buck->getLogger()->log(Buck::LOGGER_EVENT_RECEIVED, $this->getLoggerId());

		if ($this->buck_received_handler)
			call_user_func($this->buck_received_handler, $buck, $this);

		return $this->enqueueBuck($buck);

	}

	public function receiveResult($timeout = 0) {

		$result =
			$this->receiveResultFromStreams($this->stderrs, $timeout) ||
			$this->receiveResultFromStreams($this->stdouts, $timeout);

		if ($result)
			call_user_func($this->result_received_handler, $result, $this);

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

			if ($data && isset($data->buck)) {
				$buck = $data->buck;
				$pid = $this->process_pids[$key];
				$this->untrackBuck($buck);
				$buck->getLogger()->log(Buck::LOGGER_EVENT_DELEGATE_COMPLETE, $pid);
			}

			$this->process_ready[$key] = true;

			return $result;

		}

		return null;

	}

	public function rerouteBuck(Buck $buck, $timeout = 0) {
		$this->logger->log(self::LOGGER_EVENT_BUCK_REROUTE, $buck->getLoggerId());
		$buck->setRoutingDeskId($this->id);
		return $this->getClient()->sendBuck($buck, $timeout);
	}

	private function sendBuckToStreams(Buck $buck, array $streams, $timeout = 0) {

		if (!stream_select($i, $streams, $j, $timeout)) return null;

		foreach ($streams as $key => $stream) {
			if (!$this->process_ready[$key]) continue;
			$pid = $this->process_pids[$key];
			$buck->getLogger()->log(Buck::LOGGER_EVENT_DELEGATE_START, $pid);
			$stream  = $streams[$key];
			$written = Util::writeObjectToStream($buck, $stream);
			if (!$written) {
				$buck->getLogger()->log(Buck::LOGGER_EVENT_DELEGATE_ERROR, $pid);
				continue;
			}
			$this->process_ready[$key] = false;
			return $this->trackBuck($written, self::STATE_DELEGATED);
		}

		return null;

	}

	public function start($timeout = 0, $auto_reap_drawers = true) {
		$this->logger->log(self::LOGGER_EVENT_START);
		while($this->tick($timeout, $auto_reap_drawers));
	}

	public function stop() {
		if ($this->continue) {
			$this->logger->log(self::LOGGER_EVENT_STOP);
			$this->continue = false;
			return true;
		}
		return false;
	}

	public function spawnDrawer() {

		$process = proc_open(
			$this->command,
			Util::getStreamDescriptors(),
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

		$this->process_ready[$key] = true;
		$this->process_pids[$key]  = $process_pid;
		$this->processes[$key]     = $process;
		$this->stdins[$key]        = $stdin;
		$this->stdouts[$key]       = $stdout;
		$this->stderrs[$key]       = $stderr;

	}

	public function updateClient(Client $client) {

		$existing = $this->getClient();

		if ($existing) {
			if ($existing->getSignature() === $client->getSignature()) {
				$this->logger->log(self::LOGGER_EVENT_CLIENT_IGNORE, 'IDENTICAL');
				return null;
			}
			if ($client->getTimestamp() <= $existing->getTimestamp()) {
				$this->logger->log(self::LOGGER_EVENT_CLIENT_IGNORE, 'OUTDATED');
				return null;
			}
		}

		$this->logger->log(self::LOGGER_EVENT_CLIENT_UPDATE, $client->getLoggerId());

		return $this->client = $client;

	}

	public function tick($timeout = 0, $auto_reap_drawers = true) {
		$this->continue = true;
		if ($auto_reap_drawers)
			$this->reapDrawers();
		$this->receiveBuck($timeout);
		$this->processBuck($timeout);
		$this->receiveResult($timeout);
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
