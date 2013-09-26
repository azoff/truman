<? namespace truman;

class Notification extends Buck {

	const LOGGER_TYPE = 'NOTIF';

	const TYPE_CLIENT_UPDATE = 0;
	const TYPE_DESK_REFRESH  = 1;
	const TYPE_DRAWER_SIGNAL = 2;

	private $type, $notice;

	private static $_DEFAULT_OPTIONS = array(
		'priority' => self::PRIORITY_URGENT
	);

	public function __construct($type, $notice = null, array $options = []) {
		$this->type   = $type;
		$this->notice = $notice;
		$options = $options + self::$_DEFAULT_OPTIONS;
		parent::__construct(self::CALLABLE_NOOP, [], $options);
	}

	public function __toString() {
		$uuid = $this->getUUID();
		return "Notification<{$uuid}>";
	}

	public function getLoggerType() {
		return self::LOGGER_TYPE;
	}

	public function invoke() {
		return $this->getNotice();
	}

	public function getType() {
		return $this->type;
	}

	public function getNotice() {
		return $this->notice;
	}

	public function isClientUpdate() {
		return $this->getType() === self::TYPE_CLIENT_UPDATE;
	}

	public function isDrawerSignal() {
		return $this->getType() === self::TYPE_DRAWER_SIGNAL;
	}

	public function isDeskRefresh() {
		return $this->getType() === self::TYPE_DESK_REFRESH;
	}

	protected function logInit(array $original_opts) {
		$this->logger->log(self::LOGGER_EVENT_INIT, [
			'type'    => $this->type,
			'notice'  => $this->notice,
			'options' => $original_opts
		]);
	}

}