<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Administration\Diagnostics;

use UniversalSupportChat\Administration\Diagnostics\DiagnosticsPage;
use UniversalSupportChat\Administration\Settings\SupportChatSettingsPage;
use UniversalSupportChat\Audit\AuditLogRepository;
use UniversalSupportChat\ChannelContract\Auth\PeerRepository;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;
use UniversalSupportChat\Core\Configuration\Settings;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalSupportChat\TelegramDispatch\DispatchOutboxRepository;
use UniversalSupportChat\TelegramDispatch\TelegramDispatchService;
use WP_UnitTestCase;

/**
 * ADR-0015 §3: the read-only Diagnostics page, reparented under the Support
 * Chat menu, with only safe aggregate additions and a strict redaction
 * boundary.
 */
final class DiagnosticsPageTest extends WP_UnitTestCase {

	private const FAKE_PUB_KEY  = 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=';
	private const SECRET_KEY_ID = 'usc_diag_key_id_secret';
	private const SECRET_ROUTE  = 'usc-diag-secret-route/v9/x';

	private SchemaHealth $health;
	private PeerRepository $peers;
	private DispatchOutboxRepository $outbox;

	public function set_up(): void {
		parent::set_up();

		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		( new CapabilityRegistrar() )->grant_to_administrator();

		$this->health = new SchemaHealth();
		$this->peers  = new PeerRepository( $this->health );
		$this->outbox = new DispatchOutboxRepository( $this->health );

		delete_option( Settings::OPTION_NAME );
		$this->truncate();
	}

	public function tear_down(): void {
		delete_option( Settings::OPTION_NAME );
		$this->truncate();
		parent::tear_down();
	}

	private function truncate(): void {
		global $wpdb;
		foreach ( array( Migrator::CHANNEL_PEERS_TABLE, Migrator::TELEGRAM_DISPATCH_TABLE ) as $t ) {
			$table = $wpdb->prefix . $t;
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test cleanup.
		}
	}

	private function page(): DiagnosticsPage {
		return new DiagnosticsPage(
			$this->health,
			new AuditLogRepository( $this->health ),
			new CredentialVault(),
			new Settings(),
			$this->peers,
			$this->outbox
		);
	}

	private function as_manager(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function render(): string {
		$this->as_manager();
		ob_start();
		$this->page()->render();

		return (string) ob_get_clean();
	}

	// ---- menu ----

	public function test_slug_moved_and_submenu_registered_under_the_hub(): void {
		$this->assertSame( 'universal-support-chat-diagnostics', DiagnosticsPage::SLUG );

		global $submenu;
		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test isolation of a core global.
		$this->as_manager();
		$this->page()->add_menu();

		$slugs = array_column( $submenu['universal-support-chat-hub'] ?? array(), 2 );
		$this->assertContains( DiagnosticsPage::SLUG, $slugs );

		foreach ( $submenu['universal-support-chat-hub'] as $row ) {
			if ( DiagnosticsPage::SLUG === $row[2] ) {
				$this->assertSame( 'Diagnostics', $row[0] );
				$this->assertSame( CapabilityRegistrar::MANAGE, $row[1] );
			}
		}
	}

	public function test_render_denies_a_user_without_manage(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( \WPDieException::class );
		$this->page()->render();
	}

	// ---- read-only ----

	public function test_page_renders_no_form_and_no_input(): void {
		$html = $this->render();

		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( '<input', $html );
		$this->assertStringNotContainsString( '<button', $html );
	}

	public function test_retained_rows_are_present(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'Plugin version', $html );
		$this->assertStringContainsString( UNIVERSAL_SUPPORT_CHAT_VERSION, $html );
		$this->assertStringContainsString( 'Schema available', $html );
		$this->assertStringContainsString( 'Vault self-check', $html );
		$this->assertStringContainsString( 'Recent audit rows', $html );
		$this->assertStringContainsString( 'Open Settings →', $html );
		$this->assertStringContainsString( 'page=' . SupportChatSettingsPage::SLUG, $html );
	}

