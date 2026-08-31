<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\AI;

use UniversalSupportChat\AI\Admin\HubAiPanel;
use UniversalSupportChat\AI\Admin\TakeoverAction;
use UniversalSupportChat\AI\Knowledge\KnowledgeSourceRepository;
use UniversalSupportChat\AI\Turn\AiTurnRepository;
use UniversalSupportChat\Audit\AuditLogRepository;
use UniversalSupportChat\Audit\AuditLogger;
use UniversalSupportChat\Conversations\ConversationRepository;
use UniversalSupportChat\Conversations\ConversationStatus;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalSupportChat\Privacy\Redactor;
use UniversalSupportChat\Tests\Integration\AI\Support\TruncatesAiTables;
use WP_UnitTestCase;

/**
 * SC-M07 WP7 — operator takeover and the read-only Hub AI panel.
 */
final class TakeoverAndPanelTest extends WP_UnitTestCase {

	use TruncatesAiTables;

	private SchemaHealth $health;
	private ConversationRepository $conversations;
	private AiTurnRepository $turns;
	private KnowledgeSourceRepository $knowledge;

	public function set_up(): void {
		parent::set_up();
		$this->truncate_ai_tables();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		( new CapabilityRegistrar() )->grant_to_administrator();
		$this->health        = new SchemaHealth();
		$this->conversations = new ConversationRepository( $this->health );
		$this->turns         = new AiTurnRepository();
		$this->knowledge     = new KnowledgeSourceRepository( new CredentialVault() );
	}

	public function tear_down(): void {
		unset( $_POST, $_REQUEST );
		parent::tear_down();
	}

	private function run_takeover( int $conversation_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test harness nonce.
		$_REQUEST['_wpnonce']     = wp_create_nonce( TakeoverAction::NONCE );
		$_POST['_wpnonce']        = $_REQUEST['_wpnonce']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_POST['conversation_id'] = $conversation_id;

		$catch = static function (): void {
			throw new \RuntimeException( 'redirected' );
		};
		add_filter( 'wp_redirect', $catch );
		try {
			( new TakeoverAction(
				$this->conversations,
				$this->turns,
				new AuditLogger( new SchemaHealth(), new Redactor() )
			) )->handle();
		} catch ( \RuntimeException $e ) {
			unset( $e );
		} finally {
			remove_filter( 'wp_redirect', $catch );
		}
	}

	public function test_takeover_claims_the_conversation_skips_turns_and_audits(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$conv = $this->conversations->create( self::factory()->user->create() );
		$this->conversations->transition( $conv, ConversationStatus::OPEN );
		$this->turns->insert_queued( wp_generate_uuid4(), $conv->id(), 1, gmdate( 'Y-m-d H:i:s' ) );

		$this->run_takeover( $conv->id() );

		$fresh = $this->conversations->find_by_id( $conv->id() );
		$this->assertNotNull( $fresh->assigned_operator_id() );
		$this->assertSame( 'skipped', $this->turns->latest_for_conversation( $conv->id() )['status'] );

		$actions = array_map(
			static fn ( $r ) => $r['action'],
			( new AuditLogRepository( $this->health ) )->recent( 10 )
		);
		$this->assertContains( 'ai.takeover', $actions );
	}

	public function test_takeover_requires_manage(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_POST['conversation_id'] = 1;
		$_REQUEST['_wpnonce']     = wp_create_nonce( TakeoverAction::NONCE );

		$this->expectException( \WPDieException::class );
		( new TakeoverAction( $this->conversations, $this->turns, null ) )->handle();
	}

	public function test_ai_panel_shows_only_safe_aggregates(): void {
		$conv = $this->conversations->create( self::factory()->user->create() );
		$this->conversations->transition( $conv, ConversationStatus::OPEN );

		$uuid = wp_generate_uuid4();
		$id   = $this->turns->insert_queued( $uuid, $conv->id(), 1, gmdate( 'Y-m-d H:i:s' ) );
		$src  = $this->knowledge->create_approved( KnowledgeSourceRepository::TYPE_SNIPPET, null, 'Shipping policy', 'We ship worldwide.', 1 );
		global $wpdb;
		$sources_table = $wpdb->prefix . Migrator::AI_KNOWLEDGE_SOURCES_TABLE;
		$src_id        = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$sources_table} WHERE source_uuid = %s", $src ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$this->turns->complete_answered( $id, 99, 'stop', 120, 8, 300, (string) $src_id, substr( KnowledgeSourceRepository::checksum( 'We ship worldwide.' ), 0, 12 ) );

		ob_start();
		( new HubAiPanel( $this->turns, $this->knowledge ) )->render( $this->conversations->find_by_id( $conv->id() ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'AI assistant', $html );
		$this->assertStringContainsString( '120 / 8', $html );
		$this->assertStringContainsString( 'Shipping policy', $html );
		$this->assertStringContainsString( 'same text', $html );
		$this->assertStringContainsString( 'Tool calls', $html );
		$this->assertStringContainsString( '<td>0</td>', $html );

		// No prompt / answer body / key / raw error / uuid / timestamp.
		$this->assertStringNotContainsString( 'We ship worldwide', $html );
		$this->assertStringNotContainsString( $uuid, $html );
		$this->assertStringNotContainsString( 'sk-', $html );
		$this->assertDoesNotMatchRegularExpression( '/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $html );
	}

	public function test_ai_panel_is_silent_when_there_is_no_turn(): void {
		$conv = $this->conversations->create( self::factory()->user->create() );

		ob_start();
		( new HubAiPanel( $this->turns, $this->knowledge ) )->render( $this->conversations->find_by_id( $conv->id() ) );

		$this->assertSame( '', (string) ob_get_clean() );
	}
}
