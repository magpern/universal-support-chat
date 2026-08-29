<?php
/**
 * Read-only Support Chat diagnostics admin page (ADR-0015 §3).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Administration\Diagnostics;

use UniversalSupportChat\Administration\Hub\HubPage;
use UniversalSupportChat\Administration\Settings\SupportChatSettingsPage;
use UniversalSupportChat\Audit\AuditLogRepository;
use UniversalSupportChat\ChannelContract\Auth\PeerRecord;
use UniversalSupportChat\ChannelContract\Auth\PeerRepository;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;
use UniversalSupportChat\Core\Configuration\Settings;
use UniversalSupportChat\Core\Security\CredentialState;
use UniversalSupportChat\Core\Security\CredentialUnavailableException;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalSupportChat\TelegramDispatch\DispatchOutboxRepository;
use UniversalSupportChat\TelegramDispatch\TelegramDispatchService;

/**
 * Read-only technical status surface: plugin version, schema health, vault
 * self-check, recent audit count, and — added by ADR-0015 — safe aggregate
 * Telegram dispatch / pairing / outbox state.
 *
 * It renders no form and no input, and it never displays credentials, keys
 * or key IDs, routes, tokens, webhook data, message or note content,
 * conversation or Telegram identifiers, peer IDs or timestamps, raw errors,
 * or stack traces (ADR-0015 §3). Only booleans, fixed enum labels, integer
 * counts, and the version string reach the page.
 */
final class DiagnosticsPage {

	/**
	 * Submenu page slug (`admin.php?page=<SLUG>`).
	 */
	public const SLUG = 'universal-support-chat-diagnostics';

	/**
	 * Schema health.
	 *
	 * @var SchemaHealth
	 */
	private SchemaHealth $schema_health;

	/**
	 * Audit repository.
	 *
	 * @var AuditLogRepository
	 */
	private AuditLogRepository $audit_repo;

	/**
	 * Credential vault.
	 *
	 * @var CredentialVault
	 */
	private CredentialVault $vault;

	/**
	 * Settings owner.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Peer store (read-only).
	 *
	 * @var PeerRepository
	 */
	private PeerRepository $peers;

	/**
	 * Dispatch outbox (read-only aggregates only).
	 *
	 * @var DispatchOutboxRepository
	 */
	private DispatchOutboxRepository $outbox;

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth            $schema_health Schema health.
	 * @param AuditLogRepository      $audit_repo    Audit repository.
	 * @param CredentialVault         $vault         Credential vault.
	 * @param Settings                $settings      Settings owner.
	 * @param PeerRepository          $peers         Peer store (read-only).
	 * @param DispatchOutboxRepository $outbox        Dispatch outbox (read-only).
	 */
	public function __construct(
		SchemaHealth $schema_health,
		AuditLogRepository $audit_repo,
		CredentialVault $vault,
		Settings $settings,
		PeerRepository $peers,
		DispatchOutboxRepository $outbox
	) {
		$this->schema_health = $schema_health;
		$this->audit_repo    = $audit_repo;
		$this->vault         = $vault;
		$this->settings      = $settings;
		$this->peers         = $peers;
		$this->outbox        = $outbox;
	}

	/**
	 * Registers the admin page.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	/**
	 * Adds the Diagnostics submenu under the existing Support Chat menu.
	 */
	public function add_menu(): void {
		add_submenu_page(
			HubPage::SLUG,
			__( 'Support Chat Diagnostics', 'universal-support-chat' ),
			__( 'Diagnostics', 'universal-support-chat' ),
			CapabilityRegistrar::MANAGE,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Renders diagnostics (read-only).
	 */
	public function render(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'universal-support-chat' ) );
		}

		$vault_ok = false;

		try {
			$stored   = $this->vault->encrypt( 'diagnostics-probe', 'diagnostics.self_test' );
			$result   = $this->vault->decrypt( $stored, 'diagnostics.self_test' );
			$vault_ok = CredentialState::AVAILABLE === $result->state();
		} catch ( CredentialUnavailableException $exception ) {
			$vault_ok = false;
		}

		$recent          = $this->audit_repo->recent( 5 );
		$schema_ok       = $this->schema_health->is_available();
		$failure_code    = $this->schema_health->failure_code();
		$values          = $this->settings->get();
		$dispatch_label  = empty( $values['telegram_dispatch_enabled'] ) ? 'disabled' : 'enabled';
		$peer            = $this->peers->find_by_peer_id( TelegramDispatchService::PEER_ID );
		$outbox_by_state = $this->outbox->count_by_state();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Support Chat Diagnostics', 'universal-support-chat' ) . '</h1>';

		echo '<table class="widefat striped"><tbody>';
		$this->row( __( 'Plugin version', 'universal-support-chat' ), UNIVERSAL_SUPPORT_CHAT_VERSION );
		$this->row( __( 'Schema available', 'universal-support-chat' ), $schema_ok ? 'yes' : 'no' );

		if ( ! $schema_ok && null !== $failure_code ) {
			$this->row( __( 'Schema failure code', 'universal-support-chat' ), $failure_code->value );
		}

		$this->row( __( 'Vault self-check', 'universal-support-chat' ), $vault_ok ? 'ok' : 'fail-closed' );
		$this->row( __( 'Recent audit rows (last 5)', 'universal-support-chat' ), (string) count( $recent ) );
		$this->row( __( 'Telegram dispatch', 'universal-support-chat' ), $dispatch_label );
		$this->row( __( 'Telegram adapter pairing', 'universal-support-chat' ), self::pairing_label( $peer ) );
		$this->row( __( 'Telegram adapter usable', 'universal-support-chat' ), ( null !== $peer && $peer->is_usable() ) ? 'yes' : 'no' );
		$this->row( __( 'Dispatch outbox (rows by state)', 'universal-support-chat' ), self::format_state_counts( $outbox_by_state ) );
		echo '</tbody></table>';

		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . SupportChatSettingsPage::SLUG ) ),
			esc_html__( 'Open Settings →', 'universal-support-chat' )
		);

		echo '</div>';
	}

	/**
	 * Prints one escaped label/value table row.
	 *
	 * @param string $label Row label.
	 * @param string $value Row value (a boolean word, enum label, count, or version).
	 */
	private function row( string $label, string $value ): void {
		echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}

	/**
	 * Maps a peer record (or its absence) to a plain operator-facing label.
	 *
	 * @param PeerRecord|null $peer The `universal-telegram` peer row, or null.
	 */
	private static function pairing_label( ?PeerRecord $peer ): string {
		if ( null === $peer ) {
			return 'not paired';
		}

		switch ( $peer->pairing_state() ) {
			case 'revoked':
				return 'pairing revoked';
			case 'expired':
				return 'pairing expired';
			case 'paired_disabled':
				return 'paired (disabled)';
			default:
				return 'paired';
		}
	}

	/**
	 * Formats the outbox state-count aggregate as a short fixed-vocabulary
	 * string, e.g. "pending: 2, delivered: 5". Counts only — no identifiers.
	 *
	 * @param array<string, int> $counts State => count.
	 */
	private static function format_state_counts( array $counts ): string {
		if ( array() === $counts ) {
			return 'none';
		}

		$parts = array();
		foreach ( $counts as $state => $count ) {
			$parts[] = $state . ': ' . (int) $count;
		}

		return implode( ', ', $parts );
	}
}
