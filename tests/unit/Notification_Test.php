<? namespace truman\test\unit;
require_once dirname(dirname(__DIR__)) . '/autoload.php';

use truman\core\Buck;
use truman\core\Client;
use truman\core\Notification;

class Notification_Test extends \PHPUnit_Framework_TestCase {
	
	public function testClientUpdate() {
		$client = new Client();
		$notif = new Notification(Notification::TYPE_CLIENT_UPDATE, $client->getSignature());
		$this->assertTrue($notif->isClientUpdate());
		$this->assertEquals($client->getSignature(), $notif->getNotice());
	}

	public function testDeskRefresh() {
		$notif = new Notification(Notification::TYPE_DESK_REFRESH);
		$this->assertTrue($notif->isDeskRefresh());
	}

	public function testDrawerSignal() {
		$notif = new Notification(Notification::TYPE_DRAWER_SIGNAL);
		$this->assertTrue($notif->isDrawerSignal());
	}

}