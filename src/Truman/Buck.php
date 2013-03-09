<?

class Truman_Buck {

	const CALLABLE_NOOP = '__NOOP__';

	const CHANNEL_DEFAULT = 'default';

	const PRIORITY_LOW     = 4096;
	const PRIORITY_MEDIUM  = 2048;
	const PRIORITY_HIGH    = 1024;
	const PRIORITY_URGENT  = 0;

	private $uuid;
	private $priority;
	private $callable;
	private $channel;
	private $client_signature;
	private $kwargs;
	private $args;

	private static $_DEFAULT_OPTIONS = array(
		'priority'         => self::PRIORITY_MEDIUM,
		'channel'          => self::CHANNEL_DEFAULT,
		'allow_closures'   => false,
		'client_signature' => ''
	);

	public function __construct($callable = self::CALLABLE_NOOP, array $args = array(), array $options = array()) {

		$options += self::$_DEFAULT_OPTIONS;

		if (!is_callable($callable, true, $callable_name))
			Truman_Exception::throwNew($this, 'Invalid callable passed into '.__METHOD__);

		$this->args   = $args;
		$this->kwargs = (bool) array_filter(array_keys($args), 'is_string');

		$this->callable   = $options['allow_closures'] ? $callable : $callable_name;
		$this->priority   = (int) $options['priority'];
		$this->uuid       = $this->calculateUUID();

		$this->client_signature = $options['client_signature'];
		$this->channel = $options['channel'];

	}

	public function __toString() {
		$uuid = $this->getUUID();
		return __CLASS__."<{$uuid}>";
	}

	private function calculateUUID() {
		$seed  = $this->callable;
		$seed .= implode(',', $this->args);
		return md5($seed);
	}

	public function getChannel() {
		return $this->channel;
	}

	public function getClient() {
		if (strlen($sig = $this->getClientSignature()))
			return Truman_Client::fromSignature($sig);
		return null;
	}

	public function getClientSignature() {
		return $this->client_signature;
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
				$function = new ReflectionMethod($class, $method);
			} else if (strpos($this->callable, '::') !== false) {
				list($class, $method) = explode('::', $this->callable, 2);
				$function = new ReflectionMethod($class, $method);
			} else {
				$function = new ReflectionFunction($this->callable);
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

		} catch(ReflectionException $ex) {

			Truman_Exception::throwNew($this, "Unable to invoke '{$this->callable}'", $ex);

		}

		return null;

	}

}