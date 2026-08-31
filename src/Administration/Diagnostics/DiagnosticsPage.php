<?php
/**
 * Read-only Support Chat diagnostics admin page (ADR-0015 §3).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Administration\Diagnostics;

use UniversalSupportChat\AI\Knowledge\KnowledgeSourceRepository;
use UniversalSupportChat\AI\Provider\ProviderKeyManager;
use UniversalSupportChat\AI\Turn\AiTurnRepository;
use UniversalSupportChat\Administration\Hub\HubPage;
use UniversalSupportChat\Administration\Settings\SupportChatSettingsPage;
use UniversalSupportChat\Audit\AuditLogRepository;
use UniversalSupportChat\Availability\AvailabilityService;
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
	 * Availability service (read-only aggregates only), or null.
	 *
	 * @var AvailabilityService|null
	 */
	private ?AvailabilityService $availability;

	/**
	 * AI provider key manager (SC-M07), or null.
	 *
	 * @var ProviderKeyManager|null
	 */
	private ?ProviderKeyManager $ai_keys;

	/**
	 * AI turn repository (SC-M07), or null.
	 *
	 * @var AiTurnRepository|null
	 */
	private ?AiTurnRepository $ai_turns;

	/**
	 * AI knowledge source repository (SC-M07), or null.
	 *
	 * @var KnowledgeSourceRepository|null
	 */
	private ?KnowledgeSourceRepository $ai_knowledge;

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth            $schema_health Schema health.
	 * @param AuditLogRepository      $audit_repo    Audit repository.
	 * @param CredentialVault         $vault         Credential vault.
	 * @param Settings                $settings      Settings owner.
	 * @param PeerRepository          $peers         Peer store (read-only).
	 * @param DispatchOutboxRepository $outbox        Dispatch outbox (read-only).
	 * @param AvailabilityService|null $availability   Availability service (read-only).
	 * @param ProviderKeyManager|null       $ai_keys      AI provider key manager (SC-M07).
	 * @param AiTurnRepository|null          $ai_turns     AI turn repository (SC-M07).
	 * @param KnowledgeSourceRepository|null $ai_knowledge AI knowledge source repository (SC-M07).
	 */
	public function __construct(
		SchemaHealth $schema_health,
		AuditLogRepository $audit_repo,
		CredentialVault $vault,
		Settings $settings,
		PeerRepository $peers,
		DispatchOutboxRepository $outbox,
		?AvailabilityService $availability = null,
		?ProviderKeyManager $ai_keys = null,
		?AiTurnRepository $ai_turns = null,
		?KnowledgeSourceRepository $ai_knowledge = null
	) {
		$this->schema_health = $schema_health;
		$this->audit_repo    = $audit_repo;
		$this->vault         = $vault;
		$this->settings      = $settings;
		$this->peers         = $peers;
		$this->outbox        = $outbox;
		$this->availability  = $availability;
		$this->ai_keys       = $ai_keys;
		$this->ai_turns      = $ai_turns;
		$this->ai_knowledge  = $ai_knowledge;
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

		if ( null !== $this->availability ) {
			$schedule_valid = $this->availability->schedule_config_is_valid();
			$override       = $this->availability->current_override();
			$expiry_label   = 'n/a';

			if ( null !== $override ) {
				$expiry_label = null === $override->expires_at()
					? 'until cleared'
					: wp_date( 'Y-m-d H:i', $override->expires_at() );
			}

			$this->row( __( 'Availability — visitor state', 'universal-support-chat' ), $this->availability->resolve_state()->value );
			$this->row( __( 'Availability — mode', 'universal-support-chat' ), $this->availability->current_mode() );
			$this->row( __( 'Availability — override expiry', 'universal-support-chat' ), (string) $expiry_label );
			$this->row( __( 'Availability — schedule config valid', 'universal-support-chat' ), $schedule_valid ? 'yes' : 'no' );
		}
		$this->render_ai_rows( $values );

		echo '</tbody></table>';

		if ( null !== $this->availability && ! $this->availability->schedule_config_is_valid() ) {
			echo '<div class="notice notice-warning inline"><p>'
				. esc_html__( 'The stored support schedule or an exception could not be read, so visitors are being shown as offline (fail-safe). Re-save the schedule on the Settings page to fix it.', 'universal-support-chat' )
				. '</p></div>';
		}

		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . SupportChatSettingsPage::SLUG ) ),
			esc_html__( 'Open Settings →', 'universal-support-chat' )
		);

		echo '</div>';
	}

	/**
	 * Renders the SC-M07 AI-assistant diagnostics rows (safe aggregates only:
	 * enabled/disabled, configured yes/no + fail-closed probe, model label,
	 * knowledge source counts, AI turns today vs cap, handoffs today, last
	 * outcome / last provider error class). No credential, prompt, response,
	 * timestamp, identifier, or raw error (ADR-0018 §11, ADR-0015 §3).
	 *
	 * @param array<string, mixed> $values Resolved settings.
	 */
	private function render_ai_rows( array $values ): void {
		if ( null === $this->ai_keys || null === $this->ai_turns || null === $this->ai_knowledge ) {
			return;
		}

		$this->row( __( 'AI assistant', 'universal-support-chat' ), empty( $values['ai_enabled'] ) ? 'disabled' : 'enabled' );
		$this->row(
			__( 'AI provider key', 'universal-support-chat' ),
			$this->ai_keys->is_configured()
				? ( $this->ai_keys->decrypts_ok() ? 'configured' : 'configured (fail-closed)' )
				: 'not configured'
		);
		$this->row( __( 'AI model', 'universal-support-chat' ), (string) ( $values['ai_model'] ?? '' ) );

		$by_status = $this->ai_knowledge->count_by_status();
		$this->row(
			__( 'AI knowledge sources (approved / stale / revoked)', 'universal-support-chat' ),
			sprintf(
				'%d / %d / %d',
				$by_status[ KnowledgeSourceRepository::STATUS_APPROVED ] ?? 0,
				$by_status[ KnowledgeSourceRepository::STATUS_STALE ] ?? 0,
				$by_status[ KnowledgeSourceRepository::STATUS_REVOKED ] ?? 0
			)
		);

		$since = gmdate( 'Y-m-d' ) . ' 00:00:00';
		$this->row(
			__( 'AI turns today vs daily cap', 'universal-support-chat' ),
			$this->ai_turns->count_created_since( $since ) . ' / ' . (int) ( $values['ai_daily_request_cap'] ?? 0 )
		);
		$this->row( __( 'AI handoffs today', 'universal-support-chat' ), (string) $this->ai_turns->count_handoffs_since( $since ) );

		$recent = $this->ai_turns->most_recent();
		$this->row( __( 'AI last turn status', 'universal-support-chat' ), null !== $recent ? (string) $recent['status'] : 'n/a' );
		$this->row(
			__( 'AI last provider error class', 'universal-support-chat' ),
			( null !== $recent && null !== ( $recent['provider_error_class'] ?? null ) ) ? (string) $recent['provider_error_class'] : 'none'
		);
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
