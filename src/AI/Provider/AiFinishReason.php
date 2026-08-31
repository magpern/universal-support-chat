<?php
/**
 * Fixed provider finish-reason vocabulary (ADR-0018 §7).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\AI\Provider;

/**
 * The closed set of finish reasons an {@see AiResult} may report, normalised
 * from whatever the concrete provider returned.
 */
final class AiFinishReason {

	public const STOP           = 'stop';
	public const LENGTH         = 'length';
	public const CONTENT_FILTER = 'content_filter';
	public const NEEDS_HUMAN    = 'needs_human';
	public const ERROR          = 'error';
	public const UNKNOWN        = 'unknown';

	/**
	 * Normalises a raw provider `finish_reason` string.
	 *
	 * @param string|null $raw Raw provider value.
	 */
	public static function from_raw( ?string $raw ): string {
		switch ( (string) $raw ) {
			case 'stop':
				return self::STOP;
			case 'length':
				return self::LENGTH;
			case 'content_filter':
				return self::CONTENT_FILTER;
			default:
				return self::UNKNOWN;
		}
	}
}
