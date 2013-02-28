<? require_once dirname(__DIR__).'/autoload.php';

function execute(Truman_Buck $buck) {
	ob_start();
	$data['buck'] = $buck;
	try {
		$data['retval'] = @$buck->invoke();
	} catch (Exception $ex) {
		$data['exception'] = $ex;
	} if ($error = error_get_last()) {
		$data['error'] = $error;
	} if ($output = ob_get_clean()) {
		$data['output'] = $output;
	}
	return Truman_Result::newInstance(
		isset($data['retval']) && $data['retval'],
		(object) $data
	);
}

function main(array $include_paths = array()) {
	foreach ($include_paths as $include_path) {
		require_once $include_path;
	} do {
		$input = trim(fgets(STDIN));
		if (isset($input{0}) && $input !== 'close') {
			$buck = @unserialize($input);
			if ($buck instanceof Truman_Buck)
				echo execute($buck)->asXML();
			else
				Truman_Exception::throwNew('main', "Unable to understand '{$input}'");
		}
	} while($input !== 'close');
	echo "Closed!\n";
}

$args = isset($argv) ? array_slice($argv, 1) : array();

main($args);