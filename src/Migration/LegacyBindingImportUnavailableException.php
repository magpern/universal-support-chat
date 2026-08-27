<?php
/**
 * Thrown when the Universal Telegram legacy binding preparation boundary cannot be reached.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Migration;

/**
 * Covers every reason `InProcessLegacyBindingImportClient` must refuse:
 * Universal Telegram inactive, its `LegacyBindingImportServiceV1` accessor
 * unavailable, or the boundary itself rejected the whole call (wrong
 * invocation context). This is a whole-run refusal, distinct from a
 * per-candidate retryable outcome (`LegacyBindingOutcome::retryable()`),
 * which the caller must still treat as retryable, not as this exception.
 */
final class LegacyBindingImportUnavailableException extends \RuntimeException {

}
