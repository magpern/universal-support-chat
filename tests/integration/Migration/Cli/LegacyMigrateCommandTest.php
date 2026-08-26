<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Migration\Cli;

require_once __DIR__ . '/WPCliStub.php';

use UniversalSupportChat\Conversations\ConversationRepository;
use UniversalSupportChat\Conversations\ConversationStatus;
use UniversalSupportChat\Conversations\MessageRepository;
use UniversalSupportChat\Conversations\NoteRepository;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Migration\Cli\LegacyMigrateCommand;
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
use WP_UnitTestCase;

final class LegacyMigrateCommandTest extends WP_UnitTestCase {

	private LegacyMigrationMapRepository $map;
	private FakeLegacyExportClient $export_client;
	private LegacyMigrateCommand $command;

	public function set_up(): void {
		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		$this->truncate_tables_committed_by_real_transactions();
		\WP_CLI::reset();

		$health              = new SchemaHealth();
		$vault               = new CredentialVault();
		$conversations       = new ConversationRepository( $health );
		$messages            = new MessageRepository( $health, $vault );
		$notes               = new NoteRepository( $health, $vault );
		$this->map           = new LegacyMigrationMapRepository( $health );
		$message_map         = new LegacyMigrationMessageMapRepository( $health );
		$this->export_client = new FakeLegacyExportClient();
		$validator           = new LegacyMigrationValidator( $messages, $notes, $message_map );

		$backfill = new PhaseABackfillService(
			$this->export_client,
			$conversations,
			$messages,
			$notes,
			$this->map,
			$message_map,
			new LegacyMigrationRunRepository( $health ),
			new LegacyMigrationBatchLogRepository( $health )
		);

		$reconcile = new PhaseBReconciliationService(
			$this->export_client,
			new DefaultDenyQuiescenceStateProvider(),
			$messages,
			$notes,
			$this->map,
			$message_map,
			$validator
		);

		$this->command = new LegacyMigrateCommand( $backfill, $reconcile, $this->map, $validator );
	}

	/**
	 * See PhaseABackfillServiceTest's identical helper.
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

	public function test_run_without_dry_run_or_the_authority_flag_is_refused_and_writes_nothing(): void {
		$this->export_client->seed( $this->entry( 1 ) );

		$this->command->dispatch( array( 'run' ), array( 'phase' => 'backfill' ) );

		$this->assertSame( 'error', \WP_CLI::$calls[0]['method'] );
		$this->assertStringContainsString( '--assume-migration-authority', \WP_CLI::$calls[0]['message'] );
		$this->assertNull( $this->map->find_by_source_id( 1 ) );
	}

	public function test_run_with_assume_migration_authority_writes_real_data(): void {
		$this->export_client->seed( $this->entry( 1 ) );

		$this->command->dispatch(
			array( 'run' ),
			array(
				'phase'                      => 'backfill',
				'assume-migration-authority' => true,
			)
		);

		$this->assertSame( 'success', \WP_CLI::$calls[0]['method'] );
		$map_row = $this->map->find_by_source_id( 1 );
		$this->assertNotNull( $map_row );
		$this->assertSame( LegacyMigrationMapEntry::STATUS_BACKFILLED, $map_row->status() );
	}

	public function test_dry_run_never_requires_the_authority_flag_and_writes_nothing(): void {
		$this->export_client->seed( $this->entry( 1 ) );

		$this->command->dispatch(
			array( 'run' ),
			array(
				'phase'   => 'backfill',
				'dry-run' => true,
			)
		);

		$this->assertSame( 'success', \WP_CLI::$calls[0]['method'] );
		$this->assertStringContainsString( 'dry run', \WP_CLI::$calls[0]['message'] );
		$this->assertNull( $this->map->find_by_source_id( 1 ) );
	}

	public function test_reconcile_phase_without_the_authority_flag_is_also_refused(): void {
		$this->command->dispatch( array( 'run' ), array( 'phase' => 'reconcile' ) );

		$this->assertSame( 'error', \WP_CLI::$calls[0]['method'] );
		$this->assertStringContainsString( '--assume-migration-authority', \WP_CLI::$calls[0]['message'] );
	}

	public function test_status_subcommand_never_requires_the_authority_flag(): void {
		$this->export_client->seed( $this->entry( 1 ) );
		$this->command->dispatch(
			array( 'run' ),
			array(
				'phase'                      => 'backfill',
				'assume-migration-authority' => true,
			)
		);
		\WP_CLI::reset();

		$this->command->dispatch( array( 'status' ), array() );

		$logged = array_column( \WP_CLI::$calls, 'message' );
		$this->assertContains( 'backfilled   1', $logged );
	}

	public function test_validate_subcommand_never_requires_the_authority_flag_and_writes_nothing(): void {
		$this->export_client->seed( $this->entry( 1 ) );
		$this->command->dispatch(
			array( 'run' ),
			array(
				'phase'                      => 'backfill',
				'assume-migration-authority' => true,
			)
		);

		$before = $this->map->find_by_source_id( 1 );
		\WP_CLI::reset();

		$this->command->dispatch( array( 'validate' ), array() );

		$this->assertSame( 'success', \WP_CLI::$calls[ count( \WP_CLI::$calls ) - 1 ]['method'] );

		$after = $this->map->find_by_source_id( 1 );
		$this->assertSame( $before->status(), $after->status() );
		$this->assertSame( $before->updated_at(), $after->updated_at() );
	}
}
