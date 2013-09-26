<? namespace truman;

if (!extension_loaded('sockets'))
	throw new Exception('Truman requires the PHP Sockets Extension', [
		'href' => 'http://php.net/manual/sockets.setup.php'
	]);

class Socket implements \JsonSerializable {

	private static $_DEFAULT_OPTIONS = array(
		'host'        => '0.0.0.0', // Bind to all incoming addresses
		'port'        => 0, // Self-assign a port number
		'buffer_size' => 262144, // Chunk size for socket reads
		'socket_domain' => AF_INET, // IPv4 Internet based protocols
		'socket_type' => SOCK_STREAM, // Provides sequenced, reliable, full-duplex, connection-based byte streams
		'socket_protocol' => SOL_TCP, // A reliable, connection based, stream oriented, full duplex protocol
		'size_limit' => 262144, // The maximum message size that can be sent or received
		'reuse_port' => true, // Marks the socket as reusable
		'max_connections' => null, // Number of connections to allow on the socket, null is system dependent
		'nonblocking' => true, // Does not block on accept()
		'force_client_mode' => 0, // Forces client mode for the socket
		'msg_delimiter' => PHP_EOL // Splits message boundaries
	);

	private $server_mode;
	private $client_mode;
	private $host, $port;
	private $options;
	private $socket;
	private $connections = array();

	public function __construct($host_spec, array $options = []) {

		if (is_int($host_spec))
			$host_spec = ['port' => $host_spec];
		if (is_string($host_spec))
			$host_spec = parse_url($host_spec);
		if (!is_array($host_spec))
			throw new Exception('Host spec must be an int, string, or array', [
				'context'   => $this,
				'host_spec' => $host_spec,
				'method'    => __METHOD__
			]);

		$this->options = $host_spec + $options + self::$_DEFAULT_OPTIONS;

		$this->host = $this->options['host'];
		$this->port = $this->options['port'];

		$this->socket = \socket_create(
			$this->options['socket_domain'],
			$this->options['socket_type'],
			$this->options['socket_protocol']
		);

		if ($this->socket === false)
			$this->throwError('Unable to create a socket resource');

		if ($this->options['reuse_port']) {

			$option_set = \socket_set_option(
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
		if (Util::isLocalAddress($this->getHost()) && !$force_client_mode) {

			if ($this->options['nonblocking'])
				if (\socket_set_nonblock($this->socket) === false);
					$this->throwError('Unable to mark socket as non-blocking', $this->socket);

			$bound = \socket_bind(
				$this->socket,
				$this->getHost(),
				$this->getPort()
			);

			if ($bound === false)
				$this->throwError("Unable to bind to port {$this->options['port']}", $this->socket);

			$listening = \socket_listen(
				$this->socket,
				$this->options['max_connections']
			);

			if ($listening === false)
				$this->throwError("Unable to listen to port {$this->options['port']}", $this->socket);

			if (\socket_getsockname($this->socket, $this->host, $this->port) === false)
				$this->throwError('Unable to get socket name', $this->socket);

			$this->client_mode = false;
			$this->server_mode = true;

		// client mode (remote)
		} else {

			$connected = @\socket_connect(
				$this->socket,
				$this->getHost(),
				$this->getPort()
			);

			if ($connected === false)
				$this->throwError("Unable to connect to {$this->options['host']}:{$this->options['port']}",
					$this->socket);

			if (@\socket_getpeername($this->socket, $this->host, $this->port) === false)
				$this->throwError('Unable to get peer name', $this->socket);

			$this->client_mode = false;
			$this->server_mode = true;

		}

	}

	public function __destruct() {
		$this->close();
	}

	public function __toString() {
		return "Socket<{$this->getHostAndPort()}>";
	}

	public function jsonSerialize() {
		return $this->__toString();
	}

	public function close() {
		if (is_resource($this->socket)) {
			\socket_set_block($this->socket);
			\socket_set_option($this->socket, SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 1]);
			\socket_close($this->socket);
		}
		foreach ($this->connections as $connection)
			$this->closeConnection($connection);
	}

	public function acceptConnection($timeout = 0) {

		if (!$this->isServer())
			return false;

		$sockets = [$this->socket];

		$ready = \socket_select($sockets, $i, $j, $timeout);

		if ($ready === false)
			$this->throwError('Unable to detect socket changes');

		if ($ready <= 0)
			return false;

		$connection = \socket_accept($sockets[0]);

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

		if (\socket_close($connection) === false)
			$this->throwError('Unable to close connection', $connection);

		unset($this->connections[$address]);

		return true;

	}

	public function getConnectionAddress($connection) {
		\socket_getpeername($connection, $host, $port);
		return "{$host}:{$port}";
	}

	public function getHost() {
		return $this->host;
	}

	public function getPort() {
		return (int) $this->port;
	}

	public function getHostAndPort() {
		return "{$this->getHost()}:{$this->getPort()}";
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

	public function receive($timeout = 0) {

		if ($this->isClient()) {
			$connections = [$this->socket];
		} else {
			$this->acceptConnection($timeout);
			if (!count($connections = $this->connections))
				return null;
		}

		$ready = \socket_select($connections, $i, $j, $timeout);

		if ($ready === false)
			$this->throwError('Unable to detect socket changes');

		if ($ready <= 0)
			return null;

		$read_limit = $this->options['size_limit'];

		foreach ($connections as $connection) {

			$message = @\socket_read($connection, $read_limit, PHP_NORMAL_READ);

			// close out sockets that don't provide any data
			if ($message === false) $this->closeConnection($connection);

			// otherwise delegate interpretation of the data to the caller
			else return Util::streamDataDecode($message, $this->options['msg_delimiter']);

		}

		return null;

	}

	public function send($message, $connection = null, $timeout = 0) {

		$connections = is_resource($connection) ? [$connection] : [$this->socket];

		$ready = \socket_select($i, $connections, $j, $timeout);

		if ($ready === false)
			$this->throwError('Unable to detect socket changes');

		if ($ready <= 0)
			return 0;

		$connection     = $connections[0];
		$message        = Util::streamDataEncode($message, $this->options['msg_delimiter']);
		$expected_bytes = strlen($message);
		$size_limit     = $this->options['size_limit'];

		if ($expected_bytes > $size_limit)
			throw new Exception('Message size exceeds size limit', [
				'context'    => $this,
				'size_limit' => "{$size_limit} bytes",
				'method'     => __METHOD__
			]);

		$actual_bytes = 0;
		do $actual_bytes += \socket_write($connection, $message, $expected_bytes);
		while ($actual_bytes < $expected_bytes);

		if ($actual_bytes === false)
			$this->throwError('Unable to write to socket', $connection);

		return true;

	}

	private function throwError($msg, $socket = null) {

		$error_code = is_resource($socket) ?
			\socket_last_error($socket) :
			\socket_last_error();

		if ($error_code <= 0)
			return;

		$error = \socket_strerror($error_code);

		throw new Exception($msg, [
			'context'    => $this,
			'error'      => $error,
			'code'       => $error_code
		]);

	}

}
