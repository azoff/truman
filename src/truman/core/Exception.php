<? namespace truman\core;

use truman\core\Buck;

/**
 * Class Exception Used by all Truman classes to namespace their Exceptions
 * @package truman\core
 */
class Exception extends \Exception {

	private $details;

	/**
	 * @inheritdoc
	 */
	public function __construct($message, array $details = [], \Exception $previous = null) {
		if ($this->details = $details) {
			$details  = json_encode($details, JSON_PRETTY_PRINT);
			$message .= "\nDetails: {$details}";
		}
		$code = is_null($previous) ? 0 : $previous->getCode();
		parent::__construct($message, $code, $previous);
	}

	public function getDetails() {
		return $this->details;
	}

}