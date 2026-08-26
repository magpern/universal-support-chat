<?php
/**
 * WP-CLI entry point for the SC-M03 legacy migration engine.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Migration\Cli;

use UniversalSupportChat\Migration\LegacyMigrationMapRepository;
use UniversalSupportChat\Migration\LegacyMigrationMessageMapRepository;
use UniversalSupportChat\Migration\LegacyMigrationValidator;
use UniversalSupportChat\Migration\PhaseABackfillService;
use UniversalSupportChat\Migration\PhaseBReconciliationService;

/**
 * Registers `wp universal-support-chat legacy-migrate {run,status,validate}`
 * only when WP-CLI is present (Support Chat ADR-0008 §4 — this repository
 * registers no CLI entry point of its own outside a WP-CLI process).
 * `run` is the only subcommand that ever writes anything, and only when
 * `--dry-run` is absent — a real mutating invocation additionally requires
 * `--assume-migration-authority`, a mandatory operator-confirmation guard
 * against *accidental* invocation, never a security control (ADR-0008 §4:
 * the real boundary is host authority to execute WP-CLI at all).
 */
final class LegacyMigrateCommand {

	/**
	 * Constructor.
	 *
	 * @param PhaseABackfillService                $backfill    Phase A.
	 * @param PhaseBReconciliationService           $reconcile   Phase B.
	 * @param LegacyMigrationMapRepository          $map         Status reporting.
	 * @param LegacyMigrationValidator              $validator   Read-only validation reporting.
	 */
	public function __construct(
		private readonly PhaseABackfillService $backfill,
		private readonly PhaseBReconciliationService $reconcile,
		private readonly LegacyMigrationMapRepository $map,
		private readonly LegacyMigrationValidator $validator
	) {}

	/**
	 * Registers the WP-CLI command when WP_CLI is present.
	 */
	public function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'universal-support-chat legacy-migrate', array( $this, 'dispatch' ) );
	}

	/**
	 * WP-CLI dispatcher.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : run|status|validate
	 *
	 * [--phase=<phase>]
	 * : backfill|reconcile. Only meaningful for `run`. Default: backfill.
	 *
	 * [--dry-run]
	 * : Report only; write nothing to any Support Chat table (default for `run`).
	 *
	 * [--assume-migration-authority]
	 * : Required before `run` performs any real (non-dry-run) write.
	 * Confirms the operator intends this invocation; not a security control.
	 *
	 * [--batch-size=<n>]
	 * : Requested export batch size for `run --phase=backfill`. Default 100.
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function dispatch( array $args, array $assoc_args ): void {
		$action = $args[0] ?? '';

		switch ( $action ) {
			case 'run':
				$this->run( $assoc_args );
				break;
			case 'status':
				$this->status();
				break;
			case 'validate':
				$this->validate();
				break;
			default:
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- WP-CLI path only.
				// @phpstan-ignore-next-line class.notFound
				\WP_CLI::error( 'Usage: wp universal-support-chat legacy-migrate <run|status|validate> [--phase=backfill|reconcile] [--dry-run] [--assume-migration-authority] [--batch-size=<n>]' );
		}
	}

	/**
	 * `run` subcommand.
	 *
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	private function run( array $assoc_args ): void {
		$phase   = isset( $assoc_args['phase'] ) ? (string) $assoc_args['phase'] : 'backfill';
		$dry_run = isset( $assoc_args['dry-run'] );

		if ( ! $dry_run && ! isset( $assoc_args['assume-migration-authority'] ) ) {
			// @phpstan-ignore-next-line class.notFound
			\WP_CLI::error( 'Refusing a real (non-dry-run) migration write without --assume-migration-authority. This flag confirms the operator intends this invocation; it is not a security control (Support Chat ADR-0008 §4).' );
			return;
		}

		if ( 'backfill' === $phase ) {
			$batch_size = isset( $assoc_args['batch-size'] ) ? max( 1, (int) $assoc_args['batch-size'] ) : 100;
			$result     = $this->backfill->run( $dry_run, $batch_size, $this->current_user_id() );

			// @phpstan-ignore-next-line class.notFound
			\WP_CLI::success(
				sprintf(
					'Phase A %s: %d batch(es), %d processed, %d backfilled, %d skipped, %d failed.',
					$dry_run ? '(dry run)' : 'complete',
					$result['batches'],
					$result['processed'],
					$result['backfilled'],
					$result['skipped'],
					$result['failed']
				)
			);

			return;
		}

		if ( 'reconcile' === $phase ) {
			$result = $this->reconcile->run( $dry_run );

			if ( 'refused' === $result['status'] ) {
				// @phpstan-ignore-next-line class.notFound
				\WP_CLI::error( 'Phase B refused to run: ' . (string) $result['reason'] );
				return;
			}

			// @phpstan-ignore-next-line class.notFound
			\WP_CLI::success(
				sprintf(
					'Phase B %s: %d checked, %d validated, %d failed.',
					$dry_run ? '(dry run)' : 'complete',
					$result['checked'],
					$result['validated'],
					$result['failed']
				)
			);

			return;
		}

		// @phpstan-ignore-next-line class.notFound
		\WP_CLI::error( 'Unknown --phase: ' . $phase . ' (expected backfill|reconcile).' );
	}

	/**
	 * `status` subcommand — aggregate operational evidence only, never plaintext.
	 */
	private function status(): void {
		$counts = $this->map->counts_by_status();

		foreach ( $counts as $status => $count ) {
			// @phpstan-ignore-next-line class.notFound
			\WP_CLI::log( sprintf( '%-12s %d', $status, $count ) );
		}
	}

	/**
	 * `validate` subcommand — read-only; writes nothing.
	 */
	private function validate(): void {
		$registry_errors = $this->validator->validate_registry_self_consistency();

		foreach ( $registry_errors as $error ) {
			// @phpstan-ignore-next-line class.notFound
			\WP_CLI::warning( $error );
		}

		$checked = 0;
		$passed  = 0;

		foreach ( $this->map->find_backfilled( 10000 ) as $entry ) {
			++$checked;

			if ( $this->validator->validate_counts( $entry ) && $this->validator->validate_correspondence( $entry ) ) {
				++$passed;
			}
		}

		// @phpstan-ignore-next-line class.notFound
		\WP_CLI::success( sprintf( 'Validated %d backfilled conversation(s): %d passed count/correspondence checks.', $checked, $passed ) );
	}

	/**
	 * The invoking operator's WP user id, if this WP-CLI process was
	 * started with `--user`; null otherwise (a purely operational-evidence
	 * field on the run record, never used for authorization).
	 */
	private function current_user_id(): ?int {
		$id = get_current_user_id();

		return $id > 0 ? $id : null;
	}
}
