<?

class TrumanUtil {

	public static function isKeyedArray(array $to_check) {
		return (bool) array_filter(array_keys($to_check), 'is_string');
	}

}