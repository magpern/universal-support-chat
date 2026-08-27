<?php
/**
 * Production LegacyBindingImportClient: calls Universal Telegram's
 * LegacyBindingImportServiceV1 in-process.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Migration;

/**
 * Symmetric to `InProcessLegacyExportClient`, for the write direction
 * (Support Chat ADR-0009 §2, ADR-0002/ADR-0007 §1 "no plugin reads or
 * writes another plugin's database tables directly"). Never touches a
 * `universal_telegram_*` table, never holds Universal Telegram's vault key
 * material, and adds no REST route, Ajax handler, or Contract v1
 * operation. Every reference to Universal Telegram's class is guarded by
 * `class_exists()` at runtime, since it is an optional plugin this
 * repository does not depend on at the package-manager level.
 */
final class InProcessLegacyBindingImportClient implements LegacyBindingImportClient {

	private const UT_PLUGIN_CLASS = '\UniversalTelegram\Core\Plugin';

	/**
	 * Calls Universal Telegram's `LegacyBindingImportServiceV1::import_batch()` in-process.
	 *
	 * @param array<int, array{source_conversation_id:int, bot_id:int, destination_id:int, telegram_topic_id:int, support_conversation_uuid:string}> $candidates
	 * @param bool $dry_run When true, Universal Telegram commits no write.
	 *
	 * @return array<int, array{source_conversation_id:int, outcome:string, binding_uuid:?string}>
	 *
	 * @throws LegacyBindingImportUnavailableException If the call cannot be completed for any reason.
	 */
	public function import_batch( array $candidates, bool $dry_run ): array {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			throw new LegacyBindingImportUnavailableException(
				'Legacy binding preparation may only run from a WP-CLI process (Support Chat ADR-0009 §7).'
			);
		}

		if ( ! class_exists( self::UT_PLUGIN_CLASS ) ) {
			throw new LegacyBindingImportUnavailableException( 'Universal Telegram is not active.' );
		}

		$ut_plugin = \UniversalTelegram\Core\Plugin::instance();

		if ( ! method_exists( $ut_plugin, 'legacy_binding_import_service' ) ) {
			throw new LegacyBindingImportUnavailableException( 'Universal Telegram is running an incompatible version (no legacy_binding_import_service()).' );
		}

		$service = $ut_plugin->legacy_binding_import_service();

		if ( null === $service ) {
			throw new LegacyBindingImportUnavailableException( 'Universal Telegram legacy binding preparation boundary is unavailable.' );
		}

		try {
			$result = $service->import_batch( $candidates, $dry_run );
		} catch ( \Throwable $exception ) {
			throw new LegacyBindingImportUnavailableException(
				'Universal Telegram refused the binding import call: ' . $exception->getMessage(),
				0,
				$exception
			);
		}

		if ( ! is_array( $result ) ) {
			throw new LegacyBindingImportUnavailableException( 'Universal Telegram returned a malformed binding import result.' );
		}

		return $result;
	}
}
