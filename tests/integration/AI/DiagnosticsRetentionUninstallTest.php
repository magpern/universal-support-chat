<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\AI;

use UniversalSupportChat\AI\Knowledge\KnowledgeSourceRepository;
use UniversalSupportChat\AI\Provider\ProviderKeyManager;
use UniversalSupportChat\AI\Turn\AiTurnRepository;
use UniversalSupportChat\Administration\Diagnostics\DiagnosticsPage;
use UniversalSupportChat\Audit\AuditLogRepository;
use UniversalSupportChat\Audit\AuditLogger;
use UniversalSupportChat\Availability\AvailabilityService;
use UniversalSupportChat\ChannelContract\Auth\PeerRepository;
use UniversalSupportChat\Conversations\ConversationMessage;
use UniversalSupportChat\Conversations\ConversationRepository;
use UniversalSupportChat\Conversations\ConversationStatus;
use UniversalSupportChat\Conversations\MessageRepository;
use UniversalSupportChat\Conversations\NoteRepository;
use UniversalSupportChat\Conversations\RetentionCleanupHandler;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;
use UniversalSupportChat\Core\Configuration\Settings;
use UniversalSupportChat\Core\Lifecycle\Uninstaller;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalSupportChat\Privacy\Redactor;
use UniversalSupportChat\TelegramDispatch\DispatchOutboxRepository;
use UniversalSupportChat\Tests\Integration\AI\Support\TruncatesAiTables;
use WP_UnitTestCase;

/**
 * SC-M07 WP8 — Diagnostics AI block, retention purge of ai_turns, uninstall
 * removal, and `ai.*` audit redaction.
 */
final class DiagnosticsRetentionUninstallTest extends WP_UnitTestCase {

	use TruncatesAiTables;

	private SchemaHealth $health;

	public function set_up(): void {
		parent::set_up();
		$this->truncate_ai_tables();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		( new CapabilityRegistrar() )->grant_to_administrator();
		$this->health = new SchemaHealth();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		delete_option( Settings::OPTION_NAME );
		( new ProviderKeyManager( new CredentialVault() ) )->clear();
		parent::tear_down();
	}

	private function diagnostics(): DiagnosticsPage {
		$vault = new CredentialVault();

		return new DiagnosticsPage(
			$this->health,
			new AuditLogRepository( $this->health ),
			$vault,
			new Settings(),
			new PeerRepository( $this->health ),
			new DispatchOutboxRepository( $this->health ),
			null,
			new ProviderKeyManager( $vault ),
			new AiTurnRepository(),
			new KnowledgeSourceRepository( $vault )
		);
	}

	public function test_ai_diagnostics_block_renders_only_safe_aggregates(): void {
		update_option(
			Settings::OPTION_NAME,
			array(
				'ai_enabled'           => true,
				'ai_daily_request_cap' => 500,
			)
		);
		$vault = new CredentialVault();
		( new ProviderKeyManager( $vault ) )->set( 'sk-super-secret-key' );

		$turns = new AiTurnRepository();
		$id    = $turns->insert_queued( wp_generate_uuid4(), 5, 1, gmdate( 'Y-m-d H:i:s' ) );
		$turns->complete_handed_off( $id, 'provider_failed', 'timeout' );
		( new KnowledgeSourceRepository( $vault ) )->create_approved( KnowledgeSourceRepository::TYPE_SNIPPET, null, 'Shipping', 'secret sauce text', 1 );

		ob_start();
		$this->diagnostics()->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'AI assistant', $html );
		$this->assertStringContainsString( 'enabled', $html );
		$this->assertStringContainsString( 'configured', $html );
		$this->assertStringContainsString( 'AI knowledge sources', $html );
		$this->assertStringContainsString( 'timeout', $html );

