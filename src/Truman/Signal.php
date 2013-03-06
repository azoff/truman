<?

class Truman_Signal extends Truman_Buck {

	private $signal;

	public function __construct($signal = SIGTERM) {
		$this->signal = (int) $signal;
		parent::__construct('posix_kill', array(SIGTERM));
	}

	public function invoke() {
		$this->args = array(getmypid(), $this->signal);
		parent::__invoke();
	}

}