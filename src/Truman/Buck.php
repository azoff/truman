<?

class Truman_Buck {

	private $callable;
	private $args   = array();
	private $kwargs = array();

	public function __construct($callable, array $args = array(), $strict_mode = false) {
		
		if (!is_callable($callable, !$strict_mode, $callable_name))
			Truman_Exception::throw_new($this, 'Invalid callable passed into '.__METHOD__);

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

			$function = new ReflectionFunction($this->callable);
			$args     = array();
			
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

			return $function->invokeArgs($args);	
		
		} catch(ReflectionException $ex) {

			Truman_Exception::throw_new($this, "Unable to invoke '{$this->callable}'", $ex);

		}

	}

}