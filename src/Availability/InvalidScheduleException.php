<?php
/**
 * Raised when an availability schedule or exception payload is malformed.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Availability;

/**
 * Thrown by the value objects' `from_array()` factories on any malformed
 * element. The Settings sanitiser catches it to reject a whole Availability
 * section update while preserving the prior valid value (ADR-0017 §Decision;
 * plan v2 §6). The runtime resolver never sees it — {@see AvailabilityService}
 * catches it and falls back to the fail-safe `unavailable` state.
 */
final class InvalidScheduleException extends \InvalidArgumentException {}
