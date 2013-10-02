<? namespace truman\core;

use truman\interfaces\LoggerContext;

/**
 * Class Desk Receives, prioritizes, and processes Bucks
 * @package truman\core
 */
class Desk implements \JsonSerializable, LoggerContext {

	const DEFAULT_HOST = 0;
	const DEFAULT_PORT = 12345;

	/**
	 * Tracked Buck is in this Desk's priority queue
	 */
	const STATE_ENQUEUED  = 'ENQUEUED';

	/**
	 * Tracked Buck is currently delegated to one of this Desk's Drawers
	 */
	const STATE_DELEGATED = 'DELEGATED';

	/**
	 * Untracked Buck
	 */
	const STATE_MISSING   = 'MISSING';

	const LOGGER_TYPE = 'DESK';

	/**
	 * Occurs when this Desk is instantiated
	 */
	const LOGGER_EVENT_INIT = 'INIT';

	/**
	 * Occurs when this Desk starts listening for incoming Bucks
	 */
	const LOGGER_EVENT_START         = 'START';

	/**
	 * Occurs when this Desk stops listening for incoming Bucks
	 */
	const LOGGER_EVENT_STOP          = 'STOP';

	/**
	 * Occurs when this Desk reaps one or more of its Drawers
	 */
	const LOGGER_EVENT_REAPED        = 'REAPED';

	/**
	 * Occurs when this Desk restarts all of its drawers
	 */
	const LOGGER_EVENT_REFRESHED     = 'REFRESHED';

	/**
	 * Occurs when this Desk receives something it did not expect
	 */
	const LOGGER_EVENT_RECEIVE_ERROR = 'RECEIVE_ERROR';

	/**
	 * Occurs when this Desk routes a Buck to another Desk (deduplication)
	 */
	const LOGGER_EVENT_BUCK_REROUTE  = 'BUCK_REROUTE';

	/**
	 * Occurs when this Desk ignores a Client update (older, or identical client)
	 */
	const LOGGER_EVENT_CLIENT_IGNORE = 'CLIENT_IGNORE';

	/**
	 * Occurs when this Desk installs a Client update
	 */
	const LOGGER_EVENT_CLIENT_UPDATE = 'CLIENT_UPDATE';

	/**
	 * A list of includes to instantiate this Desk's Drawers with
	 */
	const OPTION_INCLUDE                 = 'include';

	/**
	 * The number of Drawers to spawn under this Desk
	 */
	const OPTION_DRAWER_COUNT            = 'drawer_count';

	/**
	 * Options to pass to the Logger for this Desk
	 */
	const OPTION_LOGGER_OPTS             = 'logger_options';

	/**
	 * Automatically reap Drawers when they die
	 */
	const OPTION_AUTO_REAP_DRAWERS       = 'auto_reap_drawers';

	/**
	 * A method to call when this Desk receives a Buck
	 */
	const OPTION_BUCK_RECEIVED_HANDLER   = 'buck_received_handler';

	/**
	 * A method to call when this Desk processes a Buck
	 */
	const OPTION_BUCK_PROCESSED_HANDLER  = 'buck_processed_handler';

	/**
	 * A method to call when this Desk receives a Result
	 */
	const OPTION_RESULT_RECEIVED_HANDLER = 'result_received_handler';

	const STDIN  = 0;
	const STDOUT = 1;
	const STDERR = 2;

	private $id;
	private $client;
	private $logger;
	private $command;
	private $continue;
	private $inbound_socket;
	private $auto_reap_drawers;

	private $queue;
	private $buck_states  = [];
	private $buck_objects = [];
	private $bucks_delegated = [];

	private $stdins       = [];
	private $stdouts      = [];
	private $stderrs      = [];
	private $processes    = [];
	private $process_pids = [];

	private $buck_received_handler;
	private $buck_processed_handler;
	private $result_received_handler;

	private static $_KNOWN_HOSTS = [];

	private static $_DEFAULT_OPTIONS = [
		self::OPTION_INCLUDE                 => [],
		self::OPTION_LOGGER_OPTS             => [],
		self::OPTION_AUTO_REAP_DRAWERS       => true,
		self::OPTION_DRAWER_COUNT            => 3,
		self::OPTION_BUCK_RECEIVED_HANDLER   => null,
		self::OPTION_BUCK_PROCESSED_HANDLER  => null,
		self::OPTION_RESULT_RECEIVED_HANDLER => null,
	];

