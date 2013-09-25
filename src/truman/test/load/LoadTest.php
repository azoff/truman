<? namespace truman\test\load;

use truman\Desk;
use truman\Buck;
use truman\Exception;
use truman\Result;
use truman\Util;

class LoadTest {

	private $bucks_enqueued  = 0;
	private $bucks_running   = 0;
	private $bucks_completed = 0;

	private $desks    = [];
	private $spammers = [];
	private $spammer_streams = [];

	private $dirty          = true;
	private $drawer_memory  = [];
	private $options        = [];
	private $ports          = [];
	private $port           = 12345;
	private $start          = 0;
	private $work_time      = 0;

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
		$this->start = microtime(true);
		do $status = $this->tick();
		while($status < 0);
		return (int) $status;
	}

	public function tick() {
		$status = -1;
		foreach ($this->desks as $desk) {
			if ($desk->tick()) continue;
			else $status = 0;  break;
		}
		$this->render();
		return $status;
	}

	public function render() {

		if (!$this->dirty) return;
		else $this->dirty = false;

		passthru('clear');

		self::format($model, 'desks',     $this->getDeskCount());
		self::format($model, 'drawers',   $this->getActiveDrawerCount());
		self::format($model, 'spammers',  $this->getSpammerCount());

		self::format($model, 'enqueued bucks',  $this->getBucksEnqueuedCount());
		self::format($model, 'running bucks',   $this->getBucksRunningCount());
		self::format($model, 'completed bucks', $this->getBucksCompletedCount());

		self::format($model, 'work time',  $this->getWorkTime(), 'time');
		self::format($model, 'idle time',  $this->getIdleTime(), 'time');
		self::format($model, 'total time', $this->getTotalTime(), 'time');

		self::format($model, 'desk memory',   $this->getDeskMemory(), 'memory');
		self::format($model, 'drawer memory', $this->getDrawerMemory(), 'memory');

		foreach ($this->getSystemLoad() as $key => $load)
			self::format($model, $key, $load, null);

		print str_repeat('=', 45) . "\n";
		print " Load Test\n";
		print   str_repeat('=', 45) . "\n\n";
		foreach ($model as $name => $value)
			print "{$name}: {$value}\n";
		print "\n" . str_repeat('=', 45) . "\n\n";
	}

	public function getSystemLoad() {
		$loads = [];
		$key[] = '1 minute load';
		$key[] = '5 minute load';
		$key[] = '15 minute load';
		foreach (sys_getloadavg() as $i => $load)
			$loads[$key[$i]] = $load;
		return $loads;
	}

	public function getDeskMemory() {
		return memory_get_usage(true);
	}

	public function getDrawerMemory() {
		return array_sum($this->drawer_memory);
	}

	public function getTotalTime() {
		return microtime(true) - $this->start;
	}

	public function getWorkTime() {
		return $this->work_time;
	}

	public function getIdleTime() {
		return $this->getTotalTime() - $this->getWorkTime();
	}

	public function getDeskCount() {
		return count($this->desks);
	}

	public function getActiveDrawerCount() {
		$drawers = 0;
		foreach ($this->desks as $desk)
			$drawers += $desk->getActiveDrawerCount();
		return $drawers;
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

	public function onBuckCompleted(Result $result) {
		$this->bucks_running--;
		$this->bucks_completed++;
		$data = $result->data();
		$this->drawer_memory[$data->pid] = $data->memory;
		$this->work_time += $data->runtime;
		$this->dirty = true;
	}

	public function onBuckEnqueued() {
		$this->bucks_enqueued++;
		$this->dirty = true;
	}

	public function onBuckRunning() {
		$this->bucks_enqueued--;
		$this->bucks_running++;
		$this->dirty = true;
	}

	private static function format(&$model, $key, $value, $type = 'int', $pad = 16) {
		$key = str_pad(ucwords($key), $pad, ' ', STR_PAD_LEFT);
		if ($type === 'int')    $value = number_format($value);
		if ($type === 'float')  $value = number_format($value, 1);
		if ($type === 'memory') $value = number_format($value) . ' Bytes (' . number_format($value/1048576.0, 1) . 'MB)';
		if ($type === 'time')   $value = number_format($value/3600) . 'h ' .
                                         number_format($value/60) . 'm ' .
                                         number_format($value%60.0, 1) . 's';
		$model[$key] = $value;
	}

}