<?

class TrumanUtil {

	public static function isKeyedArray(array $to_check) {
		return (bool) array_filter(array_keys($to_check), 'is_string');
	}

	public static function dump($obj) {
		error_log(print_r($obj, true));
	}

	public static function trace() {
		self::log(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
	}

}