	/**
	 * Runs a Desk from the command line
	 * @param array $argv The arguments passed into the command line
	 * @param array $option_keys Any options to set by command line (defaults to all options)
	 */
	public static function main(array $argv, array $option_keys = null) {
		$args        = Util::getArgs($argv);
		$options     = Util::getOptions($option_keys, self::$_DEFAULT_OPTIONS);
		$desk_spec   = array_shift($args);
		try {
			$desk = new Desk($desk_spec, $options);
			exit($desk->start());
		} catch (Exception $ex) {
			error_log("Error: {$ex->getMessage()}");
			exit(1);
		}
	}

	/**
	 * Creates a new Desk instance
	 * @param int|string|array $inbound_socket_spec The socket specification to receive Bucks over the network. Can be
	 * anything accepted in Socket::__construct()
	 * @param array $options Optional settings for the Desk. See Desk::$_DEFAULT_OPTIONS
	 * @throws Exception If any handlers are not callable, or if the inbound socket is invalid
	 */
	public function __construct($inbound_socket_spec = null, array $options = []) {

		$options += self::$_DEFAULT_OPTIONS;

		$this->id    = uniqid(microtime(true), true);
		$this->queue = new \SplPriorityQueue();

		if (!is_null($inbound_socket_spec)) {
			$this->inbound_socket = new Socket($inbound_socket_spec);
			if ($this->inbound_socket->isClient())
				throw new Exception('Inbound socket may not run in client mode', [
					'context' => $this,
					'socket'  => $this->inbound_socket,
					'method'  => __METHOD__
				]);
		}

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

		if (!is_array($includes = $options[self::OPTION_INCLUDE]))
			$includes = [$includes];

		$this->auto_reap_drawers = $options[self::OPTION_AUTO_REAP_DRAWERS];

		$this->command = implode(' ', array_merge(
			['php bin/drawer.php'],
			array_filter($includes, 'is_readable')
		));

		while ($options[self::OPTION_DRAWER_COUNT]-- > 0)
			$this->spawnDrawer();

		$this->logger = new Logger($this, $options[self::OPTION_LOGGER_OPTS]);
		$this->logger->log(self::LOGGER_EVENT_INIT, array_values($this->process_pids));

		Util::onShutdown([$this, 'close']);

	}

	/**
	 * @inheritdoc
	 */
	public function __destruct() {
		$this->close();
	}

	/**
	 * @inheritdoc
	 */
	public function __toString() {
		$id = $this->getLoggerId();
		$name = $id ? "<{$id}>" : '';
		$count = $this->getDrawerCount();
		return "Desk{$name}[{$count}]";
	}

	/**
	 * @inheritdoc
	 */
	public function jsonSerialize() {
		return $this->__toString();
	}

	/**
	 * Checks a notification to see if it applies to this Desk. If so, this method takes the appropriate action.
	 * @param Notification $notification The notification to check.
	 */
	public function checkNotification(Notification $notification) {
		if ($notification->isClientUpdate())
			$this->updateClient($notification);
		if ($notification->isDeskRefresh())
			$this->refreshDrawers($notification);
	}

	/**
	 * Closes the inbound Socket and kills all child Drawers
	 */
	public function close() {
		$this->stop();
		if (isset($this->inbound_socket)) {
			$this->inbound_socket->__destruct();
			unset($this->inbound_socket);
		}
		$this->killDrawers();
	}

	/**
	 * @inheritdoc
	 */
	public function getLoggerType() {
		return self::LOGGER_TYPE;
	}

	/**
	 * @inheritdoc
	 */
	public function getLoggerId() {
		if (isset($this->inbound_socket))
			return $this->inbound_socket->getHostAndPort();
		return '';
	}

	/**
	 * @inheritdoc
	 */
	public function getLogger() {
		return $this->logger;
	}

	/**
	 * The unique ID for this Desk
	 * @return string
	 */
	public function getId() {
		return $this->id;
	}

	/**
	 * Gets the size of the priority queue in this Desk
	 * @return int
	 */
	public function getQueueSize() {
		return $this->queue->count();
	}

	/**
	 * Gets the number of defined Drawers for this Desk
	 * @return int
	 */
	public function getDrawerCount() {
		return count($this->processes);
	}

