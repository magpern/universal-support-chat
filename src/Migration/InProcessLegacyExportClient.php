<?php
/**
 * Production LegacyExportClient: calls Universal Telegram's
 * LegacyExportServiceV1 in-process.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Migration;

/**
 * The only class in this repository that ever references Universal
 * Telegram's namespace, and only ever this one call (Support Chat
 * ADR-0008 §2, ADR-0002/ADR-0007 §1 "no plugin reads or writes another
 * plugin's database tables directly"). Never touches a
 * `universal_telegram_*` table, never holds Universal Telegram's vault key
 * material, and adds no REST route, Ajax handler, or Contract v1
 * operation. Universal Telegram is an optional plugin this repository does
 * not depend on at the package-manager level, so every reference to its
 * class is guarded by `class_exists()` at runtime.
 */
final class InProcessLegacyExportClient implements LegacyExportClient {

	private const UT_PLUGIN_CLASS = '\UniversalTelegram\Core\Plugin';

	/**
	 * Calls Universal Telegram's `LegacyExportServiceV1::export_batch()` in-process.
	 *
	 * @param int $after_source_id The highest legacy conversation id already processed; 0 for the first batch.
	 * @param int $limit           Requested batch size.
	 *
	 * @return array{export_schema_version: int, conversations: array<int, array<string, mixed>>, error?: string}
	 *
	 * @throws LegacyExportUnavailableException If the call cannot be completed for any reason.
	 */
	public function export_batch( int $after_source_id, int $limit ): array {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			throw new LegacyExportUnavailableException(
				'Legacy migration may only run from a WP-CLI process (Support Chat ADR-0008 §4).'
			);
		}

		if ( ! class_exists( self::UT_PLUGIN_CLASS ) ) {
			throw new LegacyExportUnavailableException( 'Universal Telegram is not active.' );
		}

		$ut_plugin = \UniversalTelegram\Core\Plugin::instance();

		if ( ! method_exists( $ut_plugin, 'legacy_export_service' ) ) {
			throw new LegacyExportUnavailableException( 'Universal Telegram is running an incompatible version (no legacy_export_service()).' );
		}

		$service = $ut_plugin->legacy_export_service();

		if ( null === $service ) {
			throw new LegacyExportUnavailableException( 'Universal Telegram legacy export boundary is unavailable.' );
		}

		try {
			$result = $service->export_batch( $after_source_id, $limit );
		} catch ( \Throwable $exception ) {
			throw new LegacyExportUnavailableException(
				'Universal Telegram refused the export call: ' . $exception->getMessage(),
				0,
				$exception
			);
		}

		if ( ! is_array( $result ) || ! isset( $result['export_schema_version'], $result['conversations'] ) ) {
			throw new LegacyExportUnavailableException( 'Universal Telegram returned a malformed export envelope.' );
		}

		if ( 1 !== $result['export_schema_version'] ) {
			throw new LegacyExportUnavailableException(
				'Unsupported export_schema_version: ' . (string) $result['export_schema_version'] . ' (this engine understands only version 1).'
			);
		}

		if ( isset( $result['error'] ) ) {
			throw new LegacyExportUnavailableException( 'Universal Telegram refused the export batch: ' . (string) $result['error'] );
		}

		return $result;
	}
}
