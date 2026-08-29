<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Core;

use PHPUnit\Framework\TestCase;
use UniversalSupportChat\Core\Plugin;
use UniversalSupportChat\Persistence\Migrator;

/**
 * Structural proof that the obsolete SC-M03 legacy-migration / final-cutover
 * engine (ADR-0013) is gone from `src/` while ADR-0012 Telegram dispatch and
 * the inbound Contract path remain.
 */
final class ScM03EngineRetirementTest extends TestCase {

	/**
	 * @var array<int, string>
	 */
	private const RETIRED_CLASSES = array(
		'UniversalSupportChat\\Migration\\Cli\\LegacyMigrateCommand',
		'UniversalSupportChat\\Migration\\Cli\\LegacyBindCommand',
		'UniversalSupportChat\\Migration\\PhaseABackfillService',
		'UniversalSupportChat\\Migration\\PhaseBReconciliationService',
		'UniversalSupportChat\\Migration\\LegacyExportClient',
		'UniversalSupportChat\\Migration\\InProcessLegacyExportClient',
		'UniversalSupportChat\\Migration\\LegacyBindingImportService',
		'UniversalSupportChat\\Migration\\InProcessLegacyBindingImportClient',
		'UniversalSupportChat\\Migration\\QuiescenceStateProvider',
		'UniversalSupportChat\\Migration\\UniversalTelegramQuiescenceStateProvider',
		'UniversalSupportChat\\Migration\\DefaultDenyQuiescenceStateProvider',
		'UniversalSupportChat\\Migration\\LegacyMigrationMapRepository',
		'UniversalSupportChat\\Migration\\LegacyMigrationValidator',
		'UniversalSupportChat\\Migration\\LegacyFieldMap',
		'UniversalSupportChat\\ChannelContract\\HandoffMapRepository',
	);

	public function test_every_retired_engine_class_is_gone(): void {
		foreach ( self::RETIRED_CLASSES as $class ) {
			$this->assertFalse(
				class_exists( $class ) || interface_exists( $class ),
				$class . ' should have been removed by ADR-0013'
			);
		}
	}

	public function test_src_migration_directory_no_longer_exists(): void {
		$this->assertDirectoryDoesNotExist( dirname( __DIR__, 3 ) . '/src/Migration' );
	}

	public function test_composition_root_exposes_no_legacy_migration_accessors(): void {
		foreach ( array( 'legacy_migration_map', 'phase_a_backfill_service', 'phase_b_reconciliation_service', 'legacy_migration_validator' ) as $method ) {
			$this->assertFalse(
				method_exists( Plugin::class, $method ),
				'Plugin::' . $method . '() should have been removed by ADR-0013'
			);
		}
	}

	public function test_contract_dispatcher_has_no_cutover_provenance_surface(): void {
		$reflection = new \ReflectionClass( \UniversalSupportChat\ChannelContract\Rest\ContractOperationDispatcher::class );

		$this->assertFalse( $reflection->hasMethod( 'dispatch_with_provenance' ) );
		$this->assertFalse( $reflection->hasMethod( 'provenance' ) );

		$params = $reflection->getConstructor()->getParameters();
		$names  = array_map( static fn ( \ReflectionParameter $p ): string => $p->getName(), $params );
		$this->assertNotContains( 'handoff_map', $names );
		// The retained inbound path + its ADR-0012 loop-prevention hook.
		$this->assertTrue( $reflection->hasMethod( 'ingest_operator_reply' ) );
		$this->assertContains( 'dispatch', $names, 'the optional DispatchEnqueuer is still injected' );
	}

	public function test_schema_version_stays_at_12_and_legacy_manifest_constants_are_retained(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Persistence/Migrator.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local read.

		$this->assertMatchesRegularExpression( '/function target_version\(\): int \{\s*return 12;/', $source );

		// Steps 9-11 are retired inert no-ops (create no obsolete table).
		$this->assertStringContainsString( 'Retired (ADR-0013)', $source );

		// Name-only manifest constants kept for uninstall compatibility / diagnostics.
		$this->assertSame( 'universal_support_chat_legacy_migration_runs', Migrator::LEGACY_MIGRATION_RUNS_TABLE );
		$this->assertSame( 'universal_support_chat_legacy_migration_map', Migrator::LEGACY_MIGRATION_MAP_TABLE );
		$this->assertSame( 'universal_support_chat_legacy_handoff_map', Migrator::LEGACY_HANDOFF_MAP_TABLE );
	}
}
