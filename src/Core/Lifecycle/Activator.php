<?php
/**
 * Plugin activation.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Core\Lifecycle;

use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;

/**
 * Network-wide multisite activation is refused. Schema migration runs
 * lazily on the next plugins_loaded via Plugin::init().
 */
final class Activator {

	/**
	 * Activation callback.
	 *
	 * @param bool $network_wide Whether WordPress is activating network-wide.
	 */
	public function activate( bool $network_wide ): void {
		if ( $network_wide ) {
			wp_die(
				esc_html__(
					'Universal Support Chat cannot be network-activated. Activate it individually on each site that needs it.',
					'universal-support-chat'
				)
			);
		}

		( new CapabilityRegistrar() )->grant_to_administrator();
	}
}
