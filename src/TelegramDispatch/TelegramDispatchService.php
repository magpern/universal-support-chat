<?php
/**
 * Support Chat -> Telegram automatic message dispatch worker.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\TelegramDispatch;

use UniversalSupportChat\Audit\AuditLogger;
use UniversalSupportChat\ChannelContract\Outbound\AdapterContractClient;
use UniversalSupportChat\Conversations\ConversationMessage;
use UniversalSupportChat\Conversations\MessageRepository;
use UniversalSupportChat\Core\Configuration\Settings;
use UniversalSupportChat\Privacy\Classification;

/**
 * Drains the dispatch outbox: for each due, Support-Chat-originated message
 * row it resolves (or creates) the Universal Telegram channel case via the
 * real Contract v1 `ensure_channel_case`, then delivers the body via the
 * real Contract v1 `deliver_message`, using the SC-owned, stable
 * `deliver_message` idempotency key (message UUID) so a retry can never
 * duplicate a Telegram delivery.
 *
 * Runs only from WP-Cron / WP-CLI (`DispatchWorker`), never inside a
 * visitor or Hub request. When the feature is disabled, or Universal
 * Telegram is unpaired / disabled / unavailable, rows simply stay
 * retryable — a committed Support Chat message is never dropped because the
 * transport is down. Message plaintext lives only in memory for the
 * duration of a `deliver_message` call and is never audited.
 */
final class TelegramDispatchService {

	/**
	 * Fixed Contract v1 peer slug for the Universal Telegram adapter.
	 */
	public const PEER_ID = 'universal-telegram';

	private const MAX_BODY_CHARS = 4096;

	/**
	 * Backoff schedule (seconds) indexed by attempt count, capped at the
	 * last entry. Transient/unavailable failures never exhaust this — the
	 * row keeps retrying at the capped interval.
	 *
	 * @var array<int, int>
	 */
	private const BACKOFF = array( 60, 120, 300, 900, 1800, 3600 );

	/**
	 * Constructor.
	 *
	 * @param Settings                $settings Plugin settings.
	 * @param DispatchOutboxRepository $outbox   Dispatch outbox.
	 * @param MessageRepository       $messages Conversation messages.
	 * @param AdapterContractClient   $client   Outbound Contract v1 client.
	 * @param AuditLogger             $audit    Audit logger.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly DispatchOutboxRepository $outbox,
		private readonly MessageRepository $messages,
		private readonly AdapterContractClient $client,
		private readonly AuditLogger $audit
	) {}

	/**
	 * Whether automatic Telegram dispatch is enabled.
	 */
	public function is_enabled(): bool {
		$values = $this->settings->get();

		return ! empty( $values['telegram_dispatch_enabled'] );
	}

	/**
	 * Reclaims rows stranded in `delivering` by a crashed worker, back to a
	 * retryable state. Runs regardless of whether the feature is currently
	 * enabled, so toggling dispatch off does not permanently strand a row.
	 *
	 * @return int Number of rows reclaimed.
	 */
	public function reclaim_stale(): int {
		return $this->outbox->reclaim_expired_leases();
	}

	/**
	 * Processes up to $limit due outbox rows.
	 *
	 * @param int $limit Maximum rows to process this pass.
	 *
	 * @return array{processed: int, delivered: int, failed: int, abandoned: int}
	 */
	public function dispatch_due( int $limit = 20 ): array {
		$processed = 0;
		$delivered = 0;
		$failed    = 0;
		$abandoned = 0;

		if ( ! $this->is_enabled() ) {
			$this->reclaim_stale();

			return compact( 'processed', 'delivered', 'failed', 'abandoned' );
		}

		foreach ( $this->outbox->claim_due( $limit ) as $record ) {
			++$processed;

			$outcome = $this->deliver_one( $record );

			if ( DispatchRecord::STATE_DELIVERED === $outcome ) {
				++$delivered;
			} elseif ( DispatchRecord::STATE_ABANDONED === $outcome ) {
				++$abandoned;
			} else {
				++$failed;
			}
		}

		if ( $processed > 0 ) {
			$this->audit->record(
				'telegram_dispatch.swept',
				'system',
				null,
				array(
					'processed' => (string) $processed,
					'delivered' => (string) $delivered,
					'failed'    => (string) $failed,
					'abandoned' => (string) $abandoned,
				),
				array(
					'processed' => Classification::PUBLIC,
					'delivered' => Classification::PUBLIC,
					'failed'    => Classification::PUBLIC,
					'abandoned' => Classification::PUBLIC,
				),
				Classification::INTERNAL
			);
		}

		return compact( 'processed', 'delivered', 'failed', 'abandoned' );
	}

