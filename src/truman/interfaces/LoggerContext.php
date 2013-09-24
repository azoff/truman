<? namespace truman\interfaces;

interface LoggerContext {

	public function __toString();

	public function getLoggerType();

	public function getLoggerId();

	public function getLogger();

}