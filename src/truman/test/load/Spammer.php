<? namespace truman\test\load;

use truman\core\Client;
use truman\core\Buck;
use truman\core\Exception;
use truman\core\Util;

class Spammer {

	private $options, $client;

	private static $_DEFAULT_OPTIONS = [
		'timeout'          => 0,
		'job_duration_max' => 2000000, // max two seconds running jobs
		'job_delay_max'    => 2000000, // max two seconds between sending jobs
	];

	public static function main(array $argv, array $options = null) {
		$options = $options ?: [];
		$desk_specs = Util::getArgs($argv);
		try {
			$spammer = new Spammer($desk_specs, $options);
			exit($spammer->poll());
		} catch (Exception $ex) {
			$pid = getmypid();
			error_log("Spammer<{$pid}> {$ex->getMessage()}");
			exit(1);
		}
	}

	public function __construct(array $desk_specs, array $options = []) {
		if (!count($desk_specs))
			throw new Exception('can not start because no desk specs are defined');
		$this->client  = new Client($desk_specs);
		$this->options = $options + self::$_DEFAULT_OPTIONS;
	}

	function __toString() {
		$pid = getmypid();
		return "Spammer<{$pid}>";
	}

	public function poll() {
		declare(ticks = 1);
		do $status = $this->tick();
		while($status < 0);
		return (int) $status;
	}

	public function tick() {

		// send buck
		if (is_null($buck = $this->spam()))
			return -1;

		// wait for a bit until ending this tick
		$max_delay = $this->options['job_delay_max'];
		$delay = rand(1, $max_delay);
		usleep($delay);

		return -1;

	}

	public function spam() {
		$max_job_duration = $this->options['job_duration_max'];
		$buck = self::newSpamBuck($max_job_duration);
		return $this->client->sendBuck($buck);
	}

	public static function newSpamBuck($max_job_duration) {
		$job_duration = [rand(1, $max_job_duration)];
		return new Buck('truman\test\load\Spammer::work', $job_duration);
	}

	public static function work($duration) {
		print "Working for {$duration} microseconds... ";
		usleep($duration);
		print "Done!";
		return $duration;
	}

}