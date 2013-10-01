<? namespace truman\test\load;

use truman\core\Desk;
use truman\core\Buck;
use truman\core\Exception;
use truman\core\Result;
use truman\core\Util;

/**
 * Class LoadTest Starts and monitors a load test using Truman
 * @package truman\test\load
 */
class LoadTest {

	private $bucks_enqueued  = 0;
	private $bucks_running   = 0;
	private $bucks_completed = 0;

	private $job_delay_max;
	private $job_duration_max;

	private $prefill_count;
	private $original_desk_count;
	private $original_drawer_count;
	private $original_spammer_count;

	private $desks    = [];
	private $spammers = [];
	private $spammer_streams = [];

	private $drawer_memory      = [];
	private $drawer_base_memory = [];

	private $dirty          = true;
	private $ports          = [];
	private $port           = 12345;
	private $start_time     = 0;
	private $work_time      = 0;

	/**
	 * The number of Desks in the load test
	 */
	const OPTION_DESKS = 'desks';

	/**
	 * The number of Spammers in the load test
	 */
	const OPTION_SPAMMERS = 'spammers';

	/**
	 * The number of Drawers in the load test
	 */
	const OPTION_DRAWERS = 'drawers';

	/**
	 * The max duration of a Buck created by a Spammer
	 */
	const OPTION_JOB_DURATION_MAX = 'job_duration_max';

	/**
	 * The max time between creating Bucks in a Spammer
	 */
	const OPTION_JOB_DELAY_MAX = 'job_delay_max';

	/**
	 * The number of Bucks to put into the queue before allowing the Desks to process them
	 */
	const OPTION_PREFILL_QUEUE = 'prefill_queue';

	private static $_DEFAULT_OPTIONS = [
		self::OPTION_DESKS            => 1,
		self::OPTION_SPAMMERS         => 1,
		self::OPTION_DRAWERS          => 1,
		self::OPTION_JOB_DURATION_MAX => 2000000, // max two seconds running jobs
		self::OPTION_JOB_DELAY_MAX    => 2000000, // max two seconds between sending jobs
		self::OPTION_PREFILL_QUEUE    => 0,
	];

	/**
	 * Runs a LoadTest from the command line
	 * @param array $argv The arguments passed into the command line
	 * @param array $option_keys Any options to set by command line (defaults to all options)
	 */
	public static function main(array $argv = null, array $option_keys = null) {
		$options = Util::getOptions($option_keys, self::$_DEFAULT_OPTIONS);
		$test    = new LoadTest($options);
		exit($test->start());
	}

	/**
	 * Creates a new LoadTest
	 * @param array $options Optional settings for the LoadTest. See LoadTest::$_DEFAULT_OPTIONS
	 */
	public function __construct(array $options = []) {
		$options += self::$_DEFAULT_OPTIONS;
		$this->original_desk_count    = $options[self::OPTION_DESKS];
		$this->original_spammer_count = $options[self::OPTION_SPAMMERS];
		$this->original_drawer_count  = $options[self::OPTION_DRAWERS];
		$this->prefill_count          = $options[self::OPTION_PREFILL_QUEUE];
		$this->job_delay_max          = $options[self::OPTION_JOB_DELAY_MAX];
		$this->job_duration_max       = $options[self::OPTION_JOB_DURATION_MAX];
		while ($this->getDeskCount() < $this->original_desk_count)
			$this->spawnDesk();
		while ($this->getSpammerCount() < $this->original_spammer_count)
			$this->spawnSpammer();
	}

	/**
	 * Starts this LoadTest's tick() cycle
	 * @return int The status code of the last tick()
	 */
	public function start() {
		declare(ticks = 1);
		$this->start_time = microtime(true);
		$this->prefillQueue();
		do $status = $this->tick();
		while($status < 0);
		return (int) $status;
	}

	/**
	 * Fills up the Desk queues with as many Bucks as the user requests
	 * @throws Exception
	 */
	private function prefillQueue() {

		if (($expected_prefill = $this->prefill_count) < 0)
			return;

		if (!$this->desks)
			throw new Exception('Unable to prefill queue because there are no desks', [
				'context' => $this,
				'method'  => __METHOD__
			]);

		while ($this->getBucksEnqueuedCount() < $expected_prefill) {
			foreach ($this->desks as $desk)
				$desk->receiveBuck();
			$this->render();
		}

	}

	/**
	 * The basic work cycle for a LoadTest. Calls tick() on each Desk, then render()s any changes.
	 * @return int -1 to continue, 0 to stop
	 */
	public function tick() {
		$status = -1;
		foreach ($this->desks as $desk) {
			if ($desk->tick())                continue;
			else $status = 0;                 break;
		}
		$this->render();
		return $status;
	}

