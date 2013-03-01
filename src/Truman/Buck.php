<?

class Truman_Buck {

	const PRIORITY_LOW    = 4096;
	const PRIORITY_MEDIUM = 2048;
	const PRIORITY_HIGH   = 1024;
	const PRIORITY_URGENT = 0;

	private $uuid;
	private $priority;
	private $callable;
	private $args   = array();
	private $kwargs = array();

	private static $_DEFAULT_OPTIONS = array(
		'priority' => self::PRIORITY_MEDIUM,
		'dedupe'   => false
	);

	public function __construct($callable, array $args = array(), array $options = array()) {

		$options += self::$_DEFAULT_OPTIONS;

		if (!is_callable($callable, true, $callable_name))
			Truman_Exception::throwNew($this, 'Invalid callable passed into '.__METHOD__);

		$this->callable = $callable_name;
		$this->priority = (int) $options['priority'];
		$this->uuid     = $this->calculateUUID($options['dedupe']);

		foreach ($args as $key => $value) {
			if (is_int($key))
				$this->args[$key] = $value;
			else
				$this->kwargs[$key] = $value;
		}

	}

	private function calculateUUID($dedupe = false) {
		$seed  = $this->callable;
		$seed .= implode(',', $this->args);
		$seed .= implode(',', $this->kwargs);
		$seed .= $dedupe ? '' : uniqid(microtime(1), true);
		return md5($seed);
	}

	public function getPriority() {
		return $this->priority;
	}

	public function getUUID() {
		return $this->uuid;
	}

	public function invoke() {

		try {

			$args = array();
			$instance = null;

			if (strpos($this->callable, '::') !== false) {
				list($class_name, $method_name) = explode('::', $this->callable);
				$class = new ReflectionClass($class_name);
				$instance = $class->newInstanceWithoutConstructor();
				$function = $class->getMethod($method_name);
			} else {
				$function = new ReflectionFunction($this->callable);
			}

			if ($function->getNumberOfParameters() > 0) {
				$params = $function->getParameters();
				foreach ($params as $param) {
					if (array_key_exists($key = $param->getName(), $this->kwargs))
						$args[] = $this->kwargs[$key];
					else if (array_key_exists($key = $param->getPosition(), $this->args))
						$args[] = $this->args[$key];
					else
						$args[] = null;
				}
			}

			if ($instance)
				return $function->invokeArgs($instance, $args);
			else
				return $function->invokeArgs($args);

		} catch(ReflectionException $ex) {

			Truman_Exception::throwNew($this, "Unable to invoke '{$this->callable}'", $ex);

		}

	}

}