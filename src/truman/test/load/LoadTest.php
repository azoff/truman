<? namespace truman\test\load;

use truman\Desk;
use truman\Buck;
use truman\Exception;
use truman\Util;

class LoadTest {

	private $bucks_enqueued = 0;
	private $bucks_running  = 0;
	private $bucks_completed = 0;

	private $model    = [];
	private $spammers = [];
	private $options  = [];
	private $desks    = [];
	private $ports    = [];
	private $port     = 12345;

	private static $_DEFAULT_OPTIONS = [
		'desks'            => 1,
		'spammers'         => 1,
		'drawers'          => 1,
		'job_duration_max' => 2000000, // max two seconds running jobs
		'job_delay_max'    => 2000000, // max two seconds between sending jobs
	];

	public static function main(array $options) {
		$test = new LoadTest($options);
		exit($test->start());
	}

	public function __construct(array $options = []) {
		$this->options = $options + self::$_DEFAULT_OPTIONS;
		while ($this->getDeskCount() < $this->options['desks'])
			$this->spawnDesk();
		while ($this->getSpammerCount() < $this->options['spammers'])
			$this->spawnSpammer();
	}

	public function start() {
		declare(ticks = 1);
		do $status = $this->tick();
		while($status < 0);
		return $status;
	}

	public function tick() {
		foreach ($this->desks as $desk) {
			$status = $desk->tick();
			if ($status >= 0)
				return $status;
		}
		$this->update();
		$this->render();
		return -1;
	}

	public function update() {
		$this->model['Spammers'] = $this->getSpammerCount();
		$this->model['Desks']    = $this->getDeskCount();
		$this->model['Drawers']  = 0;
		foreach ($this->desks as $desk)
			$this->model['Drawers'] += $desk->activeDrawerCount();
		$this->model['Enqueued']  = "{$this->bucks_enqueued} Bucks";
		$this->model['Running']   = "{$this->bucks_running} Bucks";
		$this->model['Completed'] = "{$this->bucks_completed} Bucks";
		$this->model['Memory Usage'] = (memory_get_peak_usage(true) / 1048576.0) . ' MB';
	}

	public function render() {
		// soon...
		// http://devzone.zend.com/173/using-ncurses-in-php/
	}

	public function getDeskCount() {
		return count($this->desks);
	}

	public function getSpammerCount() {
		return count($this->spammers);
	}

	private function getSpammerCommand() {
		$command[] = 'php';
		$command[] = 'bin/spammer.php';
		$command[] = "--job_duration_max={$this->options['job_duration_max']}";
		$command[] = "--job_delay_max={$this->options['job_delay_max']}";
		$command[] = '--';
		foreach ($this->ports as $port)
			$command[] = $port;
		return implode($command);
	}

	public function spawnSpammer() {

		$command     = $this->getSpammerCommand();
		$descriptors = Util::getStreamDescriptors();
		$spammer     = proc_open($command, $descriptors, $streams, TRUMAN_HOME);
		unset($streams);

		if (!is_resource($spammer))
			throw new Exception('Unable to open spammer', [
				'context' => $this,
				'command' => $command,
				'method'  => __METHOD__
			]);

		// get shell PID
		$status = proc_get_status($spammer);
		$pid    = $status['pid'];

		$this->spammers[$pid] = $spammer;

	}

	public function spawnDesk() {
		$desk = new Desk($this->port, [
			Desk::OPTION_RESULT_RECEIVED_HANDLER => [$this, 'onResultReceived'],
			Desk::OPTION_BUCK_RECEIVED_HANDLER   => [$this, 'onBuckReceived'],
			Desk::OPTION_BUCK_PROCESSED_HANDLER  => [$this, 'onBuckSent'],
			Desk::OPTION_DRAWER_COUNT            => $this->options['drawers']
		]);
		$this->desks[(string)$desk] = $desk;
		$this->ports[(string)$desk] = $this->port++;
		return $desk;
	}

	public function onResultReceived() {
		$this->bucks_running--;
		$this->bucks_completed++;
	}

	public function onBuckReceived() {
		$this->bucks_enqueued++;
	}

	public function onBuckSent() {
		$this->bucks_enqueued--;
		$this->bucks_running++;
	}

}