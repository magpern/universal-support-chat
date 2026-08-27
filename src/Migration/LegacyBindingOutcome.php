<?php
/**
 * Typed outcome vocabulary for SC-M03 work package 5 binding preparation.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Migration;

/**
 * The full outcome vocabulary ADR-0009 §4 fixes — the structural,
 * SC-owned exclusions (this repository's own map-row checks), plus the
 * outcomes Universal Telegram's own binding-import service determines and
 * returns across the write boundary (mirrored here as plain string
 * constants only — this class never references Universal Telegram's
 * namespace; only `InProcessLegacyBindingImportClient` does). A terminal
 * outcome permanently writes `binding_status`; a retryable outcome never
 * does, so the next ordinary `run` automatically reselects the row.
 */
final class LegacyBindingOutcome {

	// Structural, SC-owned terminal exclusions (§2 items 2-6).
	public const SKIP_NO_TOPIC                   = 'binding_skip_no_topic';
	public const SKIP_MISSING_BOT_OR_DESTINATION = 'binding_skip_missing_bot_or_destination';
	public const SKIP_TOPIC_NOT_CREATED          = 'binding_skip_topic_not_created';
	public const SKIP_TOPIC_LIFECYCLE_TERMINAL   = 'binding_skip_topic_lifecycle_terminal';
	public const SKIP_NO_TARGET_CONVERSATION     = 'binding_skip_no_target_conversation';

	// Outcomes Universal Telegram's own service determines (§2 item 7-9, 11).
	public const SKIP_TOPIC_STATE_CHANGED              = 'binding_skip_topic_state_changed_since_migration';
	public const RETRY_UT_UNAVAILABLE_OR_INDETERMINATE = 'binding_retry_ut_unavailable_or_indeterminate';
	public const SKIP_ALREADY_BOUND                    = 'binding_skip_already_bound';
	public const CONFLICT_EXISTING_MISMATCHED          = 'binding_conflict_existing_mismatched';
	public const CONFLICT_EXISTING_ACTIVE              = 'binding_conflict_existing_active';
	public const CONFLICT_EXISTING_STATUS_UNRESOLVED   = 'binding_conflict_existing_status_unresolved';
	public const RETRY_NOT_QUIESCENT                   = 'binding_retry_not_quiescent';
	public const RETRY_TRANSIENT_ERROR                 = 'binding_retry_transient_error';

	public const CREATED = 'created';

	/**
	 * Every terminal outcome — writes a non-NULL `binding_status`.
	 *
	 * @return array<int, string>
	 */
	public static function terminal(): array {
		return array(
			self::SKIP_NO_TOPIC,
			self::SKIP_MISSING_BOT_OR_DESTINATION,
			self::SKIP_TOPIC_NOT_CREATED,
			self::SKIP_TOPIC_LIFECYCLE_TERMINAL,
			self::SKIP_NO_TARGET_CONVERSATION,
			self::SKIP_TOPIC_STATE_CHANGED,
			self::SKIP_ALREADY_BOUND,
			self::CONFLICT_EXISTING_MISMATCHED,
			self::CONFLICT_EXISTING_ACTIVE,
			self::CONFLICT_EXISTING_STATUS_UNRESOLVED,
			self::CREATED,
		);
	}

	/**
	 * Every retryable outcome — never writes `binding_status`; only the
	 * non-gating `binding_last_attempt_*` audit pair.
	 *
	 * @return array<int, string>
	 */
	public static function retryable(): array {
		return array(
			self::RETRY_UT_UNAVAILABLE_OR_INDETERMINATE,
			self::RETRY_NOT_QUIESCENT,
			self::RETRY_TRANSIENT_ERROR,
		);
	}

	/**
	 * The `binding_status` column value a terminal outcome maps to
	 * (`created`|`skipped`|`conflict`) — never called for a retryable
	 * outcome.
	 *
	 * @param string $outcome A terminal outcome constant.
	 */
	public static function binding_status_for( string $outcome ): string {
		if ( self::CREATED === $outcome ) {
			return 'created';
		}

		if ( in_array( $outcome, array( self::CONFLICT_EXISTING_MISMATCHED, self::CONFLICT_EXISTING_ACTIVE, self::CONFLICT_EXISTING_STATUS_UNRESOLVED ), true ) ) {
			return 'conflict';
		}

		return 'skipped';
	}

	/**
	 * Whether an outcome is terminal.
	 *
	 * @param string $outcome An outcome constant.
	 */
	public static function is_terminal( string $outcome ): bool {
		return in_array( $outcome, self::terminal(), true );
	}
}
