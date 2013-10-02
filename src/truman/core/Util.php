<? namespace truman\core;

class Util {

	/**
	 * Creates a temporary fifo pipe on the filesystem
	 * @param int $mode The file permissions for the created fifo pipe
	 * @return string The path to the fifo pipe
	 */
	public static function tempFifo($mode = 0777) {
		$path = self::tempFilePath('temp_fifo_', 'pipe', $mode);
		posix_mkfifo($path, $mode);
		return $path;
	}

	/**
	 * Creates a temporary file on the filesystem
	 * @param string $prefix A unique prefix to add to the front of the filesystem
	 * @param string $extension The file extension to use on the created file
	 * @param string $dir The directory to create the temporary file in
	 * @return string the path to the temporary file
	 */
	public static function tempFilePath($prefix, $extension, $dir = '/tmp') {
		$path = tempnam($dir, $prefix);
		rename($path, $path = "{$path}.{$extension}");
		return $path;
	}

	/**
	 * Creates a temporary PHP file on the filesystem
	 * @param string $content PHP code to go in the file
	 * @return string The path to the temporary PHP file
	 */
	public static function tempPhpFile($content) {
		$path = self::tempFilePath('temp_php_', 'php');
		file_put_contents($path, "<?php {$content} ?>");
		return $path;
	}

	/**
	 * Writes a serializeable PHP object to a file stream
	 * @param mixed $object A serializable PHP object
	 * @param resource $stream The stream to write to
	 * @return mixed $object
	 */
	public static function writeObjectToStream($object, $stream) {
		$data     = self::streamDataEncode($object);
		$expected = strlen($data);
		$actual   = 0;
		do $actual += fputs($stream, $data);
		while ($actual !== $expected);
		return $object;
	}

	/**
	 * Reads a serialized object from a stream
	 * @param resource $stream The stream to read from
	 * @return mixed|null The unserialized object
	 */
	public static function readObjectFromStream($stream) {
		$message = fgets($stream);
		return self::streamDataDecode($message);
	}

	/**
	 * Checks to see if an array is a keyed (associative) array
	 * @param array $to_check The array to check
	 * @return bool true if keyed, false if regular
	 */
	public static function isKeyedArray(array $to_check) {
		return (bool) array_filter(array_keys($to_check), 'is_string');
	}

	/**
	 * Checks if an IP address or URL points to the local host
	 * @param string $host_address The address to check
	 * @return bool true if the address is local, otherwise false
	 */
	public static function isLocalAddress($host_address) {

		// empty strings are not checked
		if (!strlen($host_address = trim($host_address)))
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

	/**
	 * Dumps an object to the error log
	 * @param mixed $obj The object to dump
	 */
	public static function dump($obj) {
		error_log(print_r($obj, true));
	}

	/**
	 * Extracts arguments from the command line
	 * @param array $argv The complete list of arguments passed into the script
	 * @return array The actual list of arguments passed into the script
	 */
	public static function getArgs(array $argv) {
		return array_filter(array_slice($argv, 1), function($value){
			return $value{0} !== '-';
		});
	}

	/**
	 * Extract options from the command line
	 * @param array $option_keys The options to try and extract from the command line
	 * @param array $default_options A list of default options the script can use if no keys are provided
	 * @return array The list of provided options
	 */
	public static function getOptions(array $option_keys = null, array $default_options = []) {
		if (is_null($option_keys))
			$option_keys = array_keys($default_options);
		$key_to_opt  = function($key){ return "{$key}::"; };
		$option_keys = array_map($key_to_opt, $option_keys);
		return getopt('', $option_keys) ?: [];
	}

	/**
	 * Gets the memory usage allocated above the base memory for the script
	 * @return int The allocated memory usage, in bytes
	 */
	public static function getMemoryUsage() {
		return memory_get_usage(true) - TRUMAN_BASE_MEMORY;
	}

	/**
	 * Decodes stream data from encoded form into a PHP object
	 * @param string $data The stream data to decode
	 * @param string $delimiter The EOM delimiter
	 * @return mixed|null The decoded object from stream data
	 */
	public static function streamDataDecode($data, $delimiter = PHP_EOL) {
		return unserialize(base64_decode(rtrim($data, $delimiter)));
	}

	/**
	 * Encodes objects from PHP form to encoded stream data
	 * @param mixed $data The PHP object to encode
	 * @param string $delimiter The EOM delimiter
	 * @return string The encoded stream data
	 */
	public static function streamDataEncode($data, $delimiter = PHP_EOL) {
		return base64_encode(serialize($data)) . $delimiter;
	}

	/**
	 * Gets regular stream descriptors for working with PHP processes
	 * @return array
	 */
	public static function getStreamDescriptors() {
		return [
			['pipe', 'r'],
			['pipe', 'w'],
			['pipe', 'w']
		];
	}

	/**
	 * Takes an arbitrary value and normalizes it into a Socket specification
	 * @param int|string|array $socket_spec valid values include:
	 *  - A numeric port number
	 *  - A URL containing an IP or domain, and port number
	 *  - A keyed array, compatible with the output of PHP's parse_url() method
	 * @param bool $default_host A default host to use if none can be extracted
	 * @param bool $default_port A default port to use if none can be extracted
	 * @return array A normalized socket specification
	 * @throws Exception If the specification is not a valid value, or if the host/port could not be extracted.
	 */
	public static function normalizeSocketSpec($socket_spec, $default_host = false, $default_port = false) {

		if (is_numeric($socket_spec))
			$normalized_spec = [Socket::SPEC_PORT => $socket_spec];
		else if (is_string($socket_spec))
			$normalized_spec = parse_url($socket_spec);
		else if (is_array($socket_spec))
			$normalized_spec = $socket_spec;
		else
			throw new Exception('Socket specification must be an int, string, or array', [
				'specification' => $socket_spec,
				'method'        => __METHOD__
			]);

		if (!isset($normalized_spec[Socket::SPEC_HOST])) {
			if ($default_host === false)
				throw new Exception('Socket specification is missing host, and no default exists', [
					'specification' => $socket_spec,
					'method'        => __METHOD__
				]);
			$normalized_spec[Socket::SPEC_HOST] = $default_host;
		}

		if (!isset($normalized_spec[Socket::SPEC_PORT])) {
			if ($default_host === false)
				throw new Exception('Socket specification is missing port, and no default exists', [
					'specification' => $socket_spec,
					'method'        => __METHOD__
				]);
			$normalized_spec[Socket::SPEC_PORT] = $default_port;
		}

		return $normalized_spec;

	}

	/**
	 * Closes a socket connection
	 * @param resource $socket The socket to close
	 * @return bool true if the socket was closed, otherwise false
	 */
	public static function socketClose($socket) {
		if (!is_resource($socket)) return true;
		\socket_set_block($socket);
		\socket_set_option($socket, SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 1]);
		return \socket_close($socket) === false ? false : true;
	}

	/**
	 * Runs a handler on script shutdown or abort
	 * @param callable $handler The handler to call
	 */
	public static function onShutdown($handler) {
		pcntl_signal(SIGINT, $handler);
		pcntl_signal(SIGTERM, $handler);
		register_shutdown_function($handler);
	}

}