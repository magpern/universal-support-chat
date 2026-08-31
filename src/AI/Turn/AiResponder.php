<?php
/**
 * Visitor-request-side AI turn enqueue (ADR-0018 §2).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\AI\Turn;

use UniversalSupportChat\AI\Provider\ProviderKeyManager;
use UniversalSupportChat\Conversations\Conversation;
use UniversalSupportChat\Conversations\ConversationMessage;
use UniversalSupportChat\Core\Configuration\Settings;
use UniversalSupportChat\TelegramDispatch\DispatchEnqueuer;

/**
 * The one seam the visitor REST path calls when AI is enabled. It writes the
 * visitor message and the `ai_turns` row **in one transaction** (reusing the
 * {@see DispatchEnqueuer} atomic seam so the ADR-0012 outbox row still
 * commits together when Telegram dispatch is on), then fires a non-blocking
 * cron kick. **No provider call happens here** — that is {@see AiTurnWorker}.
 */
final class AiResponder {

	/**
	 * Constructor.
	 *
	 * @param Settings              $settings Plugin settings.
	 * @param ProviderKeyManager    $keys     Provider key manager.
	 * @param AiTurnRepository       $turns    Turn repository.
	 * @param AiTurnRateLimiter      $limiter  Rate limiter (per-user counter).
	 * @param DispatchEnqueuer|null  $dispatch Optional Telegram dispatch enqueuer.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly ProviderKeyManager $keys,
		private readonly AiTurnRepository $turns,
		private readonly AiTurnRateLimiter $limiter,
		private readonly ?DispatchEnqueuer $dispatch = null
	) {}

	/**
	 * Whether the AI should answer the next message in this conversation:
	 * enabled, a provider key configured, not claimed by an operator, and not
	 * already handed off.
	 *
	 * @param Conversation $conversation Owned, non-terminal conversation.
	 */
	public function is_eligible( Conversation $conversation ): bool {
		$settings = $this->settings->get();

		return ! empty( $settings['ai_enabled'] )
			&& $this->keys->is_configured()
			&& null === $conversation->assigned_operator_id()
			&& ! $this->turns->has_handoff( $conversation->id() );
	}

	/**
	 * Persists the visitor message and its `ai_turns` row atomically, then
	 * kicks the async worker. Returns the message, or null if the write
	 * failed (the caller surfaces its normal retryable error).
	 *
	 * @param string                                 $conversation_uuid Parent conversation UUID.
	 * @param int                                    $conversation_id   Parent conversation id.
	 * @param int                                    $owner_user_id     Visitor user id.
	 * @param callable(): (ConversationMessage|null) $create            Creates the visitor message row.
	 */
	public function persist_with_turn( string $conversation_uuid, int $conversation_id, int $owner_user_id, callable $create ): ?ConversationMessage {
		$turn_uuid = wp_generate_uuid4();

		$enqueue_turn = function ( ConversationMessage $message ) use ( $turn_uuid, $conversation_id ): bool {
			// An idempotent retry of the visitor request returns the same
			// message row; it must not spawn a second AI turn.
			if ( $this->turns->exists_for_message( $message->id() ) ) {
				return true;
			}

			return $this->turns->insert_queued(
				$turn_uuid,
				$conversation_id,
				$message->id(),
				gmdate( 'Y-m-d H:i:s' )
			) > 0;
		};

		if ( null !== $this->dispatch ) {
			$message = $this->dispatch->persist_and_enqueue( $conversation_uuid, $create, $enqueue_turn );
		} else {
			$message = $this->persist_in_own_transaction( $create, $enqueue_turn );
		}

		if ( ! $message instanceof ConversationMessage ) {
			return null;
		}

		$this->limiter->note_user_request( $owner_user_id );
		AiTurnWorker::request_immediate_run();

		return $message;
	}

	/**
	 * The no-dispatch fallback: message + turn row in one transaction.
	 *
	 * @param callable(): (ConversationMessage|null) $create       Creates the message.
	 * @param callable(ConversationMessage): bool    $enqueue_turn Inserts the turn row.
	 */
	private function persist_in_own_transaction( callable $create, callable $enqueue_turn ): ?ConversationMessage {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- transaction control statement.
		$wpdb->query( 'START TRANSACTION' );

		try {
			$message = $create();

			if ( ! $message instanceof ConversationMessage || false === $enqueue_turn( $message ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- transaction control statement.
				$wpdb->query( 'ROLLBACK' );

				return null;
			}
		} catch ( \Throwable $exception ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- transaction control statement.
			$wpdb->query( 'ROLLBACK' );

			throw $exception;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- transaction control statement.
		$wpdb->query( 'COMMIT' );

		return $message;
	}
}
