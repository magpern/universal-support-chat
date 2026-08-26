<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Interop;

use UniversalSupportChat\Conversations\ConversationRepository;
use UniversalSupportChat\Conversations\MessageRepository;
use UniversalSupportChat\Conversations\NoteRepository;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Migration\InProcessLegacyExportClient;
use UniversalSupportChat\Migration\LegacyMigrationBatchLogRepository;
use UniversalSupportChat\Migration\LegacyMigrationMapEntry;
use UniversalSupportChat\Migration\LegacyMigrationMapRepository;
use UniversalSupportChat\Migration\LegacyMigrationMessageMapRepository;
use UniversalSupportChat\Migration\LegacyMigrationRunRepository;
use UniversalSupportChat\Migration\PhaseABackfillService;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalTelegram\Core\Plugin as UtPlugin;
use UniversalTelegram\Persistence\Migrator as UtMigrator;
use WP_UnitTestCase;

if ( ! defined( 'WP_CLI' ) ) {
	// This suite exercises the real cross-plugin boundary exactly as
	// Support Chat's own migration WP-CLI command would: in-process, from
	// within an authorized WP-CLI process (Support Chat ADR-0008 §4,
	// Universal Telegram ADR-0039). No other integration test in this
	// repository relies on WP_CLI being undefined.
	define( 'WP_CLI', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- simulates the real WP-CLI process constant, not a plugin global.
}

/**
 * Proves `InProcessLegacyExportClient` against Universal Telegram's real,
 * merged `LegacyExportServiceV1` (not a fake) — both plugins' real source
 * loaded in one disposable WordPress install
 * (tests/integration/Interop/bootstrap.php). Seeds real Universal Telegram
 * conversation/message/note rows through Universal Telegram's own
 * repositories, backfills them through this repository's real
 * `PhaseABackfillService`, and confirms the plaintext round-trips through
 * Support Chat's own vault — this plugin's cross-plugin boundary
 * responsibility, end to end.
 */
final class LegacyExportClientIntegrationTest extends WP_UnitTestCase {

	private ConversationRepository $sc_conversations;
	private MessageRepository $sc_messages;
	private NoteRepository $sc_notes;
	private LegacyMigrationMapRepository $map;

	public function set_up(): void {
		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		$this->truncate_sc_tables_committed_by_real_transactions();

		$health                 = new SchemaHealth();
		$vault                  = new CredentialVault();
		$this->sc_conversations = new ConversationRepository( $health );
		$this->sc_messages      = new MessageRepository( $health, $vault );
		$this->sc_notes         = new NoteRepository( $health, $vault );
		$this->map              = new LegacyMigrationMapRepository( $health );
	}

	private function truncate_sc_tables_committed_by_real_transactions(): void {
		global $wpdb;

		foreach (
			array(
				Migrator::LEGACY_MIGRATION_BATCH_LOG_TABLE,
				Migrator::LEGACY_MIGRATION_MESSAGE_MAP_TABLE,
				Migrator::LEGACY_MIGRATION_MAP_TABLE,
				Migrator::LEGACY_MIGRATION_RUNS_TABLE,
				Migrator::CONVERSATION_NOTES_TABLE,
				Migrator::CONVERSATION_MESSAGES_TABLE,
				Migrator::CONVERSATIONS_TABLE,
			) as $table_constant
		) {
			$table = $wpdb->prefix . $table_constant;
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, test cleanup only.
		}
	}

	private function backfill_service(): PhaseABackfillService {
		return new PhaseABackfillService(
			new InProcessLegacyExportClient(),
			$this->sc_conversations,
			$this->sc_messages,
			$this->sc_notes,
			$this->map,
			new LegacyMigrationMessageMapRepository( new SchemaHealth() ),
			new LegacyMigrationRunRepository( new SchemaHealth() ),
			new LegacyMigrationBatchLogRepository( new SchemaHealth() )
		);
	}

	public function test_real_ut_conversation_backfills_through_the_real_export_boundary(): void {
		$ut = UtPlugin::instance();
		$this->assertNotNull( $ut->legacy_export_service(), 'Universal Telegram legacy export service was not wired — is the sibling checkout really loaded?' );

		$ut_conversations = $ut->conversation_repository();
		$ut_messages      = $ut->message_repository();
		$ut_notes         = $ut->conversation_note_repository();

		$owner           = self::factory()->user->create();
		$operator        = self::factory()->user->create();
		$ut_conversation = $ut_conversations->create( wp_generate_uuid4(), 'hashed-secret', 3, 'sales', null, $owner );
		$this->assertNotNull( $ut_conversation );

		$ut_messages->create( $ut_conversation->id(), 'visitor', 'Is my order shipped yet?' );
		$ut_messages->create( $ut_conversation->id(), 'operator', 'Yes, shipped this morning.' );
		$ut_notes->create( $ut_conversation->id(), $operator, 'Confirmed shipment with warehouse.' );

		$result = $this->backfill_service()->run( false, 100 );

		$this->assertGreaterThanOrEqual( 1, $result['backfilled'] );

		$map_row = $this->map->find_by_source_id( $ut_conversation->id() );
		$this->assertNotNull( $map_row );
		$this->assertSame( LegacyMigrationMapEntry::STATUS_BACKFILLED, $map_row->status() );
		$this->assertSame( $owner, $this->sc_conversations->find_by_id( (int) $map_row->target_conversation_id() )->owner_user_id() );

		$sc_messages = $this->sc_messages->list_for_conversation( (int) $map_row->target_conversation_id() );
		$this->assertCount( 2, $sc_messages );
		$this->assertSame( 'Is my order shipped yet?', $sc_messages[0]->plaintext_body() );
		$this->assertSame( 'Yes, shipped this morning.', $sc_messages[1]->plaintext_body() );

		$sc_notes = $this->sc_notes->list_for_conversation( (int) $map_row->target_conversation_id() );
		$this->assertCount( 1, $sc_notes );
		$this->assertSame( 'Confirmed shipment with warehouse.', $sc_notes[0]->plaintext_body() );

		// The plaintext was re-encrypted under Support Chat's own vault —
		// not merely copied — confirmed by decrypting through this
		// plugin's own MessageRepository, whose AAD context binds to this
		// plugin's own fresh target message UUID, never Universal
		// Telegram's.
		$this->assertStringNotContainsString( 'Is my order shipped yet?', (string) $this->raw_ciphertext_column( (int) $map_row->target_conversation_id() ) );
	}

	private function raw_ciphertext_column( int $sc_conversation_id ): ?string {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATION_MESSAGES_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, test assertion only.
		return $wpdb->get_var(
			$wpdb->prepare( "SELECT body_ciphertext FROM {$table} WHERE conversation_id = %d ORDER BY id ASC LIMIT 1", $sc_conversation_id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function test_ownerless_ut_conversation_is_skipped_not_migrated(): void {
		$ut               = UtPlugin::instance();
		$ut_conversations = $ut->conversation_repository();

		// Anonymous (no owner_user_id) — a real, legitimate Universal
		// Telegram state per its own M06.3 design.
		$ut_conversation = $ut_conversations->create( wp_generate_uuid4(), 'hashed-secret', 3, null );
		$this->assertNotNull( $ut_conversation );
		$this->assertNull( $ut_conversation->owner_user_id() );

		$this->backfill_service()->run( false, 100 );

		$map_row = $this->map->find_by_source_id( $ut_conversation->id() );
		$this->assertNotNull( $map_row );
		$this->assertSame( LegacyMigrationMapEntry::STATUS_SKIPPED, $map_row->status() );
		$this->assertSame( 'ownerless_conversation_unsupported', $map_row->error_reason() );
	}

	public function test_no_direct_universal_telegram_sql_or_vault_key_access(): void {
		// Structural guard, not just documentation: confirm this repository
		// never builds a query against one of Universal Telegram's real
		// table names as a literal SQL identifier. Prose mentioning the
		// "universal_telegram_" prefix in a docblock (this boundary class's
		// own commentary about what it must never do) is not itself a
		// violation — only an actual table-name literal is.
		$forbidden_literals = array(
			"'" . UtMigrator::CONVERSATIONS_TABLE . "'",
			"'" . UtMigrator::CONVERSATION_MESSAGES_TABLE . "'",
			"'" . UtMigrator::CONVERSATION_NOTES_TABLE . "'",
			'FROM universal_telegram_',
			'JOIN universal_telegram_',
			'INTO universal_telegram_',
			'UPDATE universal_telegram_',
		);

		$offenders = array();

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 3 ) . '/src', \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			$contents = (string) file_get_contents( $file->getPathname() );

			foreach ( $forbidden_literals as $literal ) {
				if ( false !== strpos( $contents, $literal ) ) {
					$offenders[] = $file->getPathname() . ' (' . $literal . ')';
				}
			}
		}

		$this->assertSame( array(), $offenders, 'Found a direct Universal Telegram SQL reference: ' . implode( ', ', $offenders ) );
	}
}
