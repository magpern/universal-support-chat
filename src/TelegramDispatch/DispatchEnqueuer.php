<?php
/**
 * Transactional hand-off from Support Chat write paths into the dispatch outbox.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\TelegramDispatch;

use UniversalSupportChat\Conversations\ConversationMessage;
use UniversalSupportChat\Core\Configuration\Settings;

/**
 * The single seam the visitor REST path and the Hub reply path call to
 * persist a conversation message.
 *
 * When automatic dispatch is enabled, the outbox row is written **in the
 * same database transaction** as the message row: either both commit or
 * neither does. The worker then acts only on committed rows. This is what
 * makes the outbox durable — there is no window in which a message exists
 * in Support Chat with no outbox row to drive its mirror (or its retry).
 *
 * When dispatch is disabled (the default) no transaction is opened and the
 * message write is byte-for-byte what it was before this feature.
 *
 * `mark_telegram_origin()` is the loop-prevention seam: a message ingested
 * *from* Telegram (`ContractOperationDispatcher::ingest_operator_reply`)
 * gets a permanent suppression row so it can never be mirrored back out. It
 * is defence-in-depth — no code path ever enqueues an ingested message —
 * so it stays best-effort and idempotent.
 */
final class DispatchEnqueuer {

	/**
	 * Constructor.
	 *
	 * @param Settings                 $settings Plugin settings.
	 * @param DispatchOutboxRepository $outbox   Dispatch outbox.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly DispatchOutboxRepository $outbox
	) {}

	/**
	 * Whether automatic Telegram dispatch is enabled.
	 */
	public function is_enabled(): bool {
		$values = $this->settings->get();

		return ! empty( $values['telegram_dispatch_enabled'] );
	}

	/**
	 * Persists a conversation message and, when dispatch is enabled,
	 * atomically records its outbox row in the same transaction.
	 *
	 * `$persist` must create exactly one message row and return the
	 * resulting `ConversationMessage` (or `null` on failure). It must not
	 * itself open, commit, or roll back a transaction.
	 *
	 * Returns the persisted message, or `null` if either the message write
	 * or — when dispatch is enabled — the outbox write failed (in which
	 * case nothing is committed and the caller should surface an ordinary
	 * retryable error, exactly as it already does for a failed message
	 * write).
	 *
	 * When `$within_transaction` is given it runs inside the same
	 * transaction, immediately after the message (and, when dispatch is
	 * enabled, its outbox row) is written. Returning `false` from it rolls
	 * the whole unit back and yields `null` — this is how the SC-M06 offline
	 * path (ADR-0017 §7) keeps the visitor message and the
	 * `waiting_for_operator` transition atomic. Passing it forces a
	 * transaction even when dispatch is disabled.
	 *
	 * @param string                                    $conversation_uuid  Parent conversation UUID.
	 * @param callable(): (ConversationMessage|null)     $persist            Creates and returns the message row.
	 * @param callable(ConversationMessage): bool|null   $within_transaction Optional post-persist step; `false` rolls back.
	 */
	public function persist_and_enqueue( string $conversation_uuid, callable $persist, ?callable $within_transaction = null ): ?ConversationMessage {
		if ( ! $this->is_enabled() && null === $within_transaction ) {
			$message = $persist();

			return $message instanceof ConversationMessage ? $message : null;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- transaction control statement.
		$wpdb->query( 'START TRANSACTION' );

		try {
			$message = $persist();

			if ( ! $message instanceof ConversationMessage ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- transaction control statement.
				$wpdb->query( 'ROLLBACK' );

				return null;
			}

			if ( $this->is_enabled() && $this->is_mirrored_direction( $message->direction() ) ) {
				$enqueued = $this->outbox->enqueue(
					$message->uuid(),
					$message->conversation_id(),
					$conversation_uuid,
					$message->direction()
				);

				// `enqueue()` returns false both for a genuine write
				// failure and for an already-present row (an idempotent
				// retry of an earlier, committed message). Only the former
				// must roll the message back.
				if ( ! $enqueued && ! $this->outbox->exists( $message->uuid() ) ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- transaction control statement.
					$wpdb->query( 'ROLLBACK' );

					return null;
				}
			}

			if ( null !== $within_transaction && false === $within_transaction( $message ) ) {
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

		if ( $this->is_enabled() && $this->is_mirrored_direction( $message->direction() ) ) {
			// ADR-0014 Amendment 1: the ONLY expedite step in the request —
			// a non-blocking, non-throwing async kick. No Telegram I/O, no
			// Contract call, no dependence on Universal Telegram here.
			DispatchWorker::request_immediate_run();
		}

		return $message;
	}

	/**
	 * Records a Telegram-originated message with a permanent suppression
	 * marker (loop prevention). Always applied, regardless of the feature
	 * flag, so the message can never be mirrored back out even if dispatch
	 * is enabled later.
	 *
	 * @param ConversationMessage $message           Committed message.
	 * @param string              $conversation_uuid Parent conversation UUID.
	 */
	public function mark_telegram_origin( ConversationMessage $message, string $conversation_uuid ): void {
		$this->outbox->mark_telegram_origin(
			$message->uuid(),
			$message->conversation_id(),
			$conversation_uuid,
			$message->direction()
		);
	}

	/**
	 * Whether a message of this direction is ever mirrored to Telegram.
	 *
	 * @param string $direction Message direction.
	 */
	private function is_mirrored_direction( string $direction ): bool {
		return in_array(
			$direction,
			array( ConversationMessage::DIRECTION_VISITOR, ConversationMessage::DIRECTION_OPERATOR ),
			true
		);
	}
}