	// ---- safe aggregates ----

	public function test_telegram_aggregates_render_defaults_when_nothing_configured(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'Telegram dispatch', $html );
		$this->assertStringContainsString( 'disabled', $html );
		$this->assertStringContainsString( 'Telegram adapter pairing', $html );
		$this->assertStringContainsString( 'not paired', $html );
		$this->assertStringContainsString( 'Telegram adapter usable', $html );
		$this->assertStringContainsString( 'Dispatch outbox (rows by state)', $html );
		$this->assertStringContainsString( 'none', $html );
	}

	public function test_dispatch_flag_and_pairing_reflect_real_state(): void {
		update_option( Settings::OPTION_NAME, array( 'telegram_dispatch_enabled' => true ) + ( new Settings() )->defaults() );

		$this->peers->create(
			TelegramDispatchService::PEER_ID,
			self::FAKE_PUB_KEY,
			self::SECRET_KEY_ID,
			array( 'ingest_operator_reply' ),
			null,
			null,
			self::SECRET_ROUTE
		);

		$html = $this->render();

		$this->assertMatchesRegularExpression( '/Telegram dispatch<\/th><td>enabled/', $html );
		$this->assertMatchesRegularExpression( '/Telegram adapter pairing<\/th><td>paired/', $html );
		$this->assertMatchesRegularExpression( '/Telegram adapter usable<\/th><td>yes/', $html );
	}

	public function test_outbox_state_counts_are_rendered_as_aggregates(): void {
		$this->outbox->enqueue( wp_generate_uuid4(), 1, wp_generate_uuid4(), 'visitor' );
		$this->outbox->enqueue( wp_generate_uuid4(), 1, wp_generate_uuid4(), 'operator' );

		$html = $this->render();

		$this->assertMatchesRegularExpression( '/Dispatch outbox \(rows by state\)<\/th><td>[^<]*pending: 2/', $html );
	}

	// ---- redaction boundary (ADR-0015 §3) ----

	public function test_render_never_leaks_keys_routes_ids_or_timestamps(): void {
		update_option( Settings::OPTION_NAME, array( 'telegram_dispatch_enabled' => true ) + ( new Settings() )->defaults() );

		$this->peers->create(
			TelegramDispatchService::PEER_ID,
			self::FAKE_PUB_KEY,
			self::SECRET_KEY_ID,
			array( 'ingest_operator_reply' ),
			'some_required_cap',
			'2099-01-01 00:00:00',
			self::SECRET_ROUTE
		);

		$conversation_uuid = wp_generate_uuid4();
		$this->outbox->enqueue( wp_generate_uuid4(), 42, $conversation_uuid, 'visitor' );

		$html = $this->render();

		foreach (
			array(
				self::FAKE_PUB_KEY,
				self::SECRET_KEY_ID,
				self::SECRET_ROUTE,
				'some_required_cap',
				'2099-01-01',
				$conversation_uuid,
			) as $forbidden
		) {
			$this->assertStringNotContainsString( $forbidden, $html, $forbidden );
		}

		// No raw error text / stack-trace markers either.
		$this->assertStringNotContainsString( 'Exception', $html );
		$this->assertStringNotContainsString( '#0 ', $html );
	}

	public function test_unavailable_schema_shows_only_the_enum_failure_label(): void {
		$broken = new SchemaHealth();
		$broken->mark_unavailable( \UniversalSupportChat\Persistence\MigrationFailureCode::STEP_FAILED );

		$page = new DiagnosticsPage(
			$broken,
			new AuditLogRepository( $broken ),
			new CredentialVault(),
			new Settings(),
			new PeerRepository( $broken ),
			new DispatchOutboxRepository( $broken )
		);

		$this->as_manager();
		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Schema failure code', $html );
		$this->assertStringContainsString( 'step_failed', $html );
	}
}
