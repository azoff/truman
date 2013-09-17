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

	public static function sendPhpObjectToStream($object, $stream) {
		$data     = serialize($object) . "\n";
		$expected = strlen($data);
		$actual   = 0;
		do $actual += fputs($stream, $data);
		while ($actual !== $expected);
		return $object;
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

	public static function trace() {
		self::dump(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
	}

}