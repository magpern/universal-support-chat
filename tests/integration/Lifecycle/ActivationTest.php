<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Lifecycle;

use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;
use UniversalSupportChat\Core\Lifecycle\Activator;
use UniversalSupportChat\Core\Plugin;
use WP_UnitTestCase;

final class ActivationTest extends WP_UnitTestCase {

	public function test_activation_grants_manage_capability(): void {
		$role = get_role( 'administrator' );
		$role->remove_cap( CapabilityRegistrar::MANAGE );

		( new Activator() )->activate( false );

		$this->assertTrue( get_role( 'administrator' )->has_cap( CapabilityRegistrar::MANAGE ) );
	}

	public function test_plugin_boots_and_migrates(): void {
		Plugin::instance()->init();
		$health = Plugin::instance()->schema_health();
		$this->assertNotNull( $health );
		$this->assertTrue( $health->is_available() );
		$this->assertSame( 8, (int) get_option( 'universal_support_chat_db_version', 0 ) );
	}
}
