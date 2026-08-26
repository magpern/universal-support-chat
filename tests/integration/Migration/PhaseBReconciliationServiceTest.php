<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Migration;

use UniversalSupportChat\Conversations\ConversationRepository;
use UniversalSupportChat\Conversations\ConversationStatus;
use UniversalSupportChat\Conversations\MessageRepository;
use UniversalSupportChat\Conversations\NoteRepository;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Migration\DefaultDenyQuiescenceStateProvider;
use UniversalSupportChat\Migration\LegacyMigrationBatchLogRepository;
use UniversalSupportChat\Migration\LegacyMigrationMapEntry;
use UniversalSupportChat\Migration\LegacyMigrationMapRepository;
use UniversalSupportChat\Migration\LegacyMigrationMessageMapRepository;
use UniversalSupportChat\Migration\LegacyMigrationRunRepository;
use UniversalSupportChat\Migration\LegacyMigrationValidator;
use UniversalSupportChat\Migration\PhaseABackfillService;
use UniversalSupportChat\Migration\PhaseBReconciliationService;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalSupportChat\Tests\Integration\Migration\Support\FakeLegacyExportClient;
use UniversalSupportChat\Tests\Integration\Migration\Support\FakeQuiescenceStateProvider;
use WP_UnitTestCase;

final class PhaseBReconciliationServiceTest extends WP_UnitTestCase {

	private ConversationRepository $conversations;
	private MessageRepository $messages;
	private NoteRepository $notes;
	private LegacyMigrationMapRepository $map;
	private LegacyMigrationMessageMapRepository $message_map;
	private FakeLegacyExportClient $export_client;

	public function set_up(): void {
		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		$this->truncate_tables_committed_by_real_transactions();

		$health              = new SchemaHealth();
		$vault               = new CredentialVault();
		$this->conversations = new ConversationRepository( $health );
		$this->messages      = new MessageRepository( $health, $vault );
		$this->notes         = new NoteRepository( $health, $vault );
		$this->map           = new LegacyMigrationMapRepository( $health );
		$this->message_map   = new LegacyMigrationMessageMapRepository( $health );
		$this->export_client = new FakeLegacyExportClient();
	}