	/**
	 * Delivers one claimed row. Returns the terminal-or-retry state it
	 * left the row in.
	 *
	 * @param DispatchRecord $record Claimed outbox row (state `delivering`).
	 */
	private function deliver_one( DispatchRecord $record ): string {
		$message = $this->messages->find_by_uuid( $record->message_uuid() );

		if ( null === $message ) {
			// The message row is gone (e.g. purged). Nothing to deliver,
			// ever.
			$this->outbox->mark_abandoned( $record->id(), 'message_missing' );

			return DispatchRecord::STATE_ABANDONED;
		}

		$body = $message->plaintext_body();
		if ( null === $body || '' === trim( $body ) ) {
			// Body retention-nulled or unreadable: undeliverable, and a
			// retry cannot recover it.
			$this->outbox->mark_abandoned( $record->id(), 'body_unavailable' );

			return DispatchRecord::STATE_ABANDONED;
		}

		$body = $this->clamp( $body );

		$ensure = $this->client->ensure_channel_case( self::PEER_ID, $record->conversation_uuid(), 'support_chat_dispatch' );

		if ( ! $ensure['ok'] || '' === $ensure['channel_case_ref'] ) {
			$this->outbox->mark_failed(
				$record->id(),
				$this->reason( 'channel_unavailable', $ensure['reason'] ),
				$this->backoff( $record->attempts() )
			);

			return DispatchRecord::STATE_FAILED;
		}

		$channel_case_ref = $ensure['channel_case_ref'];
		$this->outbox->record_channel_case_ref( $record->id(), $channel_case_ref );

		// Product policy: when this ensure call actually created the
		// Telegram case (a brand-new forum topic), tell operators a
		// conversation is now waiting there. Best-effort — a notify
		// failure never blocks the message delivery below.
		if ( 'created' === $ensure['case_status'] ) {
			$this->client->notify_operators(
				self::PEER_ID,
				$channel_case_ref,
				'new_conversation',
				'A Support Chat conversation is now linked to this topic.'
			);
		}

		$deliver = $this->client->deliver_message(
			self::PEER_ID,
			$channel_case_ref,
			$message->uuid(),
			$body,
			$this->attribution( $record->direction() )
		);

		if ( $deliver['ok'] ) {
			$this->outbox->mark_delivered( $record->id() );

			return DispatchRecord::STATE_DELIVERED;
		}

		if ( AdapterContractClient::REASON_INVALID_INPUT === $deliver['reason'] ) {
			$this->outbox->mark_abandoned( $record->id(), 'invalid_input' );

			return DispatchRecord::STATE_ABANDONED;
		}

		$this->outbox->mark_failed(
			$record->id(),
			$this->reason( 'delivery_failed', $deliver['reason'] ),
			$this->backoff( $record->attempts() )
		);

		return DispatchRecord::STATE_FAILED;
	}

	/**
	 * Channel-facing attribution label for a direction.
	 *
	 * @param string $direction Message direction.
	 */
	private function attribution( string $direction ): string {
		return ConversationMessage::DIRECTION_VISITOR === $direction ? 'Visitor' : 'Support';
	}

	/**
	 * Bounds a body to the Contract text limit.
	 *
	 * @param string $body Plaintext body.
	 */
	private function clamp( string $body ): string {
		if ( strlen( $body ) <= self::MAX_BODY_CHARS ) {
			return $body;
		}

		return (string) mb_substr( $body, 0, self::MAX_BODY_CHARS, 'UTF-8' );
	}

	/**
	 * Combines a stable local reason with the client's own reason code.
	 *
	 * @param string      $local  Local stable reason.
	 * @param string|null $client Client-provided reason code, if any.
	 */
	private function reason( string $local, ?string $client ): string {
		return null !== $client && '' !== $client ? $local . ':' . $client : $local;
	}

	/**
	 * Backoff seconds for the next attempt after $attempts failures.
	 *
	 * @param int $attempts Attempts already made (>= 1 for a claimed row).
	 */
	private function backoff( int $attempts ): int {
		$index = max( 0, $attempts - 1 );

		return self::BACKOFF[ min( $index, count( self::BACKOFF ) - 1 ) ];
	}
}
