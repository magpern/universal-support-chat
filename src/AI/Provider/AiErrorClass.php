<?php
/**
 * Fixed provider-error vocabulary (ADR-0018 §7).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\AI\Provider;

/**
 * The closed set of provider-error classes an {@see AiResult} may carry.
 * Only the retryable ones drive the bounded worker retry/backoff; the rest
 * hand off terminally (ADR-0018 §4.5).
 */
final class AiErrorClass {

	public const TIMEOUT      = 'timeout';
	public const TRANSPORT    = 'transport';
	public const RATE_LIMITED = 'rate_limited';
	public const SERVER       = 'server';
	public const CLIENT       = 'client';
	public const AUTH         = 'auth';
	public const MALFORMED    = 'malformed';

	/**
	 * Whether a class is a transient failure worth retrying.
	 *
	 * @param string $error_class One of the class constants.
	 */
	public static function is_retryable( string $error_class ): bool {
		return in_array(
			$error_class,
			array( self::TIMEOUT, self::TRANSPORT, self::RATE_LIMITED, self::SERVER ),
			true
		);
	}

	/**
	 * All known classes.
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array(
			self::TIMEOUT,
			self::TRANSPORT,
			self::RATE_LIMITED,
			self::SERVER,
			self::CLIENT,
			self::AUTH,
			self::MALFORMED,
		);
	}
}
