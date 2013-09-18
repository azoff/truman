<? namespace truman;

use truman\Buck;

class Exception extends \Exception {

	private $details;

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