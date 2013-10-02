<? namespace truman\core;

class Socket implements \JsonSerializable {

	const DEFAULT_HOST = 0;
	const DEFAULT_PORT = 0;

	/**
	 * Selects the host to bind or connect to
	 */
	const SPEC_HOST = 'host';

	/**
	 * Selects the port to listen or send to (0 is self-assign)
	 */
	const SPEC_PORT = 'port';

	/**
	 * Selects the channels a specification will be a part of
	 */
	const SPEC_CHANNELS = 'channels';

	/**
	 * The domain of socket protocols to work with. For example Ipv4
	 */
	const OPTION_SOCKET_DOMAIN = 'socket_domain';

	/**
	 * How the Socket will be used; Truman uses streaming Sockets
	 */
	const OPTION_SOCKET_TYPE = 'socket_type';

	/**
	 * The named protocol to be used by the socket; Truman uses TCP
	 */
	const OPTION_SOCKET_PROTOCOL = 'socket_protocol';

	/**
	 * The maximum message size that can be sent or received
	 */
	const OPTION_MSG_SIZE_LIMIT = 'size_limit';

	/**
	 * Marks the socket as reusable in a shared context
	 */
	const OPTION_REUSE_PORT = 'reuse_port';

	/**
	 * Number of connections to allow on the socket, null is system dependent
	 */
	const OPTION_MAX_CONNECTIONS = 'max_connections';

	/**
	 * Does not block on accept()
	 */
	const OPTION_NONBLOCKING = 'nonblocking';

	/**
	 * Forces client mode for the socket
	 */
	const OPTION_FORCE_CLIENT_MODE = 'force_client_mode';

	/**
	 * Splits message boundaries
	 */
	const OPTION_MSG_DELIMITER = 'msg_delimiter';

	private static $_DEFAULT_OPTIONS = [
		self::OPTION_SOCKET_DOMAIN     => AF_INET,     // IPv4 Internet based protocols
		self::OPTION_SOCKET_TYPE       => SOCK_STREAM, // Provides sequenced, reliable, full-duplex, connection-based byte streams
		self::OPTION_SOCKET_PROTOCOL   => SOL_TCP,     // A reliable, connection based, stream oriented, full duplex protocol
		self::OPTION_MSG_SIZE_LIMIT    => 262144,
		self::OPTION_REUSE_PORT        => true,
		self::OPTION_MAX_CONNECTIONS   => null,        // System dependent
		self::OPTION_NONBLOCKING       => true,
		self::OPTION_FORCE_CLIENT_MODE => 0,
		self::OPTION_MSG_DELIMITER     => PHP_EOL
	];

	private $server_mode;
	private $client_mode;
	private $host, $port;
	private $socket;
	private $connections = [];
	private $msg_size_limit;
	private $msg_delimiter;

	/**
	 * @param int|array|string $host_spec A Socket specification to bind or connect to.
	 * Valid values include:
	 *  - A numeric port number
	 *  - A URL containing an IP or domain, and port number
	 *  - A keyed array, compatible with the output of PHP's parse_url() method
	 * @param array $options Optional settings for the Socket. See Socket::$_DEFAULT_SETTINGS
	 * @throws Exception if Unable to create the Socket
	 */
	public function __construct($host_spec, array $options = []) {

		$options += self::$_DEFAULT_OPTIONS;

		$spec = Util::normalizeSocketSpec($host_spec, self::DEFAULT_HOST, self::DEFAULT_PORT);

		$this->host = $spec[self::SPEC_HOST];
		$this->port = $spec[self::SPEC_PORT];

		$this->socket = @\socket_create(
			$options[self::OPTION_SOCKET_DOMAIN],
			$options[self::OPTION_SOCKET_TYPE],
			$options[self::OPTION_SOCKET_PROTOCOL]
		);

		if ($this->socket === false)
			$this->throwError('Unable to create a socket resource');

		if ($options[self::OPTION_REUSE_PORT]) {

			$option_set = @\socket_set_option(
				$this->socket,
				SOL_SOCKET,
				SO_REUSEADDR,
				TRUE
			);

			if ($option_set === false)
				$this->throwError('Unable to mark socket as reusable', $this->socket);

		}

		$this->msg_delimiter  = $options[self::OPTION_MSG_DELIMITER];
		$this->msg_size_limit = $options[self::OPTION_MSG_SIZE_LIMIT];

		$force_client_mode = $options[self::OPTION_FORCE_CLIENT_MODE];

		// server mode (local)
		if (Util::isLocalAddress($this->getHost()) && !$force_client_mode) {

			if ($options[self::OPTION_NONBLOCKING])
				if (@\socket_set_nonblock($this->socket) === false);
					$this->throwError('Unable to mark socket as non-blocking', $this->socket);

			$bound = @\socket_bind(
				$this->socket,
				$this->getHost(),
				$this->getPort()
			);

			if ($bound === false)
				$this->throwError("Unable to bind to port {$spec[self::SPEC_PORT]}", $this->socket);

			$listening = @\socket_listen(
				$this->socket,
				$options[self::OPTION_MAX_CONNECTIONS]
			);

			if ($listening === false)
				$this->throwError("Unable to listen to port {$spec[self::SPEC_PORT]}", $this->socket);

			if (@\socket_getsockname($this->socket, $this->host, $this->port) === false)
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
				$this->throwError("Unable to connect to {$spec[self::SPEC_HOST]}:{$spec[self::SPEC_PORT]}",
					$this->socket);

			if (@\socket_getpeername($this->socket, $this->host, $this->port) === false)
				$this->throwError('Unable to get peer name', $this->socket);

			$this->client_mode = false;
			$this->server_mode = true;

		}

	}

