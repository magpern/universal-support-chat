<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Core\Capabilities;

use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;
use WP_UnitTestCase;

final class CapabilityRegistrarTest extends WP_UnitTestCase {

	public function test_grant_and_revoke_lifecycle(): void {
		$registrar = new CapabilityRegistrar();
		$role      = get_role( 'administrator' );

		$role->remove_cap( CapabilityRegistrar::MANAGE );
		$this->assertFalse( $role->has_cap( CapabilityRegistrar::MANAGE ) );

		$registrar->grant_to_administrator();
		$this->assertTrue( get_role( 'administrator' )->has_cap( CapabilityRegistrar::MANAGE ) );

		$registrar->revoke_from_all_roles();
		$this->assertFalse( get_role( 'administrator' )->has_cap( CapabilityRegistrar::MANAGE ) );
	}
}
