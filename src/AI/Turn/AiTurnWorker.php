<?php
/**
 * Asynchronous AI turn worker and escalation state machine (ADR-0018 §2, §4).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\AI\Turn;

use UniversalSupportChat\AI\Policy\AiSystemPolicy;
use UniversalSupportChat\AI\Policy\PromptAssembler;
use UniversalSupportChat\AI\Provider\AiProvider;
use UniversalSupportChat\AI\Provider\AiResult;
use UniversalSupportChat\AI\Knowledge\KnowledgeRetriever;
use UniversalSupportChat\Audit\AuditLogger;
use UniversalSupportChat\Availability\AvailabilityService;
use UniversalSupportChat\Conversations\ConversationMessage;
use UniversalSupportChat\Conversations\ConversationRepository;
use UniversalSupportChat\Conversations\ConversationStatus;
use UniversalSupportChat\Conversations\MessageRepository;
use UniversalSupportChat\Core\Configuration\Settings;
use UniversalSupportChat\Privacy\Classification;

/**
 * All provider I/O happens here — never in a visitor or Hub request
 * (ADR-0018 §2). Modelled on {@see \UniversalSupportChat\TelegramDispatch\DispatchWorker}:
 * a recurring safety-net sweep plus an immediate one-off hook, a bounded
 * batch, and lease-based crash recovery on the `ai_turns` rows.
 *
 * The escalation rules of ADR-0018 §4 live entirely in {@see process_turn()}:
 * takeover / handoff is re-checked immediately before the provider call, and
 * a handoff stops every further AI turn for the conversation.
 */
final class AiTurnWorker {

	public const HOOK           = 'universal_support_chat_ai_turn_run';
	public const IMMEDIATE_HOOK = 'universal_support_chat_ai_turn_immediate';
	public const SCHEDULE       = 'universal_support_chat_ai_turn_interval';

	private const INTERVAL_SECONDS = 60;
	private const BATCH_LIMIT      = 10;

	/**
	 * Bounded retry backoff, in seconds, indexed by attempt number.
	 */
	private const BACKOFF = array( 30, 90, 300, 900, 1800, 3600 );

