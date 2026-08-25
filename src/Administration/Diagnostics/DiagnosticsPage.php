<?php
/**
 * Minimal diagnostics admin page.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Administration\Diagnostics;

use UniversalSupportChat\Audit\AuditLogRepository;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;
use UniversalSupportChat\Core\Security\CredentialState;
use UniversalSupportChat\Core\Security\CredentialUnavailableException;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Persistence\SchemaHealth;

/**
 * SC-M00 diagnostics surface: version, schema health, vault self-check.
 */
final class DiagnosticsPage {

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
	 * Constructor.
	 *
	 * @param SchemaHealth       $schema_health Schema health.
	 * @param AuditLogRepository $audit_repo    Audit repository.
	 * @param CredentialVault    $vault         Credential vault.
	 */
	public function __construct( SchemaHealth $schema_health, AuditLogRepository $audit_repo, CredentialVault $vault ) {
		$this->schema_health = $schema_health;
		$this->audit_repo    = $audit_repo;
		$this->vault         = $vault;
	}

	/**
	 * Registers the admin page.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	/**
	 * Adds the Settings submenu page.
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'Universal Support Chat', 'universal-support-chat' ),
			__( 'Support Chat', 'universal-support-chat' ),
			CapabilityRegistrar::MANAGE,
			'universal-support-chat',
			array( $this, 'render' )
		);
	}

	/**
	 * Renders diagnostics.
	 */
	public function render(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			return;
		}

		$vault_ok = false;

		try {
			$stored   = $this->vault->encrypt( 'diagnostics-probe', 'diagnostics.self_test' );
			$result   = $this->vault->decrypt( $stored, 'diagnostics.self_test' );
			$vault_ok = CredentialState::AVAILABLE === $result->state();
		} catch ( CredentialUnavailableException $exception ) {
			$vault_ok = false;
		}

		$recent = $this->audit_repo->recent( 5 );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Universal Support Chat', 'universal-support-chat' ) . '</h1>';
		echo '<table class="widefat striped"><tbody>';
		echo '<tr><th>' . esc_html__( 'Plugin version', 'universal-support-chat' ) . '</th><td>' . esc_html( UNIVERSAL_SUPPORT_CHAT_VERSION ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Schema available', 'universal-support-chat' ) . '</th><td>' . esc_html( $this->schema_health->is_available() ? 'yes' : 'no' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Vault self-check', 'universal-support-chat' ) . '</th><td>' . esc_html( $vault_ok ? 'ok' : 'fail-closed' ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Recent audit rows', 'universal-support-chat' ) . '</th><td>' . esc_html( (string) count( $recent ) ) . '</td></tr>';
		echo '</tbody></table>';
		echo '</div>';
	}
}
