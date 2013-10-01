<? namespace truman\interfaces;

use truman\core\Logger;

/**
 * Interface LoggerContext Can log events about itself
 * @package truman\interfaces
 */
interface LoggerContext {

	/**
	 * Gets the logger type for Bucks
	 * @return string
	 */
	public function getLoggerType();

	/**
	 * Gets the ID used to represent this object in Logger events
	 * @return string
	 */
	public function getLoggerId();

	/**
	 * Gets the Logger instance for this Buck
	 * @return Logger
	 */
	public function getLogger();

}