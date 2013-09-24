<? namespace truman;

use truman\interfaces\LoggerContext;

class Logger implements \JsonSerializable {

	const ERROR_LOG_MESSAGE_TYPE = 3;
	const DESTINATION_DEFAULT    = '/tmp/truman.log';
	const MESSAGE_DELIMETER      = ' | ';

	const PARAM_DESTINATION = 'destination';
	const PARAM_MUTED       = 'muted';
	private static $_DEFAULT_OPTIONS = [
		self::PARAM_DESTINATION => self::DESTINATION_DEFAULT,
		self::PARAM_MUTED       => false
	];

	private $muted;
	private $destination;
	private $context = null;

	public function __construct(LoggerContext $context, array $options = []) {
		$this->context      = $context;
		$options           += self::$_DEFAULT_OPTIONS;
		$this->muted        = (bool) $options[self::PARAM_MUTED];
		$this->destination  = $options[self::PARAM_DESTINATION];
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

	public function __toString() {
		return "Logger<{$this->context}>";
	}

	public function jsonSerialize() {
		return $this->__toString();
	}

	public function isMuted() {
		return $this->muted;
	}

	public function mute() {
		$this->muted = true;
	}

	public function unmute() {
		$this->muted = false;
	}

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
		$message_parts[] = str_pad(strtoupper($event), 15);
		$message_parts[] = !$data ? '' : json_encode($data);

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