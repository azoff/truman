<?

class Truman_Buck {

	private $callable;
	private $args   = array();
	private $kwargs = array();

	public function __construct($callable, array $args = array()) {
		
		if (!is_callable($callable, true, $callable_name))
			Truman_Exception::throwNew($this, 'Invalid callable passed into '.__METHOD__);

		$this->callable = $callable_name;
		
		foreach ($args as $key => $value) {
			if (is_int($key))
				$this->args[$key] = $value;
			else
				$this->kwargs[$key] = $value;
		}

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