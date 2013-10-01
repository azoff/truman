<? namespace truman\core;

use truman\interfaces\LoggerContext;

/**
 * Class Buck Encapsulates a method to be executed by a remote Desk
 * @package truman\core
 */
class Buck implements \JsonSerializable, LoggerContext {

	/**
	 * A special callable that does nothing, default value for new Bucks
	 */
	const CALLABLE_NOOP   = '__NOOP__';

	const CHANNEL_DEFAULT = 'default';
	const LOGGER_TYPE     = 'BUCK';

	/**
	 * Occurs when a Buck is first created
	 */
	const LOGGER_EVENT_INIT              = 'INIT';

	/**
	 * Occurs when a Client starts to send a Buck to a Desk Socket
	 */
	const LOGGER_EVENT_SEND_START        = 'SEND_START';

	/**
	 * Occurs when a Client successfully sends a Buck to a Desk Socket
	 */
	const LOGGER_EVENT_SEND_COMPLETE     = 'SEND_COMPLETE';

	/**
	 * Occurs when a Client can not send a Buck to a Desk Socket
	 */
	const LOGGER_EVENT_SEND_ERROR        = 'SEND_ERROR';

	/**
	 * Occurs when a Desk receives a Buck over the network
	 */
	const LOGGER_EVENT_RECEIVED          = 'RECEIVED';

	/**
	 * Occurs when a Desk adds a Buck to its priority queue
	 */
	const LOGGER_EVENT_ENQUEUED          = 'ENQUEUED';

	/**
	 * Occurs when a Desk does not add a Buck to its queue, because the Buck already exists
	 */
	const LOGGER_EVENT_DEDUPED           = 'DEDUPLICATED';

	/**
	 * Occurs when a Desk removes a Buck from its priority queue
	 */
	const LOGGER_EVENT_DEQUEUED          = 'DEQUEUED';

	/**
	 * Occurs when a Desk sends a Buck to one of its Drawers for processing
	 */
	const LOGGER_EVENT_DELEGATE_START    = 'DELEGATE_START';

	/**
	 * Occurs when a Desk receives a Buck execution Result from one of its Drawers
	 */
	const LOGGER_EVENT_DELEGATE_COMPLETE = 'DELEGATE_COMPLETE';

	/**
	 * Occurs when a Desk receives an error from one of its Drawers
	 */
	const LOGGER_EVENT_DELEGATE_ERROR    = 'DELEGATE_ERROR';

	/**
	 * Occurs when a Drawer starts to execute a Buck
	 */
	const LOGGER_EVENT_EXECUTE_START     = 'EXECUTE_START';

	/**
	 * Occurs when a Drawer experiences an error executing a Buck
	 */
	const LOGGER_EVENT_EXECUTE_ERROR     = 'EXECUTE_ERROR';

	/**
	 * Occurs when a Drawer completes execution of a Buck
	 */
	const LOGGER_EVENT_EXECUTE_COMPLETE  = 'EXECUTE_COMPLETE';

	/**
	 * Low priority Bucks should be created with this priority
	 */
	const PRIORITY_LOW     = 1024;

	/**
	 * Medium priority Bucks should be created with this priority
	 */
	const PRIORITY_MEDIUM  = 2048;

	/**
	 * High priority Bucks should be created with this priority
	 */
	const PRIORITY_HIGH    = 4096;

	/**
	 * Only the most important Bucks, such as Notifications, should be created with this priority
	 */
	const PRIORITY_URGENT  = PHP_INT_MAX;

	/**
	 * The priority of a Buck in a Desk's priority queue. Use the PRIORITY_* options
	 */
	const OPTION_PRIORITY = 'priority';

	/**
	 * The channel the Buck should be sent to. Channels are clusters of Desks, as defined by Clients
	 */
	const OPTION_CHANNEL = 'channel';

	/**
	 * Designates whether or not closures are allowed as the callable for a Buck
	 */
	const OPTION_ALLOW_CLOSURES = 'allow_closures';

	/**
	 * Any options to be passed into the Buck's Logger::__construct method
	 */
	const OPTION_LOGGER_OPTS = 'logger_options';