	/**
	 * Renders this LoadTest's data to the screen
	 * @note render uses a pretty rudimentary method to clear the screen. it could be better...
	 */
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
		self::format($model, 'total bucks',     $this->getBucksCount());

		self::format($model, 'work time',  $this->getWorkTime(), 'time');
		self::format($model, 'idle time',  $this->getIdleTime(), 'time');
		self::format($model, 'total time', $this->getTotalTime(), 'time');

		self::format($model, 'base desk memory',  $this->getDeskBaseMemory(), 'memory');
		self::format($model, 'alloc desk memory', $this->getDeskAllocMemory(), 'memory');
		self::format($model, 'total desk memory', $this->getDeskMemory(), 'memory');

		self::format($model, 'base drawer memory',  $this->getDrawerBaseMemory(), 'memory');
		self::format($model, 'alloc drawer memory', $this->getDrawerAllocMemory(), 'memory');
		self::format($model, 'total drawer memory', $this->getDrawerMemory(), 'memory');

		self::format($model, 'base system memory',  $this->getBaseMemory(), 'memory');
		self::format($model, 'alloc system memory', $this->getAllocMemory(), 'memory');
		self::format($model, 'total system memory', $this->getMemory(), 'memory');

		self::format($model, 'bucks per second', $this->getBuckThroughput(), 'float');
		self::format($model, 'bytes per buck', $this->getSizeOfQueuedBuck(), 'float');

		foreach ($this->getSystemLoad() as $key => $load)
			self::format($model, $key, $load, null);

		print str_repeat('=', 50) . "\n";
		print " Load Test\n";
		print   str_repeat('=', 50) . "\n\n";
		foreach ($model as $name => $value)
			print "{$name}: {$value}\n";
		print "\n" . str_repeat('=', 50) . "\n\n";

