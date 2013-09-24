<? namespace truman;

use truman\interfaces\LoggerContext;

class Buck implements \JsonSerializable, LoggerContext {

	const CALLABLE_NOOP   = '__NOOP__';

	const CHANNEL_DEFAULT = 'default';

	const LOGGER_TYPE                    = 'BUCK';
	const LOGGER_EVENT_INIT              = 'INIT';
	const LOGGER_EVENT_SEND_START        = 'SEND_START';
	const LOGGER_EVENT_SEND_COMPLETE     = 'SEND_COMPLETE';
	const LOGGER_EVENT_SEND_ERROR        = 'SEND_ERROR';
	const LOGGER_EVENT_RECEIVED          = 'RECEIVED';
	const LOGGER_EVENT_ENQUEUED          = 'ENQUEUED';
	const LOGGER_EVENT_DEQUEUED          = 'DEQUEUED';
	const LOGGER_EVENT_DEDUPED           = 'DEDUPLICATED';
	const LOGGER_EVENT_DELEGATE_START    = 'DELEGATE_START';
	const LOGGER_EVENT_DELEGATE_COMPLETE = 'DELEGATE_COMPLETE';
	const LOGGER_EVENT_DELEGATE_ERROR    = 'DELEGATE_ERROR';
	const LOGGER_EVENT_EXECUTE_START     = 'EXECUTE_START';
	const LOGGER_EVENT_EXECUTE_ERROR     = 'EXECUTE_ERROR';
	const LOGGER_EVENT_EXECUTE_COMPLETE  = 'EXECUTE_COMPLETE';

	const PRIORITY_LOW     = 1024;
	const PRIORITY_MEDIUM  = 2048;
	const PRIORITY_HIGH    = 4096;
	const PRIORITY_URGENT  = PHP_INT_MAX;

	private $uuid;
	private $logger;
	private $priority;
	private $callable;
	private $channel;
	private $context;
	private $routing_desk_id;
	private $client_signature;
	private $kwargs;
	private $args;

	private static $_DEFAULT_OPTIONS = array(
		'priority'         => self::PRIORITY_MEDIUM,
		'channel'          => self::CHANNEL_DEFAULT,
		'allow_closures'   => false,
		'client_signature' => '',
		'logger_options'   => [],
		'context'          => null,
	);

	public function __construct($callable = self::CALLABLE_NOOP, array $args = [], array $options = []) {

		$options += self::$_DEFAULT_OPTIONS;

		if (!is_callable($callable, true, $callable_name))
			throw new Exception('Invalid callable argument', [
				'context' => $this,
				'method'  => __METHOD__
			]);

		$this->args   = $args;
		$this->kwargs = Util::isKeyedArray($args);

		$this->callable = $options['allow_closures'] ? $callable : $callable_name;
		$this->priority = (int) $options['priority'];
		$this->uuid     = $this->calculateUUID();

		$this->client_signature = $options['client_signature'];
		$this->channel = $options['channel'];

		// start with user-supplied context or the thread's context; use the seed if we can't find one.
		if (is_null($context = $options['context']) && is_null($context = self::getThreadContext(getmypid())))
			$this->context = $this->calculateSeed();
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

		$this->logger = new Logger($this, $options['logger_options']);

		$this->logger->log(self::LOGGER_EVENT_INIT, [
			'callable' => $this->callable,
			'args'     => $this->args,
			'priority' => $this->priority,
			'channel'  => $this->channel
		]);

	}

	public function getLogger() {
		return $this->logger;
	}

	public function getLoggerId() {
		return $this->getUUID();
	}

	public function getLoggerType() {
		return self::LOGGER_TYPE;
	}

	public function __toString() {
		$uuid = $this->getUUID();
		return "Buck<{$uuid}>";
	}

	public function jsonSerialize() {
		return $this->__toString();
	}

	public function calculateSeed() {
		$args = serialize($this->args);
		return "{$this->callable}::{$args}";
	}

	private function calculateUUID() {
		return md5($this->calculateSeed());
	}

	public function getChannel() {
		return $this->channel;
	}

	public function getContext() {
		return $this->context;
	}

	public function getClient() {
		if (strlen($sig = $this->getClientSignature()))
			return Client::fromSignature($sig);
		return null;
	}

	public function getClientSignature() {
		return $this->client_signature;
	}

	public function getRoutingDeskId() {
		return $this->routing_desk_id;
	}

	public function getPriority() {
		return $this->priority;
	}

	public function getUUID() {
		return $this->uuid;
	}

	public function hasClientSignature() {
		return strlen($this->getClientSignature()) > 0;
	}

	public function isNoop() {
		return $this->callable === self::CALLABLE_NOOP;
	}

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

	public function setRoutingDeskId($routing_desk_id) {
		$this->routing_desk_id = $routing_desk_id;
	}

	private static $contexts = [];

	public static function setThreadContext($thread_id, $context) {
		self::$contexts[$thread_id] = $context;
	}

	public static function unsetThreadContext($thread_id) {
		unset(self::$contexts[$thread_id]);
	}

	public static function getThreadContext($thread_id) {
		return isset(self::$contexts[$thread_id]) ?
			self::$contexts[$thread_id] : null;
	}

}