	/**
	 * Gets the keys for each of the Drawers in this Desk
	 * @return array
	 */
	public function getDrawerKeys() {
		return array_keys($this->processes);
	}

	/**
	 * Adds a Buck to the internal priority queue for this Desk
	 * @param Buck $buck The Buck to add
	 * @return null|Buck The enqueued Buck if successful, otherwise null
	 */
	public function enqueueBuck(Buck $buck) {
		if (!$this->isTrackingBuck($buck)) {
			$this->queue->insert($buck->getUUID(), $priority = $buck->getPriority());
			$buck->getLogger()->log(Buck::LOGGER_EVENT_ENQUEUED, $priority);
			$this->trackBuck($buck, self::STATE_ENQUEUED);
			return $buck;
		} else {
			$buck->getLogger()->log(Buck::LOGGER_EVENT_DEDUPED, $this->getBuckState($buck));
			return null;
		}
	}

	/**
	 * Restarts all child Drawers, effectively refreshing their loaded code
	 * @param Notification $notification
	 */
	public function refreshDrawers(Notification $notification) {
		while (count($this->bucks_delegated))
			$this->receiveResults();
		foreach ($this->getDrawerKeys() as $key) {
			$this->killDrawer($key);
			$this->spawnDrawer();
		}
		$this->getLogger()->log(self::LOGGER_EVENT_REFRESHED, $notification->getUUID());
	}

	/**
	 * Gets a tracked Buck by UUID
	 * @param string $uuid The UUID of the tracked Buck
	 * @return null|Buck depending on whether the Buck is tracked or not
	 */
	private function getBuck($uuid) {
		if (isset($this->buck_objects[$uuid]))
			return $this->buck_objects[$uuid];
		return null;
	}

	/**
	 * Removes the top Buck from this Desk's priority queue
	 * @param bool $untrack untracks the Buck on top of dequeuing it
	 * @return null|Buck The top Buck from the queue, or null if the queue is empty or the Buck is untracked
	 */
	private function dequeueBuck($untrack = true) {
		if ($this->queue->isEmpty()) return null;
		$buck = $this->getBuck($this->queue->extract());
		if ($untrack) $buck = $this->untrackBuck($buck);
		if ($buck) $buck->getLogger()->log(Buck::LOGGER_EVENT_DEQUEUED);
		return $buck;
	}

	/**
	 * Exposes the top Buck in the queue
	 * @return Buck|null The top Buck from the queue, or null if the queue is empty
	 */
	private function nextBuck() {
		if ($this->queue->isEmpty()) return null;
		return $this->getBuck($this->queue->top());
	}

	/**
	 * Tracks a Buck's state
	 * @param Buck $buck The Buck to track the state of
	 * @param string $state The state to track
	 * @return Buck The tracked Buck
	 */
	public function trackBuck(Buck $buck, $state) {
		$uuid = $buck->getUUID();
		$this->buck_states[$uuid] = $state;
		$this->buck_objects[$uuid] = $buck;
		return $buck;
	}

	/**
	 * Untracks a Buck's state
	 * @param Buck $buck The Buck to untrack the state from
	 * @return null|Buck The untracked Buck
	 */
	public function untrackBuck(Buck $buck) {
		if (!$this->isTrackingBuck($buck)) return null;
		$uuid = $buck->getUUID();
		unset($this->buck_states[$uuid]);
		unset($this->buck_objects[$uuid]);
		return $buck;
	}

	/**
	 * Gets whether or not the Buck is tracked
	 * @param Buck $buck The Buck to check
	 * @return bool True if tracked, otherwise false
	 */
	public function isTrackingBuck(Buck $buck) {
		return isset($this->buck_states[$buck->getUUID()]);
	}

	/**
	 * Gets the tracked state of a Buck
	 * @param Buck $buck The Buck to check the state of
	 * @return string The state of the Buck
	 */
	public function getBuckState(Buck $buck) {
		if ($this->isTrackingBuck($buck))
			return $this->buck_states[$buck->getUUID()];
		return self::STATE_MISSING;
	}

	/**
	 * The internal Client for this Desk. Could be null if no Client notifications came in.
	 * @return Client|null
	 */
	public function getClient() {
		return $this->client;
	}

