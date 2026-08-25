<?php
/**
 * Plugin deactivation.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Core\Lifecycle;

/**
 * Deactivation removes nothing; uninstall is the only place data or
 * capabilities are removed.
 */
final class Deactivator {

	/**
	 * Deactivation callback.
	 */
	public function deactivate(): void {}
}
