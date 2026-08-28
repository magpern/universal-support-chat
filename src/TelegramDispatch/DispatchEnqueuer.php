<?php
/**
 * Post-commit hand-off from Support Chat write paths into the dispatch outbox.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\TelegramDispatch;

use UniversalSupportChat\Conversations\ConversationMessage;
use UniversalSupportChat\Core\Configuration\Settings;

/**
 * The single seam the visitor REST path and the Hub reply path call after
 * a conversation message has been committed. Deliberately tiny and
 * non-throwing: enqueue failures must never turn into a visitor- or
 * operator-visible error, because Support Chat remains the system of record
 * whether or not the Telegram mirror succeeds.
 *
 * `mark_telegram_origin()` is the loop-prevention seam: a message ingested
 * *from* Telegram (`ContractOperationDispatcher::ingest_operator_reply`)
 * gets a permanent suppression row so it can never be mirrored back out.
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
	 * Enqueues a Support-Chat-originated message for mirroring to Telegram,
	 * and kicks the worker so latency stays low. No-op when the feature is
	 * disabled, when the message already has an outbox row, or for a
	 * message direction that is never mirrored.
	 *
	 * @param ConversationMessage $message           Committed message.
	 * @param string              $conversation_uuid Parent conversation UUID.
	 */
	public function enqueue_message( ConversationMessage $message, string $conversation_uuid ): void {
		$values = $this->settings->get();
		if ( empty( $values['telegram_dispatch_enabled'] ) ) {
			return;
		}

		if ( ! in_array(
			$message->direction(),
			array( ConversationMessage::DIRECTION_VISITOR, ConversationMessage::DIRECTION_OPERATOR ),
			true
		) ) {
			return;
		}

		$inserted = $this->outbox->enqueue(
			$message->uuid(),
			$message->conversation_id(),
			$conversation_uuid,
			$message->direction()
		);

		if ( $inserted ) {
			$this->kick();
		}
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
	 * Schedules an immediate one-off worker run. WP-Cron collapses
	 * identical hook+args events within a 10-minute window, so repeated
	 * kicks under load do not pile up.
	 */
	private function kick(): void {
		wp_schedule_single_event( time(), DispatchWorker::HOOK );
	}
}