	/**
	 * Constructor.
	 *
	 * @param Settings                 $settings      Plugin settings.
	 * @param ConversationRepository   $conversations Conversation repository.
	 * @param MessageRepository        $messages      Message repository.
	 * @param AiTurnRepository         $turns         Turn repository.
	 * @param KnowledgeRetriever       $retriever     Knowledge retriever.
	 * @param AiSystemPolicy           $policy        System policy builder.
	 * @param PromptAssembler          $assembler     Prompt assembler.
	 * @param AiProvider               $provider      AI provider.
	 * @param SafetyClassifier         $safety        Safety pre-check.
	 * @param AiTurnRateLimiter        $limiter       Rate limiter.
	 * @param AvailabilityService|null $availability   Availability service (honest handoff copy).
	 * @param AuditLogger|null         $audit         Audit logger.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly ConversationRepository $conversations,
		private readonly MessageRepository $messages,
		private readonly AiTurnRepository $turns,
		private readonly KnowledgeRetriever $retriever,
		private readonly AiSystemPolicy $policy,
		private readonly PromptAssembler $assembler,
		private readonly AiProvider $provider,
		private readonly SafetyClassifier $safety,
		private readonly AiTurnRateLimiter $limiter,
		private readonly ?AvailabilityService $availability = null,
		private readonly ?AuditLogger $audit = null
	) {}

	/**
	 * Registers the WP-Cron hooks.
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- fixed 60s worker interval.
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( self::IMMEDIATE_HOOK, array( $this, 'run' ) );
	}

	/**
	 * Adds the 60-second worker schedule.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Existing schedules.
	 *
	 * @return array<string, array{interval: int, display: string}>
	 */
	public function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => self::INTERVAL_SECONDS,
			'display'  => __( 'Every minute (Support Chat AI turns)', 'universal-support-chat' ),
		);

		return $schedules;
	}

	/**
	 * Ensures the recurring safety-net sweep is scheduled.
	 */
	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + self::INTERVAL_SECONDS, self::SCHEDULE, self::HOOK );
		}
	}

	/**
	 * WP-Cron / kick entry point.
	 */
	public function run(): void {
		$this->process_due( self::BATCH_LIMIT );
	}

	/**
	 * The non-blocking async kick — same contract as
	 * {@see \UniversalSupportChat\TelegramDispatch\DispatchWorker::request_immediate_run()}.
	 */
	public static function request_immediate_run(): void {
		try {
			if ( ! wp_next_scheduled( self::IMMEDIATE_HOOK ) ) {
				wp_schedule_single_event( time(), self::IMMEDIATE_HOOK );
			}

			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		} catch ( \Throwable $exception ) {
			unset( $exception );
		}
	}

	/**
	 * Unschedules both hooks (deactivation / uninstall).
	 */
	public static function unschedule(): void {
		foreach ( array( self::HOOK, self::IMMEDIATE_HOOK ) as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( false !== $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}

			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Processes up to `$limit` due turns.
	 *
	 * @param int $limit Batch size.
	 */
	public function process_due( int $limit ): void {
		foreach ( $this->turns->claim_due( $limit ) as $row ) {
			try {
				$this->process_turn( $row );
			} catch ( \Throwable $exception ) {
				// A turn must never take the worker down; leave it running —
				// the lease expires and it is retried, and a genuinely poison
				// row exhausts its attempts and hands off.
				unset( $exception );
			}
		}
	}

	/**
	 * Runs one claimed turn through the escalation state machine.
	 *
	 * @param array<string, mixed> $row Claimed turn row.
	 */
	public function process_turn( array $row ): void {
		$turn_id         = (int) $row['id'];
		$turn_uuid       = (string) $row['turn_uuid'];
		$conversation_id = (int) $row['conversation_id'];
		$attempts        = (int) $row['attempts'];

		$conversation = $this->conversations->find_by_id( $conversation_id );

		if ( null === $conversation || ConversationStatus::is_terminal( $conversation->status() ) ) {
			$this->turns->complete_handed_off( $turn_id, HandoffReason::REFUSED );

			return;
		}

		// Takeover / prior handoff — stop here and skip everything queued.
		if ( null !== $conversation->assigned_operator_id() || $this->turns->has_handoff( $conversation_id ) ) {
			$this->turns->skip_pending_for_conversation( $conversation_id );

			return;
		}

		$transcript      = $this->messages->list_for_conversation( $conversation_id, 0, 100 );
		$visitor_message = $this->last_visitor_text( $transcript );

		if ( '' === $visitor_message ) {
			$this->turns->skip_pending_for_conversation( $conversation_id );

			return;
		}

		$settings = $this->settings->get();

		// Rate / spend limits — an honest handoff, never an error.
		$breach = $this->limiter->breach( $conversation->owner_user_id(), $conversation_id, $settings );
		if ( null !== $breach ) {
			$this->handoff( $conversation, $turn_id, $turn_uuid, $breach );

			return;
		}

		// Safety / human-request / unsupported pre-check — before the model.
		$pre = $this->safety->classify( $visitor_message );
		if ( null !== $pre ) {
			$this->handoff( $conversation, $turn_id, $turn_uuid, $pre );

			return;
		}

		$knowledge = $this->retriever->retrieve(
			$visitor_message,
			max( 500, (int) $settings['ai_max_context_chars'] )
		);

		$request = $this->assembler->assemble(
			$this->policy->build(
				(string) get_bloginfo( 'name' ),
				false,
				$this->availability_state()
			),
			$this->transcript_turns( $transcript ),
			$visitor_message,
			array_map(
				static fn ( array $k ): array => array(
					'label' => $k['label'],
					'text'  => $k['text'],
				),
				$knowledge
			),
			(string) $settings['ai_model'],
			(int) $settings['ai_max_output_tokens'],
			(int) $settings['ai_request_timeout_seconds'],
			max( 500, (int) $settings['ai_max_context_chars'] )
		);

		// ADR-0018 §6: re-check takeover immediately before the provider call.
		$fresh = $this->conversations->find_by_id( $conversation_id );
		if ( null === $fresh || null !== $fresh->assigned_operator_id() || $this->turns->has_handoff( $conversation_id ) ) {
			$this->turns->skip_pending_for_conversation( $conversation_id );

			return;
		}

		$started = microtime( true );
		$result  = $this->provider->generate( $request );
		$latency = (int) round( ( microtime( true ) - $started ) * 1000 );

		$source_ids       = implode( ',', array_map( static fn ( array $k ): int => (int) $k['id'], $knowledge ) );
		$source_checksums = implode( ',', array_map( static fn ( array $k ): string => (string) $k['checksum_prefix'], $knowledge ) );

		switch ( $result->outcome() ) {
			case AiResult::OUTCOME_ANSWER:
				$message = $this->messages->create(
					$conversation_id,
					ConversationMessage::DIRECTION_AI,
					(string) $result->answer_text(),
					'stored',
					$turn_uuid
				);

				if ( ! $message instanceof ConversationMessage ) {
					$this->handoff( $conversation, $turn_id, $turn_uuid, HandoffReason::PROVIDER_FAILED );

					return;
				}

				$this->turns->complete_answered(
					$turn_id,
					$message->id(),
					$result->finish_reason(),
					$result->prompt_tokens(),
					$result->completion_tokens(),
					$latency,
					$source_ids,
					$source_checksums
				);
				$this->conversations->touch( $conversation );

				return;

			case AiResult::OUTCOME_REFUSAL:
				$this->handoff(
					$conversation,
					$turn_id,
					$turn_uuid,
					$result->needs_human() ? HandoffReason::UNCERTAIN : HandoffReason::REFUSED
				);

				return;

			default:
				if ( $result->is_retryable() && $attempts + 1 < (int) $settings['ai_max_retries'] ) {
					$this->turns->schedule_retry(
						$turn_id,
						$attempts + 1,
						self::BACKOFF[ min( $attempts, count( self::BACKOFF ) - 1 ) ],
						(string) $result->error_class()
					);

					return;
				}

				$this->handoff( $conversation, $turn_id, $turn_uuid, HandoffReason::PROVIDER_FAILED, $result->error_class() );
		}
	}

	/**
	 * Executes a handoff: a plain visitor-visible message, the transition to
	 * `waiting_for_operator`, the turn outcome, and a stop on all further AI
	 * turns for the conversation.
	 *
	 * @param \UniversalSupportChat\Conversations\Conversation $conversation Conversation.
	 * @param int                                              $turn_id      Turn id.
	 * @param string                                           $turn_uuid    Turn UUID (message idempotency key).
	 * @param string                                           $reason       {@see HandoffReason}.
	 * @param string|null                                      $error_class  Provider error class, when relevant.
	 */
	private function handoff( $conversation, int $turn_id, string $turn_uuid, string $reason, ?string $error_class = null ): void {
		$text = $this->handoff_text( $reason );

		$this->messages->create(
			$conversation->id(),
			ConversationMessage::DIRECTION_SYSTEM,
			$text,
			'stored',
			$turn_uuid
		);

		if ( ConversationStatus::WAITING_FOR_OPERATOR !== $conversation->status()
			&& ConversationStatus::is_valid_transition( $conversation->status(), ConversationStatus::WAITING_FOR_OPERATOR )
		) {
			$this->conversations->transition( $conversation, ConversationStatus::WAITING_FOR_OPERATOR );
		} else {
			$this->conversations->touch( $conversation );
		}

		$this->turns->complete_handed_off( $turn_id, $reason, $error_class );
		$this->turns->skip_pending_for_conversation( $conversation->id() );

		$this->audit_handoff( $reason, $error_class );
	}

	/**
	 * The visitor-visible handoff line — honest, availability-aware, no ETA.
	 *
	 * @param string $reason {@see HandoffReason}.
	 */
	private function handoff_text( string $reason ): string {
		if ( null !== $this->availability && $this->availability->is_unavailable() ) {
			return $this->availability->offline_message();
		}

		return HandoffReason::visitor_message( $reason );
	}

	/**
	 * Records `ai.handoff` (and `ai.escalation` for the safety case). Ids /
	 * enums only — no prompt, answer, or visitor text.
	 *
	 * @param string      $reason      {@see HandoffReason}.
	 * @param string|null $error_class Provider error class.
	 */
	private function audit_handoff( string $reason, ?string $error_class ): void {
		if ( null === $this->audit ) {
			return;
		}

		$context = array( 'reason' => $reason );
		$map     = array( 'reason' => Classification::PUBLIC );

		if ( null !== $error_class ) {
			$context['provider_error_class'] = $error_class;
			$map['provider_error_class']     = Classification::PUBLIC;
		}

		$this->audit->record( 'ai.handoff', 'system', null, $context, $map, Classification::INTERNAL );

		if ( HandoffReason::SAFETY === $reason ) {
			$this->audit->record(
				'ai.escalation',
				'system',
				null,
				array( 'reason' => $reason ),
				array( 'reason' => Classification::PUBLIC ),
				Classification::INTERNAL
			);
		}
	}

	/**
	 * The resolved availability state string.
	 */
	private function availability_state(): string {
		if ( null === $this->availability ) {
			return 'available';
		}

		return $this->availability->is_unavailable() ? 'unavailable' : 'available';
	}

	/**
	 * The most recent visitor message text in a transcript.
	 *
	 * @param array<int, ConversationMessage> $transcript Ordered messages.
	 */
	private function last_visitor_text( array $transcript ): string {
		for ( $i = count( $transcript ) - 1; $i >= 0; $i-- ) {
			if ( ConversationMessage::DIRECTION_VISITOR === $transcript[ $i ]->direction() ) {
				return (string) $transcript[ $i ]->plaintext_body();
			}
		}

		return '';
	}

	/**
	 * Maps a message list to the assembler's transcript shape, excluding the
	 * trailing visitor message (passed separately) and any `system` rows.
	 *
	 * @param array<int, ConversationMessage> $transcript Ordered messages.
	 *
	 * @return array<int, array{role: string, text: string}>
	 */
	private function transcript_turns( array $transcript ): array {
		$out               = array();
		$seen_last_visitor = false;

		for ( $i = count( $transcript ) - 1; $i >= 0; $i-- ) {
			$message = $transcript[ $i ];

			if ( ! $seen_last_visitor && ConversationMessage::DIRECTION_VISITOR === $message->direction() ) {
				$seen_last_visitor = true;
				continue;
			}

			if ( ConversationMessage::DIRECTION_SYSTEM === $message->direction() ) {
				continue;
			}

			array_unshift(
				$out,
				array(
					'role' => $message->direction(),
					'text' => (string) $message->plaintext_body(),
				)
			);
		}

		return $out;
	}
}