	/**
	 * The thread context that this Buck, and any subsequent child Buck are executed in
	 */
	const OPTION_CONTEXT = 'context';

	/**
	 * The memory limit, in bytes, that this Buck is allowed to operate under
	 */
	const OPTION_MEMORY_LIMIT = 'memory_limit';

	/**
	 * The time limit, in seconds, that this buck is allowed to operate under
	 */
	const OPTION_TIME_LIMIT = 'time_limit';

	private $uuid;
	protected $logger;
	private $priority;
	private $time_limit;
	private $memory_limit;
	private $callable;
	private $channel;
	private $context;
	private $routing_desk_id;
	private $kwargs;
	private $args;

	private static $_DEFAULT_OPTIONS = [
		self::OPTION_PRIORITY       => self::PRIORITY_MEDIUM,
		self::OPTION_CHANNEL        => self::CHANNEL_DEFAULT,
		self::OPTION_ALLOW_CLOSURES => false,
		self::OPTION_LOGGER_OPTS    => [],
		self::OPTION_CONTEXT        => null,
		self::OPTION_MEMORY_LIMIT   => 134217728, // 128MB
		self::OPTION_TIME_LIMIT     => 60,
	];

	/**
	 * Creates a new Buck instance
	 * @param callable|string $callable $callable The remote method to execute
	 * @param array $args Optional args to pass to the method. Can be a keyed array for named arguments.
	 * @param array $options Optional settings for the Buck. See Buck::$_DEFAULT_OPTIONS
	 * @throws Exception if the callable argument or context options are invalid
	 */
	public function __construct($callable = self::CALLABLE_NOOP, array $args = [], array $options = []) {

		$options += self::$_DEFAULT_OPTIONS;

		if (!is_callable($callable, true, $callable_name))
			throw new Exception('Invalid callable argument', [
				'context' => $this,
				'method'  => __METHOD__
			]);

		$this->args   = $args;
		$this->kwargs = Util::isKeyedArray($args);

		$this->callable = $options[self::OPTION_ALLOW_CLOSURES] ? $callable : $callable_name;
		$this->priority = (int) $options[self::OPTION_PRIORITY];
		$this->uuid     = $this->calculateUUID();

		$this->channel = $options[self::OPTION_CHANNEL];

		$this->time_limit   = $options['time_limit'];
		$this->memory_limit = $options['memory_limit'];

		// start with user-supplied context or the thread's context; use the seed if we can't find one.
		if (is_null($context = $options[self::OPTION_CONTEXT])
			&& is_null($context = self::getThreadContext(getmypid())))
			$this->context = $this->getUUID();
		// if we found a context string, make sure it is valid
		else if (is_string($context) && strlen($context = trim($context)))
			$this->context = $context;
		// if we found a context Buck, use the buck's context string
		else if ($context instanceof Buck)
			$this->context = $context->getContext();
		// empty string, or some other data type
		else
			throw new Exception('Invalid context option', [
				'context' => $this,
				'method'  => __METHOD__
			]);

		$this->logger = new Logger($this, $options[self::OPTION_LOGGER_OPTS]);

		$this->logInit([
			'callable' => $this->callable,
			'args'     => $this->args,
			'options'  => $options
		]);

	}

	/**
	 * @inheritdoc
	 */
	public function getLogger() {
		return $this->logger;
	}

