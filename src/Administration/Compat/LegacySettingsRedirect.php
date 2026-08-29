<?php
/**
 * Backward-compatible redirect for the legacy Settings-menu URL (ADR-0015 §4).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Administration\Compat;

use UniversalSupportChat\Administration\Diagnostics\DiagnosticsPage;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;

/**
 * Before ADR-0015 the plugin's read-only status table lived at
 * `options-general.php?page=universal-support-chat` and the Plugins-row
 * "Settings" link pointed there. ADR-0015 moves that content to the
 * Diagnostics submenu under the Support Chat menu. This class keeps the old
 * URL working: an authorised (`MANAGE`) user is sent to the new Diagnostics
 * page with a temporary (302) redirect; anyone else falls through to
 * WordPress's own "not allowed" screen. The old slug is never re-registered.
 */
final class LegacySettingsRedirect {

	/**
	 * The retired page slug that must keep resolving.
	 */
	public const LEGACY_SLUG = 'universal-support-chat';

	/**
	 * Registers the handler.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_redirect' ) );
	}

	/**
	 * Pure decision: returns the redirect target URL for the given request
	 * context, or null when no redirect should happen. No output, no
	 * redirect, no `exit`, no state change.
	 *
	 * Returns null for an unauthorised user (so WordPress renders its own
	 * denial), for any unrelated request, and for the redirect target's own
	 * request — so no redirect loop can form.
	 *
	 * @param string $pagenow Current `$pagenow`.
	 * @param string $page     Current `page` query var (already sanitised).
	 */
	public function resolve_target( string $pagenow, string $page ): ?string {
		if ( 'options-general.php' !== $pagenow ) {
			return null;
		}

		if ( self::LEGACY_SLUG !== $page ) {
			return null;
		}

		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			return null;
		}

		return admin_url( 'admin.php?page=' . DiagnosticsPage::SLUG );
	}

	/**
	 * Thin `admin_init` wrapper: resolves the target and, only when there is
	 * one, performs the redirect. `exit` is reached only if
	 * `wp_safe_redirect()` returns truthy.
	 */
	public function maybe_redirect(): void {
		global $pagenow;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin URL routing; no state change.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		$url = $this->resolve_target( is_string( $pagenow ) ? $pagenow : '', $page );

		if ( null === $url ) {
			return;
		}

		if ( wp_safe_redirect( $url, 302 ) ) {
			exit;
		}
	}
}
