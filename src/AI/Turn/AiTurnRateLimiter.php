<?php
/**
 * AI turn abuse / spend limits (ADR-0018 §8, §11).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\AI\Turn;

/**
 * Three bounded limits, all of which degrade to an honest handoff (never a
 * hard error) when breached (ADR-0018 §4.7):
 *
 * - a per-user rolling-hour counter (transient) with a short cooldown;
 * - a per-conversation lifetime turn cap;
 * - a global daily request cap.
 */
final class AiTurnRateLimiter {

	private const USER_WINDOW_SECONDS = 3600;
	private const USER_HOURLY_MAX     = 30;

	/**
	 * Turn repository.
	 *
	 * @var AiTurnRepository
	 */
	private AiTurnRepository $turns;

	/**
	 * Constructor.
	 *
	 * @param AiTurnRepository $turns Turn repository.
	 */
	public function __construct( AiTurnRepository $turns ) {
		$this->turns = $turns;
	}

	/**
	 * Records one attempt against the per-user hourly counter. Called by the
	 * responder when a turn is enqueued.
	 *
	 * @param int $user_id Visitor user id.
	 */
	public function note_user_request( int $user_id ): void {
		$key   = self::user_key( $user_id );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, self::USER_WINDOW_SECONDS );
	}

	/**
	 * Returns a {@see HandoffReason} when a limit is breached, or null when
	 * the turn may proceed. Evaluated by the worker just before the provider
	 * call.
	 *
	 * @param int                  $user_id         Visitor user id.
	 * @param int                  $conversation_id Conversation id.
	 * @param array<string, mixed> $settings        Resolved plugin settings.
	 */
	public function breach( int $user_id, int $conversation_id, array $settings ): ?string {
		$per_conversation = (int) ( $settings['ai_per_conversation_turn_cap'] ?? 10 );
		$daily            = (int) ( $settings['ai_daily_request_cap'] ?? 500 );

		if ( $this->turns->count_for_conversation( $conversation_id ) > $per_conversation ) {
			return HandoffReason::RATE_LIMITED;
		}

		if ( $this->turns->count_created_since( self::start_of_utc_day() ) > $daily ) {
			return HandoffReason::RATE_LIMITED;
		}

		if ( (int) get_transient( self::user_key( $user_id ) ) > self::USER_HOURLY_MAX ) {
			return HandoffReason::RATE_LIMITED;
		}

		return null;
	}

	/**
	 * Per-user transient key.
	 *
	 * @param int $user_id Visitor user id.
	 */
	private static function user_key( int $user_id ): string {
		return 'usc_ai_rl_' . $user_id;
	}

	/**
	 * `Y-m-d H:i:s` for 00:00:00 UTC today.
	 */
	private static function start_of_utc_day(): string {
		return gmdate( 'Y-m-d' ) . ' 00:00:00';
	}
}
