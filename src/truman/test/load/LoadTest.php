<? namespace truman\test\load;

use truman\Desk;
use truman\Buck;
use truman\Exception;
use truman\Util;

class LoadTest {

	private $bucks_enqueued  = 0;
	private $bucks_running   = 0;
	private $bucks_completed = 0;

	private $desks    = [];
	private $spammers = [];
	private $spammer_streams = [];

	private $model    = [];
	private $options  = [];
	private $ports    = [];
	private $port     = 12345;
	private $start    = 0;
	private $runtime  = 0;

	private static $_DEFAULT_OPTIONS = [
		'desks'            => 1,
		'spammers'         => 1,
		'drawers'          => 1,
		'refresh_rate'     => 42000,   // 24fps
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
		$this->start = microtime(true);
		do $status = $this->tick();
		while($status < 0);
		return (int) $status;
	}

	public function tick() {
		$status = -1;
		foreach ($this->desks as $desk) {
			if (!$desk->tick()) {
				$status = 0;
				break;
			}
		}
		$this->update();
		$this->render();
		usleep($this->options['refresh_rate']);
		return $status;
	}

	public function update() {
		$bytes = number_format(memory_get_peak_usage(true));
		$mb    = number_format($bytes/1048576, 1);
		$this->runtime = number_format(round(microtime(true) - $this->start, 4), 4);
		$this->model['Desks']    = $this->getDeskCount();
		$this->model['Drawers']  = 0;
		foreach ($this->desks as $desk)
			$this->model['Drawers'] += $desk->getActiveDrawerCount();
		$this->model['Spammers'] = $this->getSpammerCount();
		$this->model['Bucks Enqueued']  = $this->getBucksEnqueuedCount();
		$this->model['Bucks Running']   = $this->getBucksRunningCount();
		$this->model['Bucks Completed'] = $this->getBucksCompletedCount();
		$this->model['Memory'] = "{$bytes} bytes ({$mb}MB)";
		foreach (sys_getloadavg() as $i => $load) {
			if ($i === 0)      $key = 'Avg. Load Minute';
			else if ($i === 1) $key = 'Avg. Load 5 Minutes';
			else               $key = 'Avg. Load 15 Minutes';
			$this->model[$key] = $load;
		}
	}

	public function render() {
		passthru('clear');
		print "\nLoad Test ({$this->runtime}s)\n";
		print   "=================================\n";
		foreach ($this->model as $name => $value)
			print "{$name}: {$value}\n";
		print   "=================================\n\n";
	}

	public function getDeskCount() {
		return count($this->desks);
	}

	public function getSpammerCount() {
		$count = 0;
		foreach ($this->spammers as $spammer) {
			$status = proc_get_status($spammer);
			if ($status['running']) $count++;
		}
		return $count;
	}

	public function getBucksEnqueuedCount() {
		return $this->bucks_enqueued;
	}

	public function getBucksRunningCount() {
		return $this->bucks_running;
	}

	public function getBucksCompletedCount() {
		return $this->bucks_completed;
	}

	private function getSpammerCommand() {
		$command[] = 'php';
		$command[] = 'bin/spammer.php';
		$command[] = "--job_duration_max={$this->options['job_duration_max']}";
		$command[] = "--job_delay_max={$this->options['job_delay_max']}";
		$command[] = '--';
		foreach ($this->ports as $port)
			$command[] = $port;
		return implode(' ', $command);
	}

	public function spawnSpammer() {

		$command     = $this->getSpammerCommand();
		$descriptors = Util::getStreamDescriptors();
		$spammer     = proc_open($command, $descriptors, $streams, TRUMAN_HOME);
		$status      = proc_get_status($spammer);

		if (!$status['running'])
			throw new Exception('Unable to open spammer', [
				'context' => $this,
				'command' => $command,
				'method'  => __METHOD__
			]);

		$pid = $status['pid'];
		$this->spammers[$pid] = $spammer;
		$this->spammer_streams[$pid] = $streams;

		return $spammer;

	}

	public function spawnDesk() {
		$desk = new Desk($this->port, [
			Desk::OPTION_RESULT_RECEIVED_HANDLER => [$this, 'onBuckCompleted'],
			Desk::OPTION_BUCK_RECEIVED_HANDLER   => [$this, 'onBuckEnqueued'],
			Desk::OPTION_BUCK_PROCESSED_HANDLER  => [$this, 'onBuckRunning'],
			Desk::OPTION_DRAWER_COUNT            => $this->options['drawers']
		]);
		$this->desks[(string)$desk] = $desk;
		$this->ports[(string)$desk] = $this->port++;
		return $desk;
	}

	public function onBuckCompleted() {
		$this->bucks_running--;
		$this->bucks_completed++;
	}

	public function onBuckEnqueued() {
		$this->bucks_enqueued++;
	}

	public function onBuckRunning() {
		$this->bucks_enqueued--;
		$this->bucks_running++;
	}

}