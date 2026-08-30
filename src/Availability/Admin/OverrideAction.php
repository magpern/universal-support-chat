<?php
/**
 * Manual availability override admin-post action.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Availability\Admin;

use UniversalSupportChat\Administration\Hub\HubPage;
use UniversalSupportChat\Audit\AuditLogger;
use UniversalSupportChat\Availability\AvailabilityOverride;
use UniversalSupportChat\Availability\AvailabilityService;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;
use UniversalSupportChat\Privacy\Classification;

/**
 * The single write path for the manual override (ADR-0017 §6). Nonce +
 * `MANAGE` gated; it is deliberately NOT a Settings-API field, because the
 * override is auto-expiring runtime state rather than fixed-shape config.
 */
final class OverrideAction {

	public const ACTION = 'universal_support_chat_availability_override';
	public const NONCE  = 'usc_availability_override';

	/**
	 * Constructor.
	 *
	 * @param AuditLogger|null $audit Optional audit logger.
	 */
	public function __construct(
		private readonly ?AuditLogger $audit = null
	) {}

	/**
	 * Registers the admin-post hook.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Handles a set-or-clear submission.
	 */
	public function handle(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die(
				esc_html__( 'You do not have permission to change support availability.', 'universal-support-chat' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified immediately above.
		$op = isset( $_POST['override_op'] ) ? sanitize_key( wp_unslash( (string) $_POST['override_op'] ) ) : '';

		if ( 'clear' === $op ) {
			$existed = false !== get_option( AvailabilityService::OVERRIDE_OPTION, false );
			delete_option( AvailabilityService::OVERRIDE_OPTION );

			if ( $existed ) {
				$this->audit_event( 'availability.override_cleared', array() );
			}

			$this->redirect( 'override_cleared' );
		}

		$mode = isset( $_POST['override_mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['override_mode'] ) ) : '';

		if ( AvailabilityOverride::MODE_FORCE_ONLINE !== $mode && AvailabilityOverride::MODE_FORCE_OFFLINE !== $mode ) {
			$this->redirect( 'override_invalid' );
		}

		$expires_at = null;
		$raw_expiry = isset( $_POST['override_expires_at'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['override_expires_at'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' !== $raw_expiry ) {
			$parsed = date_create_immutable( $raw_expiry, wp_timezone() );

			if ( false === $parsed || $parsed->getTimestamp() <= time() ) {
				$this->redirect( 'override_invalid' );
			}

			$expires_at = $parsed->getTimestamp();
		}

		$override = new AvailabilityOverride( $mode, $expires_at, get_current_user_id(), time() );
		update_option( AvailabilityService::OVERRIDE_OPTION, $override->to_option(), true );

		$this->audit_event(
			'availability.override_set',
			array(
				'mode'   => $mode,
				'expiry' => null === $expires_at ? 'until_cleared' : 'dated',
			)
		);

		$this->redirect( 'override_set' );
	}

	/**
	 * Records an availability audit event.
	 *
	 * @param string               $action  Audit action.
	 * @param array<string, string> $context Safe context (mode / expiry markers only).
	 */
	private function audit_event( string $action, array $context ): void {
		if ( null === $this->audit ) {
			return;
		}

		$classification = array();
		foreach ( array_keys( $context ) as $key ) {
			$classification[ $key ] = Classification::PUBLIC;
		}

		$this->audit->record(
			$action,
			'operator',
			get_current_user_id(),
			$context,
			$classification,
			Classification::INTERNAL
		);
	}

	/**
	 * Redirects back to the Hub with a notice code.
	 *
	 * @param string $notice Notice code.
	 */
	private function redirect( string $notice ): void {
		wp_safe_redirect(
			add_query_arg( 'usc_notice', $notice, admin_url( 'admin.php?page=' . HubPage::SLUG ) )
		);
		exit;
	}
}
