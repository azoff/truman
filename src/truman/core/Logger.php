<? namespace truman\core;

use truman\interfaces\LoggerContext;

/**
 * Class Logger Used by Truman core classes to log discrete events across the network. The events are written in
 * a standard syntax, encouraging integration with monitors and other recovery systems.
 * @package truman\core
 */
class Logger implements \JsonSerializable {

	const ERROR_LOG_MESSAGE_TYPE = 3;
	const DESTINATION_DEFAULT    = '/tmp/truman.log';
	const MESSAGE_DELIMETER      = ' | ';

	/**
	 * The log file path for this Logger
	 */
	const OPTION_DESTINATION = 'destination';

	/**
	 * Whether or not this Logger sends messages to the log file
	 */
	const OPTION_MUTED = 'muted';

	private static $_DEFAULT_OPTIONS = [
		self::OPTION_DESTINATION => self::DESTINATION_DEFAULT,
		self::OPTION_MUTED       => false
	];

	private $muted;
	private $destination;
	private $context = null;

	/**
	 * Creates a new Logger instance
	 * @param LoggerContext $context A class that can be logged about
	 * @param array $options Optional settings for this Logger. See Logger::$_DEFAULT_OPTIONS
	 * @throws Exception If unable to write to log file
	 */
	public function __construct(LoggerContext $context, array $options = []) {
		$this->context      = $context;
		$options           += self::$_DEFAULT_OPTIONS;
		$this->muted        = (bool) $options[self::OPTION_MUTED];
		$this->destination  = $options[self::OPTION_DESTINATION];
		$appender           = fopen($this->destination, 'a');

		if ($appender === false) {
			throw new Exception('unable to write to destination', [
				'destination' => $this->destination,
				'context'     => $this,
				'method'      => __METHOD__
			]);
		}

		fclose($appender);
	}

	/**
	 * @inheritdoc
	 */
	public function __toString() {
		return "Logger<{$this->context}>";
	}

	/**
	 * @inheritdoc
	 */
	public function jsonSerialize() {
		return $this->__toString();
	}

	/**
	 * Gets whether or not this Logger can write to the log file
	 * @return bool
	 */
	public function isMuted() {
		return $this->muted;
	}

	/**
	 * Disables this Loggers ability to write to the log file
	 */
	public function mute() {
		$this->muted = true;
	}

	/**
	 * Enables this Loggers ability to write to the log file
	 */
	public function unmute() {
		$this->muted = false;
	}

	/**
	 * Logs an event to the log file
	 * @param string $event An event to log, usually defined in the host class
	 * @param mixed|null $data Any extra data about the event. Must be json_encode()able
	 * @return bool
	 * @throws Exception If unable to write the message to the log file
	 */
	public function log($event, $data = null) {

		if ($this->isMuted())
			return false;

		if (!strlen($event))
			throw new Exception('invalid event name', [
				'event'   => $event,
				'context' => $this,
				'method'  => __METHOD__
			]);

		$message_parts[] = number_format(microtime(true), 4, '.', '');
		$message_parts[] = str_pad(strtoupper($this->context->getLoggerType()), 6);
		$message_parts[] = str_pad($this->context->getLoggerId(), 32);
		$message_parts[] = str_pad(strtoupper($event), 17);
		$message_parts[] = (is_null($data) || $data === []) ? '' : (@json_encode($data) ?: '');

		$message = implode(self::MESSAGE_DELIMETER, $message_parts) . PHP_EOL;

		if (!error_log($message, self::ERROR_LOG_MESSAGE_TYPE, $this->destination))
			throw new Exception('unable to log event', [
				'message' => $message,
				'context' => $this,
				'method'  => __METHOD__
			]);

		return true;

	}

}