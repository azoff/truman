<?

class TrumanSocket {

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
		'force_client_mode' => 0, // Forces client mode for the socket
		'msg_delimiter' => PHP_EOL // Splits message boundaries
	);

	private $server_mode;
	private $client_mode;
	private $options;
	private $socket;
	private $connections = array();

	public function __construct($host_spec, array $options = array()) {

		if (is_int($host_spec))
			$host_spec = "0:{$host_spec}";
		if (is_string($host_spec))
			$host_spec = parse_url($host_spec);
		if (!is_array($host_spec))
			TrumanException::throwNew($this, 'host_spec must be an int, string, or array');

		$this->options = $host_spec + $options + self::$_DEFAULT_OPTIONS;

		$this->socket = @socket_create(
			$this->options['socket_domain'],
			$this->options['socket_type'],
			$this->options['socket_protocol']
		);

		if ($this->socket === false)
			$this->throwError('Unable to create a socket resource');

		if ($this->options['reuse_port']) {

			$option_set = @socket_set_option(
				$this->socket,
				SOL_SOCKET,
				SO_REUSEADDR,
				TRUE
			);

			if ($option_set === false)
				$this->throwError('Unable to mark socket as reusable', $this->socket);

		}

		$force_client_mode = $this->options['force_client_mode'];

		// server mode (local)
		if (self::isLocal($this->getHostSpec()) && !$force_client_mode) {

			if ($this->options['nonblocking'])
				if (@socket_set_nonblock($this->socket) === false);
					$this->throwError('Unable to mark socket as non-blocking', $this->socket);

			$bound = @socket_bind(
				$this->socket,
				$this->options['host'],
				$this->options['port']
			);

			if ($bound === false)
				$this->throwError("Unable to bind to {$this}", $this->socket);

			$listening = @socket_listen(
				$this->socket,
				$this->options['max_connections']
			);

			if ($listening === false)
				$this->throwError("Unable to listen to {$this}", $this->socket);

			$this->client_mode = false;
			$this->server_mode = true;

		// client mode (remote)
		} else {

			$connected = @socket_connect(
				$this->socket,
				$this->options['host'],
				$this->options['port']
			);

			if ($connected === false)
				$this->throwError("Unable to connect to {$this}", $this->socket);

			$this->client_mode = false;
			$this->server_mode = true;

		}

	}

	public function __destruct() {
		if (@socket_close($this->socket) === false)
			$this->throwError('Unable to close socket', $this->socket);
		foreach ($this->connections as $connection)
			$this->closeConnection($connection);
	}

	public function __toString() {
		$spec = $this->getHostSpec();
		return __CLASS__."<{$spec['host']}:{$spec['port']}>";
	}

	public function acceptConnection($timeout = 0) {

		if (!$this->isServer())
			return false;

		$sockets = array($this->socket);

		$ready = @socket_select($sockets, $i, $j, $timeout);

		if ($ready === false)
			$this->throwError('Unable to detect socket changes');

		if ($ready <= 0)
			return false;

		$connection = @socket_accept($sockets[0]);

		if ($connection === false)
			$this->throwError('Unable to accept connection', $sockets[0]);

		return $this->openConnection($connection);

	}

	public function closeConnection($connection) {

		if (!is_resource($connection))
			return false;

		$address = $this->getConnectionAddress($connection);
		if (!array_key_exists($address, $this->connections))
			return false;

		if (@socket_close($connection) === false)
			$this->throwError('Unable to close connection', $connection);

		unset($this->connections[$address]);

		return true;

	}

	public function getConnectionAddress($connection) {
		socket_getpeername($connection, $host, $port);
		return "{$host}:{$port}";
	}

	public function getHostSpec() {
		$host = $this->options['host'];
		$port = $this->options['port'];
		return array('host' => $host, 'port' => $port);
	}

	public function isClient() {
		return $this->client_mode;
	}

	public function isServer() {
		return $this->server_mode;
	}

	public function openConnection($connection) {

		if (!is_resource($connection))
			return false;

		$address = $this->getConnectionAddress($connection);

		if (array_key_exists($address, $this->connections))
			return false;

		$this->connections[$address] = $connection;

		return true;

	}

	public function receive($callback = null, $timeout = 0) {

		if ($this->isClient()) {
			$connections = array($this->socket);
		} else {
			$this->acceptConnection($timeout);
			if (!count($connections = $this->connections))
				return false;
		}

		$ready = @socket_select($connections, $i, $j, $timeout);

		if ($ready === false)
			$this->throwError('Unable to detect socket changes');

		if ($ready <= 0)
			return false;

		$read_limit = $this->options['size_limit'];

		foreach ($connections as $connection) {

			$message = socket_read($connection, $read_limit, PHP_NORMAL_READ);

			// close out sockets that don't provide any data
			if ($message === false) {
				$this->closeConnection($connection);

			// otherwise delegate interpretation of the data to the caller
			} else {
				$message = rtrim($message, $this->options['msg_delimiter']);
				if (is_callable($callback))
					return call_user_func($callback, $message, $this, $connection);
				else
					return $message;
			}

		}

		return false;

	}

	public function send($message, $connection = null, $timeout = 0) {

		$connections = is_resource($connection) ? array($connection) : array($this->socket);

		$ready = @socket_select($i, $connections, $j, $timeout);

		if ($ready === false)
			$this->throwError('Unable to detect socket changes');

		if ($ready <= 0)
			return 0;

		$connection = $connections[0];
		$delimeter  = $this->options['msg_delimiter'];
		$message    = rtrim($message, $delimeter) . $delimeter;

		$expected_bytes = strlen($message);
		$size_limit = $this->options['size_limit'];

		if ($expected_bytes > $size_limit)
			TrumanException::throwNew($this, "Message size greater than limit of {$size_limit} bytes");

		$actual_bytes = @socket_write($connection, $message, $expected_bytes);

		if ($actual_bytes === false)
			$this->throwError('Unable to write to socket', $connection);

		if ($actual_bytes >= $expected_bytes)
			return $actual_bytes - 1;

		// message truncated, need to retry
		$message = substr($message, $actual_bytes);
		return $actual_bytes - 1 + $this->send($message, $connection, $timeout);

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
