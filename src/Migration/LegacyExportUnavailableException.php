<?php
/**
 * Thrown when the Universal Telegram legacy export boundary cannot be reached.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Migration;

/**
 * Covers every reason `InProcessLegacyExportClient` must refuse: Universal
 * Telegram inactive, its `LegacyExportServiceV1` accessor unavailable, the
 * export boundary itself rejected the call (wrong invocation context, or a
 * `schema_unavailable` envelope). This engine treats all of these as a hard
 * stop for the current run, never as "zero conversations remain."
 */
final class LegacyExportUnavailableException extends \RuntimeException {

}
