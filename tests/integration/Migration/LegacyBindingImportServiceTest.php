<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Migration;

use UniversalSupportChat\Migration\LegacyBindingImportService;
use UniversalSupportChat\Migration\LegacyBindingOutcome;
use UniversalSupportChat\Migration\LegacyMigrationMapRepository;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalSupportChat\Tests\Integration\Migration\Support\FakeLegacyBindingImportClient;
use UniversalSupportChat\Tests\Integration\Migration\Support\FakeQuiescenceStateProvider;
use WP_UnitTestCase;

/**
 * Against a real `LegacyMigrationMapRepository`/real DB, but a fake
 * Universal Telegram write boundary (Support Chat ADR-0009's own test
 * seam) — proving the structural eligibility checks, the terminal/
 * retryable persistence split, and dry-run's zero-write guarantee.
 *
 * @covers \UniversalSupportChat\Migration\LegacyBindingImportService
 */
final class LegacyBindingImportServiceTest extends WP_UnitTestCase {

	private LegacyMigrationMapRepository $map;
	private FakeLegacyBindingImportClient $client;
	private FakeQuiescenceStateProvider $quiescence;
	private LegacyBindingImportService $service;

	public function set_up(): void {
		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		$this->truncate_tables_committed_by_real_transactions();

		$health           = new SchemaHealth();
		$this->map        = new LegacyMigrationMapRepository( $health );
		$this->client     = new FakeLegacyBindingImportClient();
		$this->quiescence = ( new FakeQuiescenceStateProvider() )->make_quiescent();
		$this->service    = new LegacyBindingImportService( $this->map, $this->client, $this->quiescence );
	}

	private function truncate_tables_committed_by_real_transactions(): void {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::LEGACY_MIGRATION_MAP_TABLE;
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, test cleanup only.
	}

	/**
	 * Seeds one `migrated` map row with the given legacy topic fields.
	 */
	private function seed_migrated(
		int $source_id,
		?int $bot_id = 5,
		?int $destination_id = 50,
		?int $telegram_topic_id = 500,
		?string $creation_state = 'created',
		?string $lifecycle_state = 'active'
	): void {
		$entry = $this->map->create_pending(
			$source_id,
			'source-uuid-' . $source_id,
			$bot_id,
			$destination_id,
			$telegram_topic_id,
			$creation_state,
			$lifecycle_state
		);
		$this->map->mark_backfilled( $entry->id(), 900 + $source_id, 'target-uuid-' . $source_id, 1, 1, 0, 0 );
		$this->map->mark_migrated( $entry->id(), true, 1, 0 );
	}

	public function test_created_row_is_terminal_and_calls_client_with_correct_shape(): void {
		$this->seed_migrated( 1 );

		$result = $this->service->run( false, 100 );

		$this->assertFalse( $result['refused'] );
		$this->assertSame( 1, $result['created'] );

		$entry = $this->map->find_by_source_id( 1 );
		$this->assertSame( 'created', $entry->binding_status() );
		$this->assertSame( 'fake-binding-uuid-1', $entry->binding_uuid() );
		$this->assertNull( $entry->binding_error_reason() );

		$candidate = $this->client->received_batches[0][0];
		$this->assertSame( 1, $candidate['source_conversation_id'] );
		$this->assertSame( 5, $candidate['bot_id'] );
		$this->assertSame( 50, $candidate['destination_id'] );
		$this->assertSame( 500, $candidate['telegram_topic_id'] );
		$this->assertSame( 'target-uuid-1', $candidate['support_conversation_uuid'] );
	}

	public function test_terminal_row_is_never_rescanned(): void {
		$this->seed_migrated( 1 );
		$this->service->run( false, 100 );

		$this->client->received_batches = array();
		$result                         = $this->service->run( false, 100 );

		$this->assertSame( 0, $result['checked'] );
		$this->assertSame( array(), $this->client->received_batches );
	}

	/**
	 * @dataProvider structural_exclusions
	 */
	public function test_structural_exclusions_are_terminal_and_never_call_the_client( ?int $bot_id, ?int $destination_id, ?int $telegram_topic_id, ?string $creation_state, ?string $lifecycle_state, string $expected_outcome ): void {
		$this->seed_migrated( 1, $bot_id, $destination_id, $telegram_topic_id, $creation_state, $lifecycle_state );

		$result = $this->service->run( false, 100 );

		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( array(), $this->client->received_batches, 'A structural exclusion must never call the client at all.' );

		$entry = $this->map->find_by_source_id( 1 );
		$this->assertSame( 'skipped', $entry->binding_status() );
		$this->assertSame( $expected_outcome, $entry->binding_error_reason() );
	}

