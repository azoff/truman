<? namespace truman\test\load;

use truman\core\Client;
use truman\core\Buck;
use truman\core\Exception;
use truman\core\Util;

/**
 * Class Spammer Spams the LoadTest with Bucks
 * @package truman\test\load
 */
class Spammer {


	const OPTION_TIMEOUT          = 'timeout';
	const OPTION_JOB_DELAY_MAX    = 'job_delay_max';
	const OPTION_JOB_DURATION_MAX = 'job_duration_max';

	private static $_DEFAULT_OPTIONS = [
		self::OPTION_TIMEOUT          => 0,
		self::OPTION_JOB_DURATION_MAX => 2000000, // max two seconds running jobs
		self::OPTION_JOB_DELAY_MAX    => 2000000, // max two seconds between sending jobs
	];

	/**
	 * Runs a Spammer from the command line
	 * @param array $argv The arguments passed into the command line
	 * @param array $option_keys Any options to set by command line (defaults to all options)
	 */
	public static function main(array $argv, array $option_keys = null) {
		$desk_specs = Util::getArgs($argv);
		$options    = Util::getOptions($option_keys, self::$_DEFAULT_OPTIONS);
		try {
			$spammer = new Spammer($desk_specs, $options);
			exit($spammer->poll());
		} catch (Exception $ex) {
			error_log("Error: {$ex->getMessage()}");
			exit(1);
		}
	}

	private $client;
	private $timeout;
	private $job_delay_max;
	private $job_duration_max;

	/**
	 * Creates a new Spammer instance
	 * @param array $desk_specs The list of sockets specifications to send Bucks to. Valid values
	 * include anything that can be passed into Socket::__construct(), or a list of such values.
	 * @param array $options Optional settings for the Spammer. See Spammer::$_DEFAULT_OPTIONS
	 * @throws \truman\core\Exception
	 */
	public function __construct(array $desk_specs, array $options = []) {
		if (!count($desk_specs))
			throw new Exception('can not start because no desk specs are defined');
		$options += self::$_DEFAULT_OPTIONS;
		$this->client           = new Client($desk_specs);
		$this->timeout          = $options[self::OPTION_TIMEOUT];
		$this->job_delay_max    = $options[self::OPTION_JOB_DELAY_MAX];
		$this->job_duration_max = $options[self::OPTION_JOB_DURATION_MAX];
	}

	/**
	 * @inheritdoc
	 */
	function __toString() {
		$pid = getmypid();
		return "Spammer<{$pid}>";
	}

	/**
	 * Loops the tick() method until it returns a status code
	 * @return int The status code of the last tick()
	 */
	public function poll() {
		declare(ticks = 1);
		do $status = $this->tick();
		while($status < 0);
		return (int) $status;
	}

	/**
	 * The basic work cycle for a Spammer
	 * @return int
	 */
	public function tick() {

		// send buck
		$this->spam();

		// wait for a bit until ending this tick
		$max_delay = $this->job_delay_max;
		$delay = rand(1, $max_delay);
		usleep($delay);

		return -1;

	}

	/**
	 * Creates and sends a Buck to the target Desk
	 * @return null|Buck
	 */
	public function spam() {
		$max_job_duration = $this->job_duration_max;
		$buck = self::newSpamBuck($max_job_duration);
		return $this->client->sendBuck($buck);
	}

	/**
	 * Creates a spam buck, which sleeps for a certain duration
	 * @param int $max_job_duration The duration to sleep
	 * @return Buck The spam Buck
	 */
	public static function newSpamBuck($max_job_duration) {
		$job_duration = [rand(1, $max_job_duration)];
		return new Buck('truman\test\load\Spammer::work', $job_duration);
	}

	/**
	 * Executor for the spam Buck. It will sleep for a certain amount of time.
	 * @param int $duration The microseconds to sleep
	 * @return int the duration
	 */
	public static function work($duration) {
		print "Working for {$duration} microseconds... ";
		usleep($duration);
		print "Done!";
		return $duration;
	}

}