<?php
/**
 * WP-CLI entry point for SC-M03 work package 5 binding preparation.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Migration\Cli;

use UniversalSupportChat\Migration\LegacyBindingImportService;
use UniversalSupportChat\Migration\LegacyMigrationMapRepository;
use UniversalSupportChat\Migration\QuiescenceStateProvider;

/**
 * Registers `wp universal-support-chat legacy-bind {run,status,validate}`
 * only when WP-CLI is present (Support Chat ADR-0009 §7 — this repository
 * registers no CLI entry point of its own outside a WP-CLI process).
 * `run` is the only subcommand that ever writes anything, and only when
 * `--dry-run` is absent — a real mutating invocation additionally requires
 * `--assume-binding-authority`, a mandatory operator-confirmation guard
 * against *accidental* invocation, named distinctly from
 * `--assume-migration-authority` since it confirms a separate, later
 * operation (ADR-0009 §7).
 */
final class LegacyBindCommand {

	/**
	 * Constructor.
	 *
	 * @param LegacyBindingImportService   $service    Run/validate orchestrator.
	 * @param LegacyMigrationMapRepository $map        Status reporting.
	 * @param QuiescenceStateProvider      $quiescence Status reporting only.
	 */
	public function __construct(
		private readonly LegacyBindingImportService $service,
		private readonly LegacyMigrationMapRepository $map,
		private readonly QuiescenceStateProvider $quiescence
	) {}

	/**
	 * Registers the WP-CLI command when WP_CLI is present.
	 */
	public function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'universal-support-chat legacy-bind', array( $this, 'dispatch' ) );
	}

	/**
	 * WP-CLI dispatcher.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : run|status|validate
	 *
	 * [--dry-run]
	 * : Exercise the full pipeline, including the in-process call into
	 * Universal Telegram's live re-check and lock-scoped quiescence
	 * assertion, but commit no write on either side (default for `run`).
	 *
	 * [--assume-binding-authority]
	 * : Required before `run` performs any real (non-dry-run) write.
	 * Confirms the operator intends this invocation; not a security control.
	 *
	 * [--limit=<n>]
	 * : Max candidates for this invocation. Default 100.
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
				$this->validate( $assoc_args );
				break;
			default:
				// @phpstan-ignore-next-line class.notFound
				\WP_CLI::error( 'Usage: wp universal-support-chat legacy-bind <run|status|validate> [--dry-run] [--assume-binding-authority] [--limit=<n>]' );
		}
	}

	/**
	 * `run` subcommand.
	 *
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	private function run( array $assoc_args ): void {
		$dry_run = isset( $assoc_args['dry-run'] );
		$limit   = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 100;

		if ( ! $dry_run && ! isset( $assoc_args['assume-binding-authority'] ) ) {
			// @phpstan-ignore-next-line class.notFound
			\WP_CLI::error( 'Refusing a real (non-dry-run) binding write without --assume-binding-authority. This flag confirms the operator intends this invocation; it is not a security control (Support Chat ADR-0009 §7).' );
			return;
		}

		$result = $this->service->run( $dry_run, $limit );

		if ( $result['refused'] ) {
			// @phpstan-ignore-next-line class.notFound
			\WP_CLI::error( 'Refused: ' . (string) $result['reason'] . ' (early, non-authoritative pre-check; Support Chat ADR-0009 §5).' );
			return;
		}

		// @phpstan-ignore-next-line class.notFound
		\WP_CLI::success(
			sprintf(
				'legacy-bind run (%s): checked=%d created=%d skipped=%d conflict=%d retryable=%d',
				$dry_run ? 'dry-run' : 'complete',
				$result['checked'],
				$result['created'],
				$result['skipped'],
				$result['conflict'],
				$result['retryable']
			)
		);
	}

	/**
	 * `status` subcommand — aggregate operational evidence only, never plaintext.
	 */
	private function status(): void {
		$counts = $this->map->counts_by_binding_status();

		foreach ( $counts as $label => $count ) {
			// @phpstan-ignore-next-line class.notFound
			\WP_CLI::log( sprintf( '%-16s %d', $label, $count ) );
		}

		// @phpstan-ignore-next-line class.notFound
		\WP_CLI::log( sprintf( 'is_quiescent     %s', $this->quiescence->is_quiescent() ? 'true' : 'false' ) );
	}

	/**
	 * `validate` subcommand — read-only structural preview; writes nothing
	 * and never calls Universal Telegram.
	 *
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	private function validate( array $assoc_args ): void {
		$limit  = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 10000;
		$result = $this->service->validate( $limit );

		// @phpstan-ignore-next-line class.notFound
		\WP_CLI::success(
			sprintf(
				'legacy-bind validate: checked=%d structurally_eligible=%d structurally_excluded=%d',
				$result['checked'],
				$result['structurally_eligible'],
				$result['structurally_excluded']
			)
		);
	}
}
