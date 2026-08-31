<?php
/**
 * Bounded human-handoff reason vocabulary (ADR-0018 §4).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\AI\Turn;

/**
 * The closed set of reasons an AI turn hands off to a human. Recorded on the
 * `ai_turns` row and surfaced (as an enum label) in the Hub AI panel —
 * never a free-text explanation.
 */
final class HandoffReason {

	public const VISITOR_REQUESTED   = 'visitor_requested';
	public const REFUSED             = 'refused';
	public const UNCERTAIN           = 'uncertain';
	public const SAFETY              = 'safety';
	public const PROVIDER_FAILED     = 'provider_failed';
	public const UNSUPPORTED_REQUEST = 'unsupported_request';
	public const RATE_LIMITED        = 'rate_limited';

	/**
	 * Every known reason.
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array(
			self::VISITOR_REQUESTED,
			self::REFUSED,
			self::UNCERTAIN,
			self::SAFETY,
			self::PROVIDER_FAILED,
			self::UNSUPPORTED_REQUEST,
			self::RATE_LIMITED,
		);
	}

	/**
	 * A plain, honest visitor-facing sentence for a handoff reason. Carries
	 * no machine detail. When the team is offline the caller substitutes the
	 * availability-service offline copy instead (ADR-0017 §5).
	 *
	 * @param string $reason One of the class constants.
	 */
	public static function visitor_message( string $reason ): string {
		switch ( $reason ) {
			case self::VISITOR_REQUESTED:
				return "I'm connecting you with a person from the support team. They'll pick up here.";
			case self::UNSUPPORTED_REQUEST:
				return "That needs a person from the support team — I've passed this conversation to them and they'll reply here.";
			case self::SAFETY:
				return "I've asked a person from the support team to take over this conversation. They'll reply here.";
			case self::RATE_LIMITED:
				return "I've handed this conversation to the support team, who'll reply here.";
			default:
				return "I'm not able to answer that confidently, so I've passed this conversation to the support team. They'll reply here.";
		}
	}
}