	/**
	 * Checks to see if a Drawer is alive
	 * @param string $key The key the drawer is named under
	 * @return bool true if alive, otherwise false
	 */
	public function drawerAlive($key) {
		$process = $this->processes[$key];
		if (is_null($process)) return false;
		$status = proc_get_status($process);
		return (bool) $status['running'];
	}

	/**
	 * Kills all Drawers and unsets their keys
	 */
	public function killDrawers() {
		foreach ($this->getDrawerKeys() as $key)
			$this->killDrawer($key);
	}

	/**
	 * Retries processing on a Buck
	 * @param Buck $buck The Buck to retry
	 * @param string|null $key The key of the drawer that caused a retry
	 */
	private function retryBuck(Buck $buck, $key = null) {
		if (!is_null($key))
			$buck->getLogger()->log(Buck::LOGGER_EVENT_DELEGATE_ERROR, $this->process_pids[$key]);
		$buck->getLogger()->log(Buck::LOGGER_EVENT_RETRY);
		$this->enqueueBuck($this->untrackBuck($buck));
	}

	/**
	 * Kills a Drawer and unsets its key
	 * @param string $key The key the drawer is named under, and the key to unset
	 */
	public function killDrawer($key) {

		// send SIGTERM to all child processes
		posix_kill($this->process_pids[$key], 15);

		// close all resources pointing at those processes
		fclose($this->stdins[$key]);
		fclose($this->stdouts[$key]);
		fclose($this->stderrs[$key]);
		proc_close($this->processes[$key]);

		// if the drawer died without finishing a job, reenqueue it
		if (isset($this->bucks_delegated[$key])) {
			$uuid = $this->bucks_delegated[$key];
			$buck = $this->getBuck($uuid);
			$this->retryBuck($buck, $key);
		}

		// stop tracking the process
		unset($this->bucks_delegated[$key]);
		unset($this->process_pids[$key]);
		unset($this->stdins[$key]);
		unset($this->stdouts[$key]);
		unset($this->stderrs[$key]);
		unset($this->processes[$key]);

	}

	/**
	 * Checks if a Buck belongs to this Desk, or another Desk
	 * @param Buck $buck The Buck to check
	 * @return bool true if the Buck belongs to this Desk, otherwise false
	 */
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
		if ($this->inbound_socket->getPort() !== intval($desk_spec[Socket::SPEC_PORT]))
			return false;

		// check to see if we've seen this host before
		$desk_host = gethostbyname($desk_spec[Socket::SPEC_HOST]);
		if (in_array($desk_host, self::$_KNOWN_HOSTS))
			return true;

