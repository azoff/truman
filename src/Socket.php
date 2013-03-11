<?

class TrumanSocket {

	const MODE_SERVER = 2;
	const MODE_CLIENT = 4;

	private static $_DEFAULT_OPTIONS = array(
		'host'        => 0, // Bind to all incoming addresses
		'port'        => 0, // Self-assign a port number
		'buffer_size' => 2048, // Chunk size for socket reads
		'socket_domain' => AF_INET, // IPv4 Internet based protocols
		'socket_type' => SOCK_STREAM, // Provides sequenced, reliable, full-duplex, connection-based byte streams
		'socket_protocol' => SOL_TCP, // A reliable, connection based, stream oriented, full duplex protocol
		'size_limit' => 2048, // The maximum message size that can be sent or received
		'reuse_port' => true, // Marks the socket as reusable
		'max_connections' => null, // Number of connections to allow on the socket, null is system dependent
		'nonblocking' => true, // Does not block on accept()
		'force_mode' => 0, // Forces client mode for the socket
		'msg_delimiter' => PHP_EOL // Splits message boundaries
	);

	private $mode;
	private $options;
	private $connection;
	private $sockets = array();

	public function __construct(array $options = array()) {

		$this->options = $options + self::$_DEFAULT_OPTIONS;

		$this->connection = @socket_create(
			$this->options['socket_domain'],
			$this->options['socket_type'],
			$this->options['socket_protocol']
		);

		if ($this->connection === false)
			$this->throwError('Unable to create a socket resource');

		if ($this->options['reuse_port']) {

			$option_set = @socket_set_option(
				$this->connection,
				SOL_SOCKET,
				SO_REUSEADDR,
				TRUE
			);

			if ($option_set === false)
				$this->throwError('Unable to mark socket as reusable', $this->connection);

		}

		$force_mode = $this->options['force_mode'];

		// server mode (local)
		if (self::isLocal($this->getHostSpec()) && $force_mode !== self::MODE_CLIENT) {

			if ($this->options['nonblocking'])
				if (@socket_set_nonblock($this->connection) === false);
					$this->throwError('Unable to mark socket as non-blocking', $this->connection);

			$bound = @socket_bind(
				$this->connection,
				$this->options['host'],
				$this->options['port']
			);

			if ($bound === false)
				$this->throwError("Unable to bind to {$this}", $this->connection);

			$listening = @socket_listen(
				$this->connection,
				$this->options['max_connections']
			);

			if ($listening === false)
				$this->throwError("Unable to listen to {$this}", $this->connection);

			$this->mode = self::MODE_SERVER;

		// client mode (remote)
		} else {

			$connected = @socket_connect(
				$this->connection,
				$this->options['host'],
				$this->options['port']
			);

			if ($connected === false)
				$this->throwError("Unable to connect to {$this}", $this->connection);

			$this->mode = self::MODE_CLIENT;

		}

		$this->open($this->connection);

	}

	public function __destruct() {
		$this->close($this->connection);
	}

	public function __toString() {
		$spec = $this->getHostSpec();
		return __CLASS__."<{$spec['host']}:{$spec['port']}>";
	}

	public function close($socket) {

		if (!is_resource($socket))
			return false;

		$key = (string) $socket;
		if (!array_key_exists($key, $this->sockets))
			return false;

		@socket_close($socket);

		unset($this->sockets[$key]);

		return true;

	}

	public function getHostSpec() {
		$host = $port = null;
		if ($this->sockets)
			socket_getpeername($this->connection, $host, $port);
		return array(
			'host' => $host ?: $this->options['host'],
			'port' => $port ?: $this->options['port']
		);
	}

	public function open($socket) {

		if (!is_resource($socket))
			return false;

		$key = (string) $socket;
		if (array_key_exists($key, $this->sockets))
			return false;

		$this->sockets[$key] = $socket;

		return true;

	}

	public function receive($callback = null, $timeout = 0) {

		$ready = @socket_select($read = $this->sockets, $i, $j, $timeout);

		if ($ready === false)
			$this->throwError('Unable to detect socket changes');

		if ($ready <= 0)
			return false;

		$continue = false;
		$read_limit = $this->options['size_limit'];

		foreach ($read as $socket) {

			// this happens when a new connection arrives on our socket
			if ($socket === $this->connection && $this->mode !== self::MODE_CLIENT) {

				$continue = $this->open(@socket_accept($this->connection));

			} else {

				$message = @socket_read($socket, $read_limit, PHP_NORMAL_READ);

				// close out sockets that don't provide any data
				if ($message === false) {
					$this->close($socket);

				// otherwise delegate interpretation of the data to the caller
				} else {
					$message = rtrim($message, $this->options['msg_delimiter']);
					if (is_callable($callback))
						return call_user_func($callback, $message, $this, $socket) !== false;
					else
						return $message;
				}

			}
		}

		return $continue;

	}

	public function send($message, $socket = null, $timeout = 0) {

		$write = is_resource($socket) ? array($socket) : array($this->connection);

		$ready = @socket_select($i, $write, $j, $timeout);

		if ($ready === false)
			$this->throwError('Unable to detect socket changes');

		if ($ready <= 0) return 0;

		$delimeter = $this->options['msg_delimiter'];
		$message   = rtrim($message, $delimeter) . $delimeter;

		$expected_bytes = strlen($message);
		$size_limit = $this->options['size_limit'];

		if ($expected_bytes > $size_limit)
			TrumanException::throwNew($this, "Message size greater than limit of {$size_limit} bytes");

		$actual_bytes = @socket_write($write[0], $message, $expected_bytes);

		if ($actual_bytes === false)
			$this->throwError('Unable to write to socket', $socket);

		if ($actual_bytes >= $expected_bytes)
			return $actual_bytes - 1;

		// message truncated, need to retry
		$message = substr($message, $actual_bytes);
		return $actual_bytes - 1 + $this->send($message, $socket, $timeout);

	}

	public function sendBuck(TrumanBuck $buck, $socket = null, $timeout = 0) {
		$expected = strlen($message = serialize($buck));
		$actual = $this->send($message, $socket, $timeout);
		return $expected === $actual;
	}

	private function throwError($msg, $socket = null) {

		$error_code = is_resource($socket) ?
			socket_last_error($socket) :
			socket_last_error();

		if ($error_code <= 0)
			return;

		$error = socket_strerror($error_code);
		$msg = "{$msg}. {$error}";

		TrumanException::throwNew($this, $msg);

	}

	public static function isLocal($host_spec) {
		$host = $host_spec['host'];
		return in_array($host, array(
			0, '0.0.0.0', '127.0.0.1', 'localhost', gethostname()
		));

	}

}
