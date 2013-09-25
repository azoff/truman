<? namespace truman;

class Util {

	public static function tempFifo($mode = 0777) {
		$path = self::tempFilePath('temp_fifo_', 'pipe', $mode);
		posix_mkfifo($path, $mode);
		return $path;
	}

	public static function tempFilePath($prefix, $extension, $dir = '/tmp') {
		$path = tempnam($dir, $prefix);
		rename($path, $path = "{$path}.{$extension}");
		return $path;
	}

	public static function tempPhpFile($content) {
		$path = self::tempFilePath('temp_php_', 'php');
		file_put_contents($path, "<?php {$content} ?>");
		return $path;
	}

	public static function writeObjectToStream($object, $stream) {
		$data     = self::streamDataEncode($object);
		$expected = strlen($data);
		$actual   = 0;
		do $actual += fputs($stream, $data);
		while ($actual !== $expected);
		return $object;
	}

	public static function readObjectFromStream($stream) {
		$message = fgets($stream);
		return self::streamDataDecode($message);
	}

	public static function isKeyedArray(array $to_check) {
		return (bool) array_filter(array_keys($to_check), 'is_string');
	}

	public static function isLocalAddress($host_address) {

		// empty strings are not checked
		if (!strlen($needle = trim($host_address)))
			return false;

		// convert all host names to IP addresses
		if (!($ip_address = gethostbyname($host_address)))
			return false; // unable to convert to an IP address

		// return the obvious host matches early
		if ($ip_address === '0.0.0.0' || $ip_address === '127.0.0.1' || $ip_address === '::1')
			return true;

		// return local hostname resolution early
		if ($ip_address === gethostbyname(gethostname()))
			return true;

		return false;

	}

	public static function dump($obj) {
		error_log(print_r($obj, true));
	}

	public static function getArgs($argv) {
		return array_filter(array_slice($argv, 1), function($value){
			return $value{0} !== '-';
		});
	}

	public static function getOptions(array $opts) {
		$longopts  = array_values($opts);
		$shortopts = implode('', array_keys($opts));
		return getopt($shortopts, $longopts);
	}

	public static function getMemoryUsage() {
		return memory_get_usage(true) - TRUMAN_BASE_MEMORY;
	}

	public static function trace() {
		self::dump(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
	}

	public static function streamDataDecode($data, $delimeter = PHP_EOL) {
		return unserialize(base64_decode(rtrim($data, $delimeter)));
	}

	public static function streamDataEncode($data, $delimeter = PHP_EOL) {
		return base64_encode(serialize($data)) . $delimeter;
	}

	public static function getStreamDescriptors() {
		return [
			['pipe', 'r'],
			['pipe', 'w'],
			['pipe', 'w']
		];
	}

}