	/**
	 * @return array<string, array{0:?int,1:?int,2:?int,3:?string,4:?string,5:string}>
	 */
	public static function structural_exclusions(): array {
		return array(
			'no topic'                   => array( 5, 50, null, 'created', 'active', LegacyBindingOutcome::SKIP_NO_TOPIC ),
			'missing bot'                => array( null, 50, 500, 'created', 'active', LegacyBindingOutcome::SKIP_MISSING_BOT_OR_DESTINATION ),
			'missing destination'        => array( 5, null, 500, 'created', 'active', LegacyBindingOutcome::SKIP_MISSING_BOT_OR_DESTINATION ),
			'topic not created'          => array( 5, 50, 500, 'pending', 'none', LegacyBindingOutcome::SKIP_TOPIC_NOT_CREATED ),
			'topic lifecycle not active' => array( 5, 50, 500, 'created', 'unavailable', LegacyBindingOutcome::SKIP_TOPIC_LIFECYCLE_TERMINAL ),
		);
	}

	public function test_conflict_outcome_is_terminal(): void {
		$this->seed_migrated( 1 );
		$this->client->queue_outcome( 1, LegacyBindingOutcome::CONFLICT_EXISTING_ACTIVE );

		$result = $this->service->run( false, 100 );

		$this->assertSame( 1, $result['conflict'] );
		$entry = $this->map->find_by_source_id( 1 );
		$this->assertSame( 'conflict', $entry->binding_status() );
		$this->assertSame( LegacyBindingOutcome::CONFLICT_EXISTING_ACTIVE, $entry->binding_error_reason() );
	}

	public function test_retryable_outcome_is_never_terminal_and_is_rescanned(): void {
		$this->seed_migrated( 1 );
		$this->client->queue_outcome( 1, LegacyBindingOutcome::RETRY_NOT_QUIESCENT );

		$first = $this->service->run( false, 100 );
		$this->assertSame( 1, $first['retryable'] );

		$entry = $this->map->find_by_source_id( 1 );
		$this->assertNull( $entry->binding_status(), 'A retryable outcome must never write binding_status.' );
		$this->assertSame( LegacyBindingOutcome::RETRY_NOT_QUIESCENT, $entry->binding_last_attempt_reason() );

		// Next ordinary run re-selects it automatically, no special flag.
		$this->client->queue_outcome( 1, LegacyBindingOutcome::CREATED );
		$second = $this->service->run( false, 100 );

		$this->assertSame( 1, $second['created'] );
		$entry = $this->map->find_by_source_id( 1 );
		$this->assertSame( 'created', $entry->binding_status() );
	}

	public function test_early_quiescence_pre_check_refuses_before_any_client_call(): void {
		$this->seed_migrated( 1 );
		$this->quiescence->make_not_quiescent();

		$result = $this->service->run( false, 100 );

		$this->assertTrue( $result['refused'] );
		$this->assertSame( 'not_quiescent', $result['reason'] );
		$this->assertSame( array(), $this->client->received_batches );
		$this->assertNull( $this->map->find_by_source_id( 1 )->binding_status() );
	}

	public function test_whole_batch_unavailable_exception_is_retryable_for_every_candidate(): void {
		$this->seed_migrated( 1 );
		$this->seed_migrated( 2 );
		$this->client->make_unavailable();

		$result = $this->service->run( false, 100 );

		$this->assertSame( 2, $result['retryable'] );
		$this->assertNull( $this->map->find_by_source_id( 1 )->binding_status() );
		$this->assertSame( LegacyBindingOutcome::RETRY_UT_UNAVAILABLE_OR_INDETERMINATE, $this->map->find_by_source_id( 1 )->binding_last_attempt_reason() );
	}

	public function test_dry_run_writes_nothing_at_all(): void {
		$this->seed_migrated( 1 );

		$result = $this->service->run( true, 100 );

		$this->assertSame( 1, $result['created'] );

		$entry = $this->map->find_by_source_id( 1 );
		$this->assertNull( $entry->binding_status() );
		$this->assertNull( $entry->binding_last_attempt_at() );
		$this->assertNull( $entry->binding_uuid() );
	}

	public function test_validate_never_calls_the_client_and_writes_nothing(): void {
		$this->seed_migrated( 1 );
		$this->seed_migrated( 2, 5, 50, null ); // structurally excluded (no topic).

		$result = $this->service->validate();

		$this->assertSame( 2, $result['checked'] );
		$this->assertSame( 1, $result['structurally_eligible'] );
		$this->assertSame( 1, $result['structurally_excluded'] );
		$this->assertSame( array(), $this->client->received_batches );
		$this->assertNull( $this->map->find_by_source_id( 1 )->binding_status() );
	}
}
