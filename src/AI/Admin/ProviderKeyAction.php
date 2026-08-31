<?php
/**
 * AI provider API-key admin-post action (ADR-0018 §7).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\AI\Admin;

use UniversalSupportChat\AI\Provider\ProviderKeyManager;
use UniversalSupportChat\Administration\Settings\SupportChatSettingsPage;
use UniversalSupportChat\Audit\AuditLogger;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;
use UniversalSupportChat\Privacy\Classification;

/**
 * The single write path for the OpenAI API token (ADR-0018 §7). Nonce +
 * `MANAGE` gated; deliberately NOT a Settings-API field, because a secret
 * must never sit in the sanitised, rendered-back `universal_support_chat_settings`
 * option. Modelled on {@see \UniversalSupportChat\Availability\Admin\OverrideAction}.
 *
 * The token itself is never logged, never echoed, and never placed in an
 * audit context — only a `set` / `rotated` / `cleared` marker.
 */
final class ProviderKeyAction {

	public const ACTION = 'universal_support_chat_ai_provider_key';
	public const NONCE  = 'usc_ai_provider_key';

	/**
	 * Provider key manager.
	 *
	 * @var ProviderKeyManager
	 */
	private ProviderKeyManager $keys;

	/**
	 * Audit logger, or null.
	 *
	 * @var AuditLogger|null
	 */
	private ?AuditLogger $audit;

	/**
	 * Constructor.
	 *
	 * @param ProviderKeyManager $keys  Provider key manager.
	 * @param AuditLogger|null    $audit Optional audit logger.
	 */
	public function __construct( ProviderKeyManager $keys, ?AuditLogger $audit = null ) {
		$this->keys  = $keys;
		$this->audit = $audit;
	}

	/**
	 * Registers the admin-post hook.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Handles a set / rotate / clear submission.
	 */
	public function handle(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die(
				esc_html__( 'You do not have permission to change the AI provider key.', 'universal-support-chat' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified immediately above.
		$op = isset( $_POST['provider_key_op'] ) ? sanitize_key( wp_unslash( (string) $_POST['provider_key_op'] ) ) : '';

		if ( 'clear' === $op ) {
			$existed = $this->keys->is_configured();
			$this->keys->clear();

			if ( $existed ) {
				$this->audit_event( 'cleared' );
			}

			$this->redirect( 'ai_key_cleared' );
		}

		$was_configured = $this->keys->is_configured();
		$token          = isset( $_POST['provider_api_key'] ) ? trim( (string) wp_unslash( $_POST['provider_api_key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $token ) {
			$this->redirect( 'ai_key_invalid' );
		}

		if ( ! $this->keys->set( $token ) ) {
			$this->redirect( 'ai_key_error' );
		}

		$this->audit_event( $was_configured ? 'rotated' : 'set' );
		$this->redirect( 'ai_key_set' );
	}

	/**
	 * Records an `ai.token_rotated` audit event carrying only a marker.
	 *
	 * @param string $marker One of `set` / `rotated` / `cleared`.
	 */
	private function audit_event( string $marker ): void {
		if ( null === $this->audit ) {
			return;
		}

		$this->audit->record(
			'ai.token_rotated',
			'operator',
			get_current_user_id(),
			array( 'op' => $marker ),
			array( 'op' => Classification::PUBLIC ),
			Classification::INTERNAL
		);
	}

	/**
	 * Redirects back to the Settings page with a notice code.
	 *
	 * @param string $notice Notice code.
	 */
	private function redirect( string $notice ): void {
		wp_safe_redirect(
			add_query_arg( 'usc_notice', $notice, admin_url( 'admin.php?page=' . SupportChatSettingsPage::SLUG ) )
		);
		exit;
	}
}
