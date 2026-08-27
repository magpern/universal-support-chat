<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Migration\Cli;

require_once __DIR__ . '/WPCliStub.php';

use UniversalSupportChat\Migration\Cli\LegacyBindCommand;
use UniversalSupportChat\Migration\LegacyBindingImportService;
use UniversalSupportChat\Migration\LegacyMigrationMapRepository;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalSupportChat\Tests\Integration\Migration\Support\FakeLegacyBindingImportClient;
use UniversalSupportChat\Tests\Integration\Migration\Support\FakeQuiescenceStateProvider;
use WP_UnitTestCase;

final class LegacyBindCommandTest extends WP_UnitTestCase {

	private LegacyMigrationMapRepository $map;
	private FakeLegacyBindingImportClient $client;
	private FakeQuiescenceStateProvider $quiescence;
	private LegacyBindCommand $command;

	public function set_up(): void {
		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		$this->truncate_tables_committed_by_real_transactions();
		\WP_CLI::reset();

		$health           = new SchemaHealth();
		$this->map        = new LegacyMigrationMapRepository( $health );
		$this->client     = new FakeLegacyBindingImportClient();
		$this->quiescence = ( new FakeQuiescenceStateProvider() )->make_quiescent();
		$service          = new LegacyBindingImportService( $this->map, $this->client, $this->quiescence );

		$this->command = new LegacyBindCommand( $service, $this->map, $this->quiescence );
	}

	private function truncate_tables_committed_by_real_transactions(): void {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::LEGACY_MIGRATION_MAP_TABLE;
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, test cleanup only.
	}

	private function seed_migrated( int $source_id ): void {
		$entry = $this->map->create_pending( $source_id, 'source-uuid-' . $source_id, 5, 50, 500, 'created', 'active' );
		$this->map->mark_backfilled( $entry->id(), 900 + $source_id, 'target-uuid-' . $source_id, 1, 1, 0, 0 );
		$this->map->mark_migrated( $entry->id(), true, 1, 0 );
	}

	public function test_run_without_dry_run_or_the_authority_flag_is_refused_and_writes_nothing(): void {
		$this->seed_migrated( 1 );

		$this->command->dispatch( array( 'run' ), array() );

		$this->assertSame( 'error', \WP_CLI::$calls[0]['method'] );
		$this->assertStringContainsString( '--assume-binding-authority', \WP_CLI::$calls[0]['message'] );
		$this->assertNull( $this->map->find_by_source_id( 1 )->binding_status() );
	}

	public function test_run_with_assume_binding_authority_writes_real_data(): void {
		$this->seed_migrated( 1 );

		$this->command->dispatch( array( 'run' ), array( 'assume-binding-authority' => true ) );

		$this->assertSame( 'success', \WP_CLI::$calls[0]['method'] );
		$this->assertSame( 'created', $this->map->find_by_source_id( 1 )->binding_status() );
	}

	public function test_dry_run_never_requires_the_authority_flag_and_writes_nothing(): void {
		$this->seed_migrated( 1 );

		$this->command->dispatch( array( 'run' ), array( 'dry-run' => true ) );

		$this->assertSame( 'success', \WP_CLI::$calls[0]['method'] );
		$this->assertNull( $this->map->find_by_source_id( 1 )->binding_status() );
	}

	public function test_run_refuses_when_not_quiescent_before_any_authority_check(): void {
		$this->seed_migrated( 1 );
		$this->quiescence->make_not_quiescent();

		$this->command->dispatch( array( 'run' ), array( 'assume-binding-authority' => true ) );

		$this->assertSame( 'error', \WP_CLI::$calls[0]['method'] );
		$this->assertStringContainsString( 'not_quiescent', \WP_CLI::$calls[0]['message'] );
	}

	public function test_status_subcommand_never_requires_the_authority_flag(): void {
		$this->seed_migrated( 1 );
		$this->command->dispatch( array( 'run' ), array( 'assume-binding-authority' => true ) );
		\WP_CLI::reset();

		$this->command->dispatch( array( 'status' ), array() );

		$logged = array_column( \WP_CLI::$calls, 'message' );
		$this->assertContains( sprintf( '%-16s %d', 'created', 1 ), $logged );
	}

	public function test_validate_subcommand_never_requires_the_authority_flag_and_writes_nothing(): void {
		$this->seed_migrated( 1 );

		$this->command->dispatch( array( 'validate' ), array() );

		$this->assertSame( 'success', \WP_CLI::$calls[ count( \WP_CLI::$calls ) - 1 ]['method'] );
		$this->assertNull( $this->map->find_by_source_id( 1 )->binding_status() );
	}
}