	/**
	 * @inheritdoc
	 */
	public function __destruct() {
		$this->close();
	}

	/**
	 * @inheritdoc
	 */
	public function __toString() {
		return "Socket<{$this->getHostAndPort()}>";
	}

	/**
	 * @inheritdoc
	 */
	public function jsonSerialize() {
		return $this->__toString();
	}

	/**
	 * Closes the Socket connection and frees the resource
	 */
	public function close() {
		Util::socketClose($this->socket);
		foreach ($this->connections as $connection)
			$this->closeConnection($connection);
	}

	/**
	 * Accepts external Socket connections
	 * @param int $timeout The time to wait for an external Socket to connect
	 * @return bool
	 */
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

	/**
	 * Closes an open connection
	 * @param resource $connection The connection to close
	 * @return bool
	 */
	public function closeConnection($connection) {

		if (!is_resource($connection))
			return false;

		$address = $this->getConnectionAddress($connection);
		Util::socketClose($connection);

		unset($this->connections[$address]);

		return true;

	}

	/**
	 * Gets a readable representation of the the connection's address
	 * @param resource $connection The connection to parse
	 * @return string A host:port string representing the Socket connection
	 */
	public function getConnectionAddress($connection) {
		\socket_getpeername($connection, $host, $port);
		return "{$host}:{$port}";
	}

	/**
	 * The host bound or connected to by this Socket
	 * @return string
	 */
	public function getHost() {
		return $this->host;
	}

	/**
	 * The port sending or listening to by this Socket
	 * @return int
	 */
	public function getPort() {
		return (int) $this->port;
	}

	/**
	 * A human-readable representation of this Socket's address
	 * @return string
	 */
	public function getHostAndPort() {
		return "{$this->getHost()}:{$this->getPort()}";
	}

	/**
	 * Gets whether or not this Socket is writing to another Socket
	 * @return bool
	 */
	public function isClient() {
		return $this->client_mode;
	}

	/**
	 * Gets whether or not this Socket is reading from one or more Sockets
	 * @return bool
	 */
	public function isServer() {
		return $this->server_mode;
	}

	/**
	 * Opens a connection to a Socket, should it not already be open
	 * @param resource $connection The connection to open
	 * @return bool
	 */
	public function openConnection($connection) {

		if (!is_resource($connection))
			return false;

		$address = $this->getConnectionAddress($connection);

		if (array_key_exists($address, $this->connections))
			return false;

		$this->connections[$address] = $connection;

		return true;

	}

	/**
	 * Receives any messages from the Sockets connected to this Socket
	 * @param int $timeout The time to wait for a message
	 * @return mixed|null The unserialized data returned from the Socket, or null if no data was received
	 */
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

		foreach ($connections as $connection) {

			$message = @\socket_read($connection, $this->msg_size_limit, PHP_NORMAL_READ);

			// close out sockets that don't provide any data
			if ($message === false) $this->closeConnection($connection);

			// otherwise delegate interpretation of the data to the caller
			else return Util::streamDataDecode($message, $this->msg_delimiter);

		}

		return null;

	}

	/**
	 * Sends a message from this Socket to another Socket
	 * @param mixed $message Anything that can be serialized by PHP
	 * @param null|resource $connection An explicit connection to write to
	 * @param int $timeout The time to wait until writing is possible
	 * @return bool true if the message was written, otherwise false
	 * @throws Exception If the message was invalid, or unable to read the socket
	 */
	public function send($message, $connection = null, $timeout = 0) {

		$connections = is_resource($connection) ? [$connection] : [$this->socket];

		$ready = \socket_select($i, $connections, $j, $timeout);

		if ($ready === false)
			$this->throwError('Unable to detect socket changes');

		if ($ready <= 0)
			return false;

		$connection     = $connections[0];
		$message        = Util::streamDataEncode($message, $this->msg_delimiter);
		$expected_bytes = strlen($message);

		if ($expected_bytes > $this->msg_size_limit)
			throw new Exception('Message size exceeds size limit', [
				'context'    => $this,
				'size_limit' => "{$this->msg_size_limit} bytes",
				'method'     => __METHOD__
			]);

		$actual_bytes = 0;
		do $actual_bytes += \socket_write($connection, $message, $expected_bytes);
		while ($actual_bytes < $expected_bytes);

		if ($actual_bytes === false)
			$this->throwError('Unable to write to socket', $connection);

		return true;

	}

	/**
	 * Throws an exception for Socket errors
	 * @param string $msg Added context for the error
	 * @param null|resource $socket The socket that experienced the error
	 * @throws Exception The resultant Exception
	 */
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