		// check to see if we routed this buck to ourselves
		if ($buck->getRoutingDeskId() === $this->getId()) {
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

	/**
	 * Sends Enqueued Bucks to Drawers while there are Drawers to accept and there are Bucks to send.
	 * May also reroute Bucks or update internals should the Buck be a Notification
	 * @param int $timeout Time to wait until a Drawer can accept a Buck
	 * @return array A list of Bucks sent to Drawers
	 */
	public function processBucks($timeout = 0) {
		$bucks = [];
		while (!is_null($buck = $this->processBuck($timeout)))
			$bucks[] = $buck;
		return $bucks;
	}

	/**
	 * Sends, at most, one enqueued Buck to a Drawer while there are Drawers to accept and there are Bucks to send.
	 * May also reroute Bucks or update internals should the Buck be a Notification
	 * @param int $timeout Time to wait until a Drawer can accept a Buck
	 * @return Buck|null
	 */
	public function processBuck($timeout = 0) {

		$buck  = $this->nextBuck();
		$valid = $buck instanceof Buck;
		if (!$valid) return null;

		// check to see if this is a client signature
		if ($buck instanceof Notification)
			$this->checkNotification($buck);

		// check ownership, try to reroute, reenqueue if reroute failed
		if (!$this->ownsBuck($buck))
			return $this->rerouteBuck($buck, $timeout) ? $this->dequeueBuck() : null;

		// try to send the buck to the streams, or reenqueue if sending failed
		if (!$this->sendBuckToStreams($buck, $this->stdins, $timeout))
			return null;

		// pass the processed Buck to the handler
		if ($this->buck_processed_handler)
			call_user_func($this->buck_processed_handler, $buck, $this);

		// dequeue the Buck, but keep tracking it
		return $this->dequeueBuck(false);

	}

	/**
	 * Gets the count of Drawers that are still running
	 * @return int
	 */
	public function getActiveDrawerCount() {
		$count = $this->getDrawerCount();
		foreach ($this->getDrawerKeys() as $key)
			if (!$this->drawerAlive($key))
				$count--;
		return $count;
	}

	/**
	 * Ensures that all keyed Drawers are still alive, and reaps them should they be dead
	 * @return bool
	 */
	public function reapDrawers() {
		$reaped = 0;
		foreach ($this->getDrawerKeys() as $key)
			if (!$this->drawerAlive($key))
				$reaped += $this->reapDrawer($key);
		return true;
	}

	/**
	 * Reaps a keyed Drawer by removing its references and restarting it
	 * @param string $key The drawer to reap
	 * @return int The number of Drawers restarted
	 */
	public function reapDrawer($key) {
		$this->logger->log(self::LOGGER_EVENT_REAPED, $this->process_pids[$key]);
		$this->killDrawer($key);
		return $this->spawnDrawer() ? 1 : 0;
	}

	/**
	 * Checks if a Drawer is alive and automatically reaps it - should that setting be enabled
	 * @param string $key The key for the Drawer to check
	 * @return bool true if the drawer is ready for a Buck, false if it was reaped or simply not ready
	 */
	public function checkDrawer($key) {
		if ($this->drawerAlive($key))
			return !isset($this->bucks_delegated[$key]);
		if ($this->auto_reap_drawers)
			$this->reapDrawer($key);
		return false;
	}

	/**
	 * Receives a Buck from the network
	 * @param int $timeout The time to wait for the Socket to have data
	 * @return null|Buck A received Buck, or null if no Buck was received
	 */
	public function receiveBuck($timeout = 0) {

		if (!isset($this->inbound_socket))
			return null;

		if (is_null($buck = $this->inbound_socket->receive($timeout)))
			return null;

		$valid = $buck instanceof Buck;
		if (!$valid) {
			$this->logger->log(self::LOGGER_EVENT_RECEIVE_ERROR, $buck);
			return null;
		}

		$buck->getLogger()->log(Buck::LOGGER_EVENT_RECEIVED, $this->getLoggerId());

		if ($this->buck_received_handler)
			call_user_func($this->buck_received_handler, $buck, $this);

		return $this->enqueueBuck($buck);

	}

	/**
	 * Receives Results from Drawers while there are Drawers that have Results to send
	 * @param int $timeout How long to wait for a Drawer to have a result
	 * @return array A list of received results
	 */
	public function receiveResults($timeout = 0) {

		$error_results  = $this->receiveResultsFromStreams($this->stderrs, $timeout);
		$output_results = $this->receiveResultsFromStreams($this->stdouts, $timeout);
		$all_results    = array_merge($error_results, $output_results);

		if ($this->result_received_handler)
			foreach ($all_results as $result)
				call_user_func($this->result_received_handler, $result, $this);

		return $all_results;

	}

	/**
	 * Receives Results from Drawer streams, so long as those streams have Results to give
	 * @param array $streams The Drawer streams to check
	 * @param int $timeout The time to wait until the streams have Results
	 * @return array A list of Results from the streams
	 */
	private function receiveResultsFromStreams(array $streams, $timeout = 0) {

		$results = [];

		if (!$streams || !stream_select($streams, $i, $j, $timeout))
			return $results;

		foreach ($streams as $key => $stream) {

			$result = Util::readObjectFromStream($stream);

			if ($result instanceof Result) {

				if ($buck = $result->getBuck()) {
					$pid = $this->process_pids[$key];
					$this->untrackBuck($buck);
					$buck->getLogger()->log(Buck::LOGGER_EVENT_DELEGATE_COMPLETE, $pid);
				}

				unset($this->bucks_delegated[$key]);
				$results[] = $result;

				// check drawers after read
				$this->checkDrawer($key);

			}

		}

		return $results;

	}

	/**
	 * Reroutes a Buck from this Desk to its target Desk
	 * @param Buck $buck The Buck to reroute
	 * @param int $timeout The time to wait until rerouting is possible
	 * @return null|Buck The
	 */
	public function rerouteBuck(Buck $buck, $timeout = 0) {
		$this->logger->log(self::LOGGER_EVENT_BUCK_REROUTE, $buck->getLoggerId());
		$buck->setRoutingDesk($this);
		return $this->getClient()->sendBuck($buck, $timeout);
	}

	/**
	 * Sends a Buck to the first Drawer stream this Desk can send to
	 * @param Buck $buck The Buck to send
	 * @param array $streams The Drawer streams to send to
	 * @param int $timeout The time to wait until a Drawer stream is ready for input
	 * @return null|Buck The Buck sent to a Drawer stream, or false if unable to send the Buck
	 */
	private function sendBuckToStreams(Buck $buck, array $streams, $timeout = 0) {

		if (!$streams || !stream_select($i, $streams, $j, $timeout))
			return null;

		foreach ($streams as $key => $stream) {

			// ensure that the drawer is active
			if (!$this->checkDrawer($key)) continue;

			$pid = $this->process_pids[$key];
			$buck->getLogger()->log(Buck::LOGGER_EVENT_DELEGATE_START, $pid);
			$stream  = $streams[$key];
			$written = Util::writeObjectToStream($buck, $stream);

			if (!$written) {
				$buck->getLogger()->log(Buck::LOGGER_EVENT_DELEGATE_ERROR, $pid);
				continue;
			}

			$this->bucks_delegated[$key] = $buck->getUUID();

			return $this->trackBuck($written, self::STATE_DELEGATED);

		}

		return null;

	}

	/**
	 * Starts this Desk's tick() cycle: receiving and processing until the Desk is stopped
	 * @param int $timeout
	 * @return int
	 */
	public function start($timeout = 0) {
		$this->logger->log(self::LOGGER_EVENT_START);
		while($this->tick($timeout));
		return 0;
	}

	/**
	 * Stops this desk's tick() cycle.
	 * @return bool
	 */
	public function stop() {
		if ($this->continue) {
			$this->logger->log(self::LOGGER_EVENT_STOP);
			$this->continue = false;
			return true;
		}
		return false;
	}

	/**
	 * Creates a new Drawer to be owned by this Desk
	 * @return string The key for the created Drawer
	 * @throws Exception if unable to spawn the Drawer
	 */
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

		// reference the streams
		$stdin  = $streams[self::STDIN];
		$stdout = $streams[self::STDOUT];
		$stderr = $streams[self::STDERR];

		// get shell PID
		$status = proc_get_status($process);
		$process_pid = $status['pid'];
		$key = "Drawer<{$process_pid}>";

		// wait until the streams are ready
		stream_set_blocking($stdout, 0);
		stream_set_blocking($stderr, 0);

		$this->process_pids[$key]  = $process_pid;
		$this->processes[$key]     = $process;
		$this->stdins[$key]        = $stdin;
		$this->stdouts[$key]       = $stdout;
		$this->stderrs[$key]       = $stderr;

		return $key;

	}

