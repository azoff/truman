<?

class Truman_Exception extends Exception {

	public static function throw_new($context, $msg = '', Exception $inner_exception = null) {
		$class = get_class($context);
		$code  = is_null($inner_exception) ? 0 : $inner_exception->getCode();
		$msg   = strlen($msg) ? "{$class}: {$msg}" : "{$class}: Undefined Error";
		throw new Truman_Exception($msg, $code, $inner_exception);
	}

}