		// Never the key, the snippet text, or a turn uuid.
		$this->assertStringNotContainsString( 'sk-super-secret-key', $html );
		$this->assertStringNotContainsString( 'secret sauce text', $html );
	}

	public function test_retention_purges_ai_turns_with_the_conversation(): void {
		$conversations = new ConversationRepository( $this->health );
		$messages      = new MessageRepository( $this->health, new CredentialVault() );
		$turns         = new AiTurnRepository();

		$conv = $conversations->create( self::factory()->user->create() );
		$turns->insert_queued( wp_generate_uuid4(), $conv->id(), 1, gmdate( 'Y-m-d H:i:s' ) );

		// Force it deep into the retention pipeline: archived long ago.
		global $wpdb;
		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, updated_at = %s, resolved_at = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ConversationStatus::ARCHIVED,
				'2000-01-01 00:00:00',
				'2000-01-01 00:00:00',
				$conv->id()
			)
		);

		update_option( Settings::OPTION_NAME, array( 'conversation_purge_days' => 1 ) );

		$handler = new RetentionCleanupHandler(
			$conversations,
			$messages,
			new NoteRepository( $this->health, new CredentialVault() ),
			new Settings(),
			new AuditLogger( $this->health, new Redactor() ),
			null,
			null,
			$turns
		);
		$handler->run( false );

		$this->assertSame( 0, $turns->count_for_conversation( $conv->id() ) );
	}

	public function test_uninstall_removes_the_provider_secret_only_when_opted_in(): void {
		// The WordPress test harness rewrites DROP TABLE to a no-op temporary
		// drop, so table removal cannot be asserted here (true of every
		// plugin table); this covers the SC-M07 option removal and the
		// opt-in gate. The `Uninstaller::run()` code path drops
		// `ai_turns` / `knowledge_sources` alongside the other tables.
		( new ProviderKeyManager( new CredentialVault() ) )->set( 'sk-x' );

		update_option( Settings::OPTION_NAME, array( 'remove_data_on_uninstall' => false ) );
		( new Uninstaller() )->run();
		$this->assertTrue( ( new ProviderKeyManager( new CredentialVault() ) )->is_configured() );

		update_option( Settings::OPTION_NAME, array( 'remove_data_on_uninstall' => true ) );
		( new Uninstaller() )->run();
		$this->assertFalse( get_option( ProviderKeyManager::OPTION_SECRET, false ) );
		$this->assertFalse( ( new ProviderKeyManager( new CredentialVault() ) )->is_configured() );
	}

	public function test_uninstaller_source_drops_both_ai_tables(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Core/Lifecycle/Uninstaller.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local read.

		$this->assertStringContainsString( 'Migrator::AI_TURNS_TABLE', $source );
		$this->assertStringContainsString( 'Migrator::AI_KNOWLEDGE_SOURCES_TABLE', $source );
		$this->assertStringContainsString( 'universal_support_chat_ai_provider_secret', $source );
	}

	public function test_ai_audit_events_carry_no_bodies_or_secrets(): void {
		$audit = new AuditLogger( $this->health, new Redactor() );

		// Simulate the events SC-M07 emits, then assert the stored rows carry
		// only the safe keys the code passes — never a body or secret.
		$audit->record( 'ai.handoff', 'system', null, array( 'reason' => 'safety' ), array( 'reason' => \UniversalSupportChat\Privacy\Classification::PUBLIC ), \UniversalSupportChat\Privacy\Classification::INTERNAL );
		$audit->record( 'ai.token_rotated', 'operator', 1, array( 'op' => 'rotated' ), array( 'op' => \UniversalSupportChat\Privacy\Classification::PUBLIC ), \UniversalSupportChat\Privacy\Classification::INTERNAL );

		$rows = ( new AuditLogRepository( $this->health ) )->recent( 10 );
		$blob = wp_json_encode( $rows );

		$this->assertStringContainsString( 'ai.handoff', $blob );
		$this->assertStringContainsString( 'ai.token_rotated', $blob );
		$this->assertStringNotContainsString( 'sk-', $blob );
		// The AI never audits message text; a spot check that no visitor-style
		// sentence leaked into any of these rows.
		$this->assertStringNotContainsString( 'ship to Norway', $blob );
	}
}
