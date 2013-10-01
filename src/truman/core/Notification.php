<? namespace truman\core;

class Notification extends Buck {

	const LOGGER_TYPE = 'NOTIF';

	/**
	 * Asks a Desk to check if it should update its Client with the one in the Notification
	 */
	const TYPE_CLIENT_UPDATE = 0;

	/**
	 * Tells a Desk to restart all of it's Drawers. Can be useful for loading new code
	 */
	const TYPE_DESK_REFRESH  = 1;

	/**
	 * Tells a Drawer to exit gracefully
	 */
	const TYPE_DRAWER_SIGNAL = 2;

	private $type, $notice;

	private static $_DEFAULT_OPTIONS = array(
		parent::OPTION_PRIORITY => parent::PRIORITY_URGENT
	);

	/**
	 * Creates a new Notification instance
	 * @param string $type The type of Notification (See Notification::TYPE_*)
	 * @param mixed $notice Any serialize()able data to send to the Notification target
	 * @param array $options Any settings accepted by Buck::__construct()
	 */
	public function __construct($type, $notice = null, array $options = []) {
		$this->type   = $type;
		$this->notice = $notice;
		$options = $options + self::$_DEFAULT_OPTIONS;
		parent::__construct(self::CALLABLE_NOOP, [], $options);
	}

	/**
	 * @inheritdoc
	 */
	public function __toString() {
		$uuid = $this->getUUID();
		return "Notification<{$uuid}>";
	}

	/**
	 * @inheritdoc
	 */
	public function getLoggerType() {
		return self::LOGGER_TYPE;
	}

	/**
	 * Alias for getNotice()
	 */
	public function invoke() {
		return $this->getNotice();
	}

	/**
	 * Gets the type of Notification this is
	 * @return string
	 */
	public function getType() {
		return $this->type;
	}

	/**
	 * Gets the type name for this Notification
	 * @return string
	 */
	public function getTypeName() {
		switch($this->getType()) {
			case self::TYPE_CLIENT_UPDATE: return 'CLIENT_UPDATE';
			case self::TYPE_DESK_REFRESH:  return 'DESK_REFRESH';
			case self::TYPE_DRAWER_SIGNAL: return 'DRAWER_SIGNAL';
		}
	}

	/**
	 * Gets the Notification data to be passed with this Notification
	 * @return mixed
	 */
	public function getNotice() {
		return $this->notice;
	}

	/**
	 * Convenience method to get whether or not this is a client update Notification
	 * @return bool
	 */
	public function isClientUpdate() {
		return $this->getType() === self::TYPE_CLIENT_UPDATE;
	}

	/**
	 * Convenience method to get whether or not this is a drawer signal Notification
	 * @return bool
	 */
	public function isDrawerSignal() {
		return $this->getType() === self::TYPE_DRAWER_SIGNAL;
	}

	/**
	 * Convenience method to get whether or not this is a desk refresh Notification
	 * @return bool
	 */
	public function isDeskRefresh() {
		return $this->getType() === self::TYPE_DESK_REFRESH;
	}

	/**
	 * @inheritdoc
	 */
	protected function logInit(array $options) {
		parent::logInit([
			'type'    => $this->getTypeName(),
			'notice'  => $this->getNotice(),
			'buck'    => $options
		]);
	}

}