	/**
	 * Checks to see if the Client in the Notification can replace this Desk's Client, and then replaces it.
	 * @param Notification $notification The notification containing a Client signature
	 * @return null|Client the Client being used by the Desk
	 */
	public function updateClient(Notification $notification) {

		$existing = $this->getClient();
		$client = Client::fromSignature($notification->getNotice());

		if (!$existing) {
			$this->logger->log(self::LOGGER_EVENT_CLIENT_UPDATE, $client->getLoggerId());
			return $this->client = $client;
		}

		if ($existing->getSignature() === $client->getSignature()) {
			$this->logger->log(self::LOGGER_EVENT_CLIENT_IGNORE, 'IDENTICAL');
			return $this->client;
		}

		if ($client->getTimestamp() <= $existing->getTimestamp()) {
			$this->logger->log(self::LOGGER_EVENT_CLIENT_IGNORE, 'OUTDATED');
			return $this->client;
		}

		$this->logger->log(self::LOGGER_EVENT_CLIENT_UPDATE, $client->getLoggerId());
		return $this->client = $client;

	}

	/**
	 * The regular work cycle for this Desk
	 * @param int $timeout Time to wait for receiving Bucks, processing Bucks, or receiving Results.
	 * @return bool true to continue ticking, false to stop
	 */
	public function tick($timeout = 0) {
		$this->continue = true;
		$this->receiveBuck($timeout);
		$this->processBucks($timeout);
		$this->receiveResults($timeout);
		return $this->continue;

	}

}
