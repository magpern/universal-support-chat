<?php
/**
 * Capability grant and revoke lifecycle.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Core\Capabilities;

/**
 * Owns the plugin's WordPress capabilities. SC-M00 defines a single
 * administrator manage capability; later milestones may add more.
 */
final class CapabilityRegistrar {

	public const MANAGE = 'universal_support_chat_manage';

	/**
	 * Grants every capability to the administrator role.
	 */
	public function grant_to_administrator(): void {
		$role = get_role( 'administrator' );

		if ( null !== $role ) {
			$role->add_cap( self::MANAGE );
		}
	}

	/**
	 * Revokes every capability from every role.
	 */
	public function revoke_from_all_roles(): void {
		$wp_roles = wp_roles();

		foreach ( $wp_roles->role_objects as $role ) {
			$role->remove_cap( self::MANAGE );
		}
	}
}
