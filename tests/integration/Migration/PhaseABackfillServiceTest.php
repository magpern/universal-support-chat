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
use UniversalSupportChat\Migration\LegacyMigrationBatchLogRepository;
use UniversalSupportChat\Migration\LegacyMigrationMapEntry;
use UniversalSupportChat\Migration\LegacyMigrationMapRepository;
use UniversalSupportChat\Migration\LegacyMigrationMessageMapRepository;
use UniversalSupportChat\Migration\LegacyMigrationRunRepository;
use UniversalSupportChat\Migration\PhaseABackfillService;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalSupportChat\Tests\Integration\Migration\Support\FakeLegacyExportClient;
use WP_UnitTestCase;

final class PhaseABackfillServiceTest extends WP_UnitTestCase {

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
	 * `PhaseABackfillService::run()` commits a real transaction per
	 * conversation (required for genuine crash-safety) — unlike every
	 * other write path in this codebase, that commit is never undone by
	 * `WP_UnitTestCase`'s own rollback-per-test fixture, because the data
	 * was already committed before the fixture's rollback ever runs.
	 * Explicit cleanup at the start of every test is this file's own
	 * responsibility, not a workaround for a bug in the service itself.
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

	private function service(): PhaseABackfillService {
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

	/**
	 * Builds one ADR-0008 §5-shaped conversation entry with sane defaults.
	 *
	 * @param int                   $id       Legacy conversation id.
	 * @param array<string, mixed>  $overrides Field overrides.
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

	public function test_backfill_writes_conversation_messages_and_notes(): void {
		$operator = self::factory()->user->create();

		$entry = $this->entry(
			1,
			array(
				'messages' => array(
					array(
						'id'           => 10,
						'message_uuid' => 'm-10',
						'direction'    => 'visitor',
						'body'         => 'Hello there',
						'created_at'   => '2026-01-01 00:01:00',
					),
					array(
						'id'           => 11,
						'message_uuid' => 'm-11',
						'direction'    => 'operator',
						'body'         => 'Hi, how can I help?',
						'created_at'   => '2026-01-01 00:02:00',
					),
				),
				'notes'    => array(
					array(
						'id'               => 20,
						'operator_user_id' => $operator,
						'body'             => 'Internal note.',
						'created_at'       => '2026-01-01 00:03:00',
					),
				),
			)
		);
		$this->export_client->seed( $entry );

		$result = $this->service()->run( false, 100 );

		$this->assertSame( 1, $result['backfilled'] );
		$this->assertSame( 0, $result['failed'] );
		$this->assertSame( 0, $result['skipped'] );

		$map_row = $this->map->find_by_source_id( 1 );
		$this->assertNotNull( $map_row );
		$this->assertSame( LegacyMigrationMapEntry::STATUS_BACKFILLED, $map_row->status() );
		$this->assertSame( 2, $map_row->message_count_target() );
		$this->assertSame( 1, $map_row->note_count_target() );

		$target = $this->conversations->find_by_id( (int) $map_row->target_conversation_id() );
		$this->assertNotNull( $target );
		$this->assertSame( $entry['owner_user_id'], $target->owner_user_id() );
		$this->assertSame( ConversationStatus::OPEN, $target->status() );
		$this->assertNotSame( $entry['conversation_uuid'], $target->uuid() );

		$messages = $this->messages->list_for_conversation( $target->id() );
		$this->assertCount( 2, $messages );
		$this->assertSame( 'Hello there', $messages[0]->plaintext_body() );
		$this->assertSame( 'Hi, how can I help?', $messages[1]->plaintext_body() );
		$this->assertSame( 'stored', $messages[0]->delivery_state() );
		$this->assertSame( 'stored', $messages[1]->delivery_state() );

		$notes = $this->notes->list_for_conversation( $target->id() );
		$this->assertCount( 1, $notes );
		$this->assertSame( 'Internal note.', $notes[0]->plaintext_body() );
	}

	public function test_assignee_last_seen_message_id_is_remapped_to_the_target_message(): void {
		$entry = $this->entry(
			2,
			array(
				'messages'                      => array(
					array(
						'id'           => 30,
						'message_uuid' => 'm-30',
						'direction'    => 'visitor',
						'body'         => 'First',
						'created_at'   => '2026-01-01 00:00:01',
					),
					array(
						'id'           => 31,
						'message_uuid' => 'm-31',
						'direction'    => 'operator',
						'body'         => 'Second',
						'created_at'   => '2026-01-01 00:00:02',
					),
				),
				'assignee_last_seen_message_id' => 31,
			)
		);
		$this->export_client->seed( $entry );

		$this->service()->run( false, 100 );

		$map_row  = $this->map->find_by_source_id( 2 );
		$target   = $this->conversations->find_by_id( (int) $map_row->target_conversation_id() );
		$messages = $this->messages->list_for_conversation( $target->id() );

		$expected_target_message = null;
		foreach ( $messages as $message ) {
			if ( 'Second' === $message->plaintext_body() ) {
				$expected_target_message = $message;
			}
		}

		$this->assertNotNull( $expected_target_message );
		$this->assertSame( $expected_target_message->id(), $target->assignee_last_seen_message_id() );
	}

	public function test_assignee_last_seen_message_id_resolves_to_null_when_the_referenced_message_was_not_migrated(): void {
		$entry = $this->entry(
			3,
			array(
				'messages'                      => array(
					array(
						'id'           => 40,
						'message_uuid' => 'm-40',
						'direction'    => 'visitor',
						'body'         => 'Only message',
						'created_at'   => '2026-01-01 00:00:01',
					),
				),
				// References a message id that does not appear in the
				// export's own message list (e.g. purged legacy-side).
				'assignee_last_seen_message_id' => 999,
			)
		);
		$this->export_client->seed( $entry );

		$this->service()->run( false, 100 );

		$map_row = $this->map->find_by_source_id( 3 );
		$target  = $this->conversations->find_by_id( (int) $map_row->target_conversation_id() );

		$this->assertNull( $target->assignee_last_seen_message_id() );
	}

	public function test_ut_typed_export_error_produces_a_failed_map_row_and_no_target_conversation(): void {
		$this->export_client->seed(
			array(
				'id'    => 5,
				'error' => 'decrypt_failed',
			)
		);

		$result = $this->service()->run( false, 100 );

		$this->assertSame( 0, $result['backfilled'] );
		$this->assertSame( 1, $result['failed'] );

		$map_row = $this->map->find_by_source_id( 5 );
		$this->assertNotNull( $map_row );
		$this->assertSame( LegacyMigrationMapEntry::STATUS_FAILED, $map_row->status() );
		$this->assertSame( 'export_decrypt_failed', $map_row->error_reason() );
		$this->assertNull( $map_row->target_conversation_id() );
	}

	public function test_ownerless_conversation_is_skipped_with_a_durable_audit_reason_and_no_target_row(): void {
		$this->export_client->seed( $this->entry( 6, array( 'owner_user_id' => null ) ) );

		$result = $this->service()->run( false, 100 );

		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 0, $result['backfilled'] );

		$map_row = $this->map->find_by_source_id( 6 );
		$this->assertNotNull( $map_row );
		$this->assertSame( LegacyMigrationMapEntry::STATUS_SKIPPED, $map_row->status() );
		$this->assertSame( 'ownerless_conversation_unsupported', $map_row->error_reason() );
		$this->assertNull( $map_row->target_conversation_id() );
	}

	public function test_note_with_null_operator_user_id_fails_the_whole_conversation_atomically(): void {
		$entry = $this->entry(
			7,
			array(
				'messages' => array(
					array(
						'id'           => 50,
						'message_uuid' => 'm-50',
						'direction'    => 'visitor',
						'body'         => 'Hi',
						'created_at'   => '2026-01-01 00:00:01',
					),
				),
				'notes'    => array(
					// Anonymized former operator — a real Universal Telegram
					// state this schema's NOT NULL column cannot represent.
					array(
						'id'               => 60,
						'operator_user_id' => null,
						'body'             => 'Note from a deleted operator.',
						'created_at'       => '2026-01-01 00:00:02',
					),
				),
			)
		);
		$this->export_client->seed( $entry );

		$result = $this->service()->run( false, 100 );

		$this->assertSame( 1, $result['failed'] );

		$map_row = $this->map->find_by_source_id( 7 );
		$this->assertNotNull( $map_row );
		$this->assertSame( LegacyMigrationMapEntry::STATUS_FAILED, $map_row->status() );
		$this->assertSame( 'note_operator_user_id_null_unsupported', $map_row->error_reason() );
		// No partial conversation: the target conversation row itself was
		// never created, even though a message would otherwise have
		// succeeded — the failing note is detected before any write.
		$this->assertNull( $map_row->target_conversation_id() );
	}

	public function test_a_retention_nulled_message_body_is_imported_as_a_null_body_not_a_failure(): void {
		$entry = $this->entry(
			8,
			array(
				'messages' => array(
					array(
						'id'           => 70,
						'message_uuid' => 'm-70',
						'direction'    => 'visitor',
						'body'         => null,
						'created_at'   => '2026-01-01 00:00:01',
					),
				),
			)
		);
		$this->export_client->seed( $entry );

		$result = $this->service()->run( false, 100 );

		$this->assertSame( 1, $result['backfilled'] );

		$map_row  = $this->map->find_by_source_id( 8 );
		$target   = $this->conversations->find_by_id( (int) $map_row->target_conversation_id() );
		$messages = $this->messages->list_for_conversation( $target->id() );

		// null-bodied messages are filtered out of list_for_conversation()
		// by design (matches MessageRepository's own convention); confirm
		// via the map's own recorded count instead.
		$this->assertSame( 0, count( $messages ) );
		$this->assertSame( 1, $map_row->message_count_target() );
	}

	public function test_start_idempotency_key_never_collides_across_multiple_null_source_keys(): void {
		$this->export_client->seed( $this->entry( 9, array( 'start_idempotency_key' => null ) ) );
		$this->export_client->seed( $this->entry( 10, array( 'start_idempotency_key' => null ) ) );

		$result = $this->service()->run( false, 100 );

		$this->assertSame( 2, $result['backfilled'] );
		$this->assertSame( 0, $result['failed'] );
	}

	public function test_delivery_state_is_always_stored_regardless_of_source_value(): void {
		$entry = $this->entry(
			11,
			array(
				'messages' => array(
					array(
						'id'           => 80,
						'message_uuid' => 'm-80',
						'direction'    => 'operator',
						'body'         => 'Sent message',
						'created_at'   => '2026-01-01 00:00:01',
					),
				),
			)
		);
		// Note: ADR-0008's export shape does not even carry delivery_state —
		// this test confirms the target is Support Chat's own constant
		// regardless of anything upstream.
		$this->export_client->seed( $entry );
		$this->service()->run( false, 100 );

		$map_row  = $this->map->find_by_source_id( 11 );
		$target   = $this->conversations->find_by_id( (int) $map_row->target_conversation_id() );
		$messages = $this->messages->list_for_conversation( $target->id() );

		$this->assertSame( 'stored', $messages[0]->delivery_state() );
	}

	public function test_dry_run_writes_nothing_to_any_table(): void {
		$this->export_client->seed(
			$this->entry(
				12,
				array(
					'messages' => array(
						array(
							'id'           => 90,
							'message_uuid' => 'm-90',
							'direction'    => 'visitor',
							'body'         => 'Dry run message',
							'created_at'   => '2026-01-01 00:00:01',
						),
					),
				)
			)
		);

		$result = $this->service()->run( true, 100 );

		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( 1, $result['backfilled'] );
		$this->assertNull( $result['run_id'] );

		$this->assertNull( $this->map->find_by_source_id( 12 ) );
	}

	public function test_cursor_and_high_water_mark_advance_and_a_second_run_only_picks_up_new_rows(): void {
		$this->export_client->seed( $this->entry( 100 ) );
		$first_result = $this->service()->run( false, 100 );
		$this->assertSame( 1, $first_result['backfilled'] );

		// A second run with nothing new seeded processes nothing.
		$second_result = $this->service()->run( false, 100 );
		$this->assertSame( 0, $second_result['processed'] );

		// Simulate Universal Telegram staying live: a new conversation
		// appears after the first pass's high-water mark.
		$this->export_client->seed( $this->entry( 101 ) );
		$third_result = $this->service()->run( false, 100 );

		$this->assertSame( 1, $third_result['processed'] );
		$this->assertSame( 1, $third_result['backfilled'] );

		$calls = $this->export_client->calls();
		$this->assertSame( 0, $calls[0]['after'] );
		// The third run's own call is requested with the cursor as it stood
		// before that run (the high-water mark run 1 already committed);
		// what it receives back (source id 101) is what advances the mark
		// for any future run after this one.
		$this->assertSame( 100, end( $calls )['after'] );
	}

	public function test_a_request_above_100_does_not_stop_after_the_first_100_row_ut_response(): void {
		// Universal Telegram's own LegacyExportServiceV1 never returns more
		// than 100 rows per call, regardless of what is requested
		// (ADR-0008 §5; FakeLegacyExportClient mirrors this). Requesting
		// 500 with 150 rows actually available must still process all 150
		// within this single run() call, across two internal batches of
		// 100 + 50 — not stop after the first 100 because 100 < 500 looks
		// "short" against the raw requested size.
		for ( $id = 1; $id <= 150; $id++ ) {
			$this->export_client->seed( $this->entry( $id ) );
		}

		$result = $this->service()->run( false, 500 );

		$this->assertSame( 150, $result['processed'] );
		$this->assertSame( 150, $result['backfilled'] );
		$this->assertSame( 2, $result['batches'] );

		foreach ( $this->export_client->calls() as $call ) {
			$this->assertSame( 100, $call['limit'], 'Every call must use the effective (capped) batch size, never the raw requested value.' );
		}

		$this->assertNotNull( $this->map->find_by_source_id( 1 ) );
		$this->assertNotNull( $this->map->find_by_source_id( 150 ) );
	}

	public function test_effective_batch_size_is_clamped_for_dry_run_too(): void {
		for ( $id = 1; $id <= 150; $id++ ) {
			$this->export_client->seed( $this->entry( $id ) );
		}

		$result = $this->service()->run( true, 500 );

		$this->assertSame( 150, $result['processed'] );
		$this->assertSame( 2, $result['batches'] );

		foreach ( $this->export_client->calls() as $call ) {
			$this->assertSame( 100, $call['limit'] );
		}

		// Still a dry run: nothing was written.
		$this->assertNull( $this->map->find_by_source_id( 1 ) );
	}

	public function test_a_batch_size_below_the_minimum_is_clamped_up_not_rejected(): void {
		$this->export_client->seed( $this->entry( 1 ) );

		$result = $this->service()->run( false, 0 );

		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( 1, $this->export_client->calls()[0]['limit'] );
	}

	public function test_per_conversation_transaction_rolls_back_on_forced_failure_leaving_no_map_row(): void {
		// A conversation whose 'status' is not a recognized
		// ConversationStatus value forces backfill_writes() to throw after
		// the pending map row and before any target write — proving the
		// whole thing rolls back together, not partially.
		$this->export_client->seed( $this->entry( 200, array( 'status' => 'not-a-real-status' ) ) );

		$result = $this->service()->run( false, 100 );

		$this->assertSame( 1, $result['failed'] );
		$this->assertNull( $this->map->find_by_source_id( 200 ) );
	}
}