	/**
	 * @inheritdoc
	 */
	public function getLoggerId() {
		return $this->getUUID();
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
	public function __toString() {
		$uuid = $this->getUUID();
		return "Buck<{$uuid}>";
	}

	/**
	 * @inheritdoc
	 */
	public function jsonSerialize() {
		return $this->__toString();
	}

	/**
	 * Logs the INIT event for this Buck
	 * @param array $options
	 */
	protected function logInit(array $options) {
		$this->logger->log(self::LOGGER_EVENT_INIT, $options);
	}

	/**
	 * Gets the seed used to deduplicate this Buck with other Bucks
	 * @return string
	 */
	public function calculateSeed() {
		$args = serialize($this->args);
		return "{$this->callable}::{$args}";
	}

	/**
	 * Gets the unique identifier for this Buck
	 * @return string
	 */
	private function calculateUUID() {
		return md5($this->calculateSeed());
	}

	/**
	 * Gets the channel (named cluster of Desks) to execute this Buck in
	 * @return string
	 */
	public function getChannel() {
		return $this->channel;
	}

	/**
	 * Get the execution context for this Buck, defaults to the UUID should one not be defined
	 * @return mixed
	 */
	public function getContext() {
		return $this->context;
	}

	/**
	 * Gets the memory limit (in bytes) this Buck is allowed to execute under
	 * @return int
	 */
	public function getMemoryLimit() {
		return $this->memory_limit;
	}

	/**
	 * Gets the time limit (in seconds) this Buck is allowed to execute under
	 * @return int
	 */
	public function getTimeLimit() {
		return $this->time_limit;
	}

	/**
	 * If this Buck has been routed by a Desk, this method returns the routing Desk's ID
	 * @return string|null
	 */
	public function getRoutingDeskId() {
		return $this->routing_desk_id;
	}

	/**
	 * Sets the Desk that routes this Buck to another Desk. Used to prevent circular references.
	 * @param Desk $routing_desk The Desk that routes this Buck to another
	 */
	public function setRoutingDesk(Desk $routing_desk) {
		$this->routing_desk_id = $routing_desk->getId();
	}

	/**
	 * Gets the priority of this Buck in a Desk's priority queue
	 * @return int
	 */
	public function getPriority() {
		return $this->priority;
	}

	/**
	 * Gets the unique identifier for this Buck
	 * @return string
	 */
	public function getUUID() {
		return $this->uuid;
	}

	/**
	 * Returns whether or not this method actually does any work
	 * @return bool
	 */
	public function isNoop() {
		return $this->callable === self::CALLABLE_NOOP;
	}

	/**
	 * Attempts to execute this Buck
	 * @return mixed|null The result of executing the callable within the Buck
	 * @throws Exception If unable to invoke the Buck's callable
	 */
	public function invoke() {

		if ($this->isNoop())
			return null;

		try {

			if (is_array($this->callable)) {
				$class  = $this->callable[0];
				$method = $this->callable[1];
				$function = new \ReflectionMethod($class, $method);
			} else if (strpos($this->callable, '::') !== false) {
				list($class, $method) = explode('::', $this->callable, 2);
				$function = new \ReflectionMethod($class, $method);
			} else {
				$function = new \ReflectionFunction($this->callable);
			}

			$args = array();

			if ($function->getNumberOfParameters() > 0) {
				if ($this->kwargs) {
					foreach ($function->getParameters() as $parameter) {
						$name     = $parameter->getName();
						$position = $parameter->getPosition();
						if (array_key_exists($name, $this->args))
							$args[$position] = $this->args[$name];
					}
				} else {
					$args = $this->args;
				}
			}

			if (isset($class))
				return $function->invokeArgs(null, $args);

			return $function->invokeArgs($args) ;

		} catch(\ReflectionException $ex) {

			throw new Exception('Unable to invoke callable', [
				'context'  => $this,
				'callable' => $this->callable,
				'method'   => __METHOD__
			], $ex);

		}

	}

	private static $contexts = [];

	/**
	 * Sets the context of a particular thread PID to some string value
	 * @param int $thread_id The PID of the thread to set the context for
	 * @param string $context The context to set for the thread
	 */
	public static function setThreadContext($thread_id, $context) {
		self::$contexts[$thread_id] = $context;
	}

	/**
	 * Removes the context for a particular thread PID
	 * @param int $thread_id The PID of the thread to remove the context from
	 */
	public static function unsetThreadContext($thread_id) {
		unset(self::$contexts[$thread_id]);
	}

	/**
	 * Gets the thread context given a thread's PID
	 * @param int $thread_id The PID of the thread to get the context of
	 * @return null|string The thread's context
	 */
	public static function getThreadContext($thread_id) {
		return isset(self::$contexts[$thread_id]) ?
			self::$contexts[$thread_id] : null;
	}

}