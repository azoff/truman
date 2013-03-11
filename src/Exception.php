<?

class TrumanException extends Exception {

	public static function throwNew($context, $msg = '', Exception $inner_exception = null) {
		$class = is_string($context) ? $context : get_class($context);
		$code  = is_null($inner_exception) ? 0 : $inner_exception->getCode();
		$msg   = strlen($msg) ? "({$class}) {$msg}" : "({$class})";
		throw new TrumanException($msg, $code, $inner_exception);
	}

}