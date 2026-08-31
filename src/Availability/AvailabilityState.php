<?php
/**
 * Resolved visitor-facing availability state.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Availability;

/**
 * The only two states a visitor ever sees (ADR-0017 §3). The operator
 * control mode (`Automatic` / `Force online` / `Force offline`) is a
 * separate concept — see {@see AvailabilityOverride}.
 */
enum AvailabilityState: string {
	case AVAILABLE   = 'available';
	case UNAVAILABLE = 'unavailable';

	/**
	 * Whether the team is presented as available.
	 */
	public function is_available(): bool {
		return self::AVAILABLE === $this;
	}
}