		gc_collect_cycles();

	}

	/**
	 * Formats sys_getloadavg for use in render()
	 * @return array
	 */
	public function getSystemLoad() {
		$loads = [];
		$key[] = '1 minute load';
		$key[] = '5 minute load';
		$key[] = '15 minute load';
		foreach (sys_getloadavg() as $i => $load)
			$loads[$key[$i]] = $load;
		return $loads;
	}

	/**
	 * Gets the number of Bucks per second moving through the system
	 * @return float
	 */
	public function getBuckThroughput() {
		return $this->getBucksCount() / $this->getTotalTime();
	}

	/**
	 * Gets the average size of a Buck in a Desk's priority queue
	 * @return float|int
	 */
	public function getSizeOfQueuedBuck() {
		$queued = $this->getBucksEnqueuedCount();
		return $queued > 0 ? $this->getDeskAllocMemory() / $queued : 0;
	}

	/**
	 * Gets the base allocated memory (in bytes) this LoadTest started with
	 * @return int
	 */
	public function getDeskBaseMemory() {
		return TRUMAN_BASE_MEMORY;
	}

	/**
	 * Gets the additional allocated memory (in bytes) this LoadTest used
	 * @return int
	 */
	public function getDeskAllocMemory() {
		return Util::getMemoryUsage();
	}

	/**
	 * Gets the total allocated memory (in bytes) this LoadTest used
	 * @return int
	 */
	public function getDeskMemory() {
		return $this->getDeskBaseMemory() + $this->getDeskAllocMemory();
	}

	/**
	 * Gets the base allocated memory (in bytes) all connected Drawers started with
	 * @return number
	 */
	public function getDrawerBaseMemory() {
		return array_sum($this->drawer_base_memory);
	}

	/**
	 * Gets the additional allocated memory (in bytes) all connected Drawers used
	 * @return number
	 */
	public function getDrawerAllocMemory() {
		return array_sum($this->drawer_memory);
	}

	/**
	 * Gets the total allocated memory (in bytes) all connected Drawers used
	 * @return number
	 */
	public function getDrawerMemory() {
		return $this->getDrawerBaseMemory() + $this->getDrawerAllocMemory();
	}

	/**
	 * Gets the base allocated memory (in bytes) the LoadTest and Drawers started with
	 * @return int
	 */
	public function getBaseMemory() {
		return $this->getDeskBaseMemory() + $this->getDrawerBaseMemory();
	}

	/**
	 * Gets the additional allocated memory (in bytes) the LoadTest and Drawers used
	 * @return int
	 */
	public function getAllocMemory() {
		return $this->getDeskAllocMemory() + $this->getDrawerAllocMemory();
	}

	/**
	 * Gets the total allocated memory (in bytes) the LoadTest and Drawers used
	 * @return int
	 */
	public function getMemory() {
		return $this->getBaseMemory() + $this->getAllocMemory();
	}

	/**
	 * Gets the total time this LoadTest has been running
	 * @return float
	 */
	public function getTotalTime() {
		return microtime(true) - $this->start_time;
	}

	/**
	 * Gets the total time Drawers have been working on Bucks
	 * @return float
	 */
	public function getWorkTime() {
		return $this->work_time;
	}

	/**
	 * Gets the total time Drawers were not working
	 * @return float
	 */
	public function getIdleTime() {
		$total_time = $this->getTotalTime();
		$work_time  = $this->getWorkTime();
		return $total_time > $work_time ? ($total_time - $work_time) : $total_time;
	}

	/**
	 * Gets the count of Desks in this LoadTest
	 * @return int
	 */
	public function getDeskCount() {
		return count($this->desks);
	}

	/**
	 * Gets the number of active Drawers connected to all Desks in this LoadTest
	 * @return int
	 */
	public function getActiveDrawerCount() {
		$drawers = 0;
		foreach ($this->desks as $desk)
			$drawers += $desk->getActiveDrawerCount();
		return $drawers;
	}

	/**
	 * Gets the number of Spammers in this LoadTest
	 * @return int
	 */
	public function getSpammerCount() {
		$count = 0;
		foreach ($this->spammers as $spammer) {
			$status = proc_get_status($spammer);
			if ($status['running']) $count++;
		}
		return $count;
	}

	/**
	 * Gets the number of Bucks enqueued across all Desks in this LoadTest
	 * @return int
	 */
	public function getBucksEnqueuedCount() {
		return $this->bucks_enqueued;
	}

	/**
	 * Gets the number of Bucks running on Drawers
	 * @return int
	 */
	public function getBucksRunningCount() {
		return $this->bucks_running;
	}

	/**
	 * Gets the number of Bucks successfully completed from Drawers
	 * @return int
	 */
	public function getBucksCompletedCount() {
		return $this->bucks_completed;
	}

	/**
	 * Gets the number of all Bucks that are in, or have passed through, the system
	 * @return int
	 */
	public function getBucksCount() {
		return $this->getBucksEnqueuedCount() + $this->getBucksCompletedCount() + $this->getBucksRunningCount();
	}

	/**
	 * Creates the command for spawning a Spammer
	 * @return string
	 */
	private function getSpammerCommand() {
		$command[] = 'php';
		$command[] = 'bin/spammer.php';
		$command[] = "--job_duration_max={$this->job_duration_max}";
		$command[] = "--job_delay_max={$this->job_delay_max}";
		$command[] = '--';
		foreach ($this->ports as $port)
			$command[] = $port;
		return implode(' ', $command);
	}

	/**
	 * Spawns a Spammer for this LoadTest
	 * @return resource The created Spammer
	 * @throws \truman\core\Exception if unable to open the Spammer process
	 */
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

	/**
	 * Spawns a Desk for this LoadTest
	 * @return Desk The created Desk
	 */
	public function spawnDesk() {
		$desk = new Desk($this->port, [
			Desk::OPTION_RESULT_RECEIVED_HANDLER => [$this, 'onBuckCompleted'],
			Desk::OPTION_BUCK_RECEIVED_HANDLER   => [$this, 'onBuckEnqueued'],
			Desk::OPTION_BUCK_PROCESSED_HANDLER  => [$this, 'onBuckRunning'],
			Desk::OPTION_DRAWER_COUNT            => $this->original_drawer_count
		]);
		$this->desks[(string)$desk] = $desk;
		$this->ports[(string)$desk] = $this->port++;
		return $desk;
	}

	/**
	 * Handler for when Bucks are completed by the Drawers
	 * @param Result $result
	 */
	public function onBuckCompleted(Result $result) {
		$this->bucks_running--;
		$this->bucks_completed++;
		$this->drawer_memory[$result->getPid()] = $result->getMemory();
		$this->drawer_base_memory[$result->getPid()] = $result->getMemoryBase();
		$this->work_time += $result->getRuntime();
		$this->dirty = true;
	}

	/**
	 * Handler for when Bucks are received by the Desks
	 */
	public function onBuckEnqueued() {
		$this->bucks_enqueued++;
		$this->dirty = true;
	}

	/**
	 * Handler for when Bucks are processed by the Desks
	 */
	public function onBuckRunning() {
		$this->bucks_enqueued--;
		$this->bucks_running++;
		$this->dirty = true;
	}

	/**
	 * Utility method for formatting output of the load test
	 */
	private static function format(&$model, $key, $value, $type = 'int', $pad = 20) {
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