	/**
	 * See PhaseABackfillServiceTest's identical helper for why this is
	 * necessary: both phases commit real transactions WP_UnitTestCase's
	 * own rollback fixture cannot undo.
	 */
	private function truncate_tables_committed_by_real_transactions(): void {
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
			$this->export_client,
			$this->conversations,
			$this->messages,
			$this->notes,
			$this->map,
			$this->message_map,
			new LegacyMigrationRunRepository( new SchemaHealth() ),
			new LegacyMigrationBatchLogRepository( new SchemaHealth() )
		);
	}

	private function reconcile_service( \UniversalSupportChat\Migration\QuiescenceStateProvider $quiescence ): PhaseBReconciliationService {
		$validator = new LegacyMigrationValidator( $this->messages, $this->notes, $this->message_map );

		return new PhaseBReconciliationService(
			$this->export_client,
			$quiescence,
			$this->messages,
			$this->notes,
			$this->map,
			$this->message_map,
			$validator
		);
	}

	/**
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function entry( int $id, array $overrides = array() ): array {
		$owner = self::factory()->user->create();

		return array_merge(
			array(
				'id'                            => $id,
				'conversation_uuid'             => 'legacy-uuid-' . $id,
				'bot_id'                        => 7,
				'destination_id'                => 42,
				'status'                        => ConversationStatus::OPEN,
				'assigned_operator_id'          => null,
				'owner_user_id'                 => $owner,
				'topic_creation_state'          => 'created',
				'telegram_topic_id'             => 123,
				'topic_lifecycle_state'         => 'active',
				'start_idempotency_key'         => 'legacy-start-key-' . $id,
				'created_at'                    => '2026-01-01 00:00:00',
				'updated_at'                    => '2026-01-02 00:00:00',
				'resolved_at'                   => null,
				'expires_at'                    => null,
				'assignee_last_seen_message_id' => null,
				'messages'                      => array(),
				'notes'                         => array(),
			),
			$overrides
		);
	}

	public function test_phase_b_refuses_to_run_against_the_default_deny_provider(): void {
		$result = $this->reconcile_service( new DefaultDenyQuiescenceStateProvider() )->run( false );

		$this->assertSame( 'refused', $result['status'] );
		$this->assertSame( PhaseBReconciliationService::REFUSED_NOT_QUIESCENT, $result['reason'] );
	}

	public function test_phase_b_proceeds_only_against_the_fake_provider_returning_true(): void {
		$this->export_client->seed( $this->entry( 1 ) );
		$this->backfill_service()->run( false, 100 );

		$result = $this->reconcile_service( ( new FakeQuiescenceStateProvider() )->make_quiescent() )->run( false );

		$this->assertSame( 'ran', $result['status'] );
		$this->assertSame( 1, $result['checked'] );
		$this->assertSame( 1, $result['validated'] );

		$map_row = $this->map->find_by_source_id( 1 );
		$this->assertSame( LegacyMigrationMapEntry::STATUS_MIGRATED, $map_row->status() );
		$this->assertTrue( $map_row->validation_passed() );
	}

	public function test_phase_b_blocks_when_source_rows_exist_beyond_the_recorded_high_water_mark(): void {
		$this->export_client->seed( $this->entry( 1 ) );
		$this->backfill_service()->run( false, 100 );

		// Universal Telegram gained a new conversation after Phase A's last
		// pass — Phase B must refuse rather than reconcile an incomplete set.
		$this->export_client->seed( $this->entry( 2 ) );

		$result = $this->reconcile_service( ( new FakeQuiescenceStateProvider() )->make_quiescent() )->run( false );

		$this->assertSame( 'refused', $result['status'] );
		$this->assertSame( PhaseBReconciliationService::REFUSED_NEW_SOURCE_ROWS, $result['reason'] );

		// Nothing was promoted while refused.
		$this->assertSame( LegacyMigrationMapEntry::STATUS_BACKFILLED, $this->map->find_by_source_id( 1 )->status() );
	}

	public function test_phase_b_imports_a_message_added_to_the_source_since_phase_a(): void {
		$entry = $this->entry(
			1,
			array(
				'messages' => array(
					array(
						'id'           => 10,
						'message_uuid' => 'm-10',
						'direction'    => 'visitor',
						'body'         => 'First',
						'created_at'   => '2026-01-01 00:00:01',
					),
				),
			)
		);
		$this->export_client->seed( $entry );
		$this->backfill_service()->run( false, 100 );

		// Drift: a second message arrives on the source conversation before
		// quiescence — Phase B's preflight only checks for *new
		// conversations*, not new messages on already-backfilled ones, so
		// this is exactly what reconciliation exists to catch.
		$drifted_entry               = $entry;
		$drifted_entry['messages'][] = array(
			'id'           => 11,
			'message_uuid' => 'm-11',
			'direction'    => 'operator',
			'body'         => 'Second, added later',
			'created_at'   => '2026-01-01 00:00:02',
		);

		$this->export_client = new FakeLegacyExportClient();
		$this->export_client->seed( $drifted_entry );

		$result = $this->reconcile_service( ( new FakeQuiescenceStateProvider() )->make_quiescent() )->run( false );

		$this->assertSame( 'ran', $result['status'] );
		$this->assertSame( 1, $result['validated'] );

		$map_row  = $this->map->find_by_source_id( 1 );
		$target   = $this->conversations->find_by_id( (int) $map_row->target_conversation_id() );
		$messages = $this->messages->list_for_conversation( $target->id() );

		$this->assertCount( 2, $messages );
		$this->assertSame( 'Second, added later', $messages[1]->plaintext_body() );
		$this->assertSame( 2, $map_row->message_count_target() );
	}

	public function test_dry_run_reconciliation_does_not_promote_or_import_anything(): void {
		$entry = $this->entry(
			1,
			array(
				'messages' => array(
					array(
						'id'           => 10,
						'message_uuid' => 'm-10',
						'direction'    => 'visitor',
						'body'         => 'First',
						'created_at'   => '2026-01-01 00:00:01',
					),
				),
			)
		);
		$this->export_client->seed( $entry );
		$this->backfill_service()->run( false, 100 );

		$result = $this->reconcile_service( ( new FakeQuiescenceStateProvider() )->make_quiescent() )->run( true );

		$this->assertSame( 'ran', $result['status'] );

		$map_row = $this->map->find_by_source_id( 1 );
		$this->assertSame( LegacyMigrationMapEntry::STATUS_BACKFILLED, $map_row->status() );
		$this->assertNull( $map_row->validation_passed() );
	}
}
