<?php
/**
 * The operator's manual availability override.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Availability;

/**
 * `Force online` / `Force offline`, with an expiry that is either a unix
 * timestamp or `null` ("until cleared"). A `null` expiry is a valid,
 * first-class, persistent state (ADR-0017 §6). Precedence tier 1 in
 * ADR-0017 §3 — an active override supersedes everything.
 */
final class AvailabilityOverride {

	public const MODE_FORCE_ONLINE  = 'force_online';
	public const MODE_FORCE_OFFLINE = 'force_offline';

	/**
	 * Constructor.
	 *
	 * @param string   $mode       One of the `MODE_*` constants.
	 * @param int|null  $expires_at Unix timestamp, or `null` for "until cleared".
	 * @param int      $set_by     WordPress user id of the operator who set it.
	 * @param int      $set_at     Unix timestamp when set.
	 *
	 * @throws \InvalidArgumentException When the mode is not recognised.
	 */
	public function __construct(
		private readonly string $mode,
		private readonly ?int $expires_at,
		private readonly int $set_by,
		private readonly int $set_at
	) {
		if ( self::MODE_FORCE_ONLINE !== $mode && self::MODE_FORCE_OFFLINE !== $mode ) {
			throw new \InvalidArgumentException( 'Unknown override mode.' );
		}
	}

	/**
	 * Rebuilds from the stored option array, or returns `null` when the
	 * value is absent or corrupt (fail to `Automatic`, ADR-0017 §6).
	 *
	 * @param mixed $raw Stored option value.
	 */
	public static function from_option( $raw ): ?self {
		if ( ! is_array( $raw ) || ! isset( $raw['mode'] ) ) {
			return null;
		}

		$expires_at = $raw['expires_at'] ?? null;
		if ( null !== $expires_at && ! is_int( $expires_at ) ) {
			$expires_at = is_numeric( $expires_at ) ? (int) $expires_at : null;
		}

		try {
			return new self(
				(string) $raw['mode'],
				$expires_at,
				isset( $raw['set_by'] ) ? (int) $raw['set_by'] : 0,
				isset( $raw['set_at'] ) ? (int) $raw['set_at'] : 0
			);
		} catch ( \InvalidArgumentException $exception ) {
			unset( $exception );
			return null;
		}
	}

	/**
	 * Whether the override still applies at the given moment. A `null`
	 * expiry never expires; a non-null expiry applies only while strictly
	 * in the future.
	 *
	 * @param int $now Unix timestamp.
	 */
	public function is_active( int $now ): bool {
		return null === $this->expires_at || $this->expires_at > $now;
	}

	/**
	 * The state this override forces.
	 */
	public function forced_state(): AvailabilityState {
		return self::MODE_FORCE_ONLINE === $this->mode
			? AvailabilityState::AVAILABLE
			: AvailabilityState::UNAVAILABLE;
	}

	/**
	 * The operator control-mode label.
	 */
	public function mode(): string {
		return $this->mode;
	}

	/**
	 * The expiry timestamp, or `null` for "until cleared".
	 */
	public function expires_at(): ?int {
		return $this->expires_at;
	}

	/**
	 * WordPress user id that set the override.
	 */
	public function set_by(): int {
		return $this->set_by;
	}

	/**
	 * Unix timestamp when the override was set.
	 */
	public function set_at(): int {
		return $this->set_at;
	}

	/**
	 * Serialises to the stored option array shape.
	 *
	 * @return array{mode: string, expires_at: int|null, set_by: int, set_at: int}
	 */
	public function to_option(): array {
		return array(
			'mode'       => $this->mode,
			'expires_at' => $this->expires_at,
			'set_by'     => $this->set_by,
			'set_at'     => $this->set_at,
		);
	}
}
