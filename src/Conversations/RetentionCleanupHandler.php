<?php
/**
 * Conversation retention cleanup.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Conversations;

use UniversalSupportChat\AI\Turn\AiTurnRepository;
use UniversalSupportChat\Audit\AuditLogger;
use UniversalSupportChat\Availability\AvailabilityService;
use UniversalSupportChat\Core\Configuration\Settings;
use UniversalSupportChat\Privacy\Classification;
use UniversalSupportChat\TelegramDispatch\DispatchOutboxRepository;

/**
 * Support Chat-owned retention: resolve inactive, null bodies, purge archived.
 * Scheduled via WP-Cron — no Universal Telegram dependency.
 */
final class RetentionCleanupHandler {

	public const CRON_HOOK = 'universal_support_chat_conversation_retention_cleanup';

	/**
	 * Conversation repository.
	 *
	 * @var ConversationRepository
	 */
	private ConversationRepository $conversations;

	/**
	 * Message repository.
	 *
	 * @var MessageRepository
	 */
	private MessageRepository $messages;

	/**
	 * Note repository.
	 *
	 * @var NoteRepository
	 */
	private NoteRepository $notes;

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Audit logger.
	 *
	 * @var AuditLogger
	 */
	private AuditLogger $audit;

	/**
	 * Optional Telegram dispatch outbox (ADR-0012) — purged alongside a
	 * conversation's messages so no orphan delivery rows survive.
	 *
	 * @var DispatchOutboxRepository|null
	 */
	private ?DispatchOutboxRepository $dispatch_outbox;

	/**
	 * Optional availability service (ADR-0017 §6) — used only to give the
	 * existing daily retention job a cheap tick that reaps an expired manual
	 * override even when nothing else reads {@see AvailabilityService::current_override()}
	 * (no widget render, no Hub/Diagnostics view). No dedicated cron job is
	 * added for this.
	 *
	 * @var AvailabilityService|null
	 */
	private ?AvailabilityService $availability;

	/**
	 * Optional AI turn repository (ADR-0018 §11) — the metadata-only
	 * `ai_turns` rows for a purged conversation are deleted alongside its
	 * messages so no orphan AI metadata survives. Knowledge sources are
	 * config-like admin data and are never touched by retention.
	 *
	 * @var AiTurnRepository|null
	 */
	private ?AiTurnRepository $ai_turns;

	/**
	 * Constructor.
	 *
	 * @param ConversationRepository        $conversations   Conversation repository.
	 * @param MessageRepository             $messages        Message repository.
	 * @param NoteRepository                $notes           Note repository.
	 * @param Settings                      $settings        Plugin settings.
	 * @param AuditLogger                   $audit           Audit logger.
	 * @param DispatchOutboxRepository|null $dispatch_outbox Optional Telegram dispatch outbox.
	 * @param AvailabilityService|null      $availability    Optional availability service (override reaping).
	 * @param AiTurnRepository|null         $ai_turns        Optional AI turn repository (SC-M07).
	 */
	public function __construct(
		ConversationRepository $conversations,
		MessageRepository $messages,
		NoteRepository $notes,
		Settings $settings,
		AuditLogger $audit,
		?DispatchOutboxRepository $dispatch_outbox = null,
		?AvailabilityService $availability = null,
		?AiTurnRepository $ai_turns = null
	) {
		$this->conversations   = $conversations;
		$this->messages        = $messages;
		$this->notes           = $notes;
		$this->settings        = $settings;
		$this->audit           = $audit;
		$this->dispatch_outbox = $dispatch_outbox;
		$this->availability    = $availability;
		$this->ai_turns        = $ai_turns;
	}

	/**
	 * Registers WordPress hooks.
	 */
	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	/**
	 * Ensures the daily WP-Cron event exists.
	 */
	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * WP-Cron entry point (no arguments).
	 */
	public function run_scheduled(): void {
		$this->run( false );
	}

	/**
	 * Runs one retention pass.
	 *
	 * @param bool $dry_run When true, only counts candidates and audits.
	 *
	 * @return array{resolved: int, archived: int, bodies_nulled: int, purged: int}
	 */
	public function run( bool $dry_run = false ): array {
		$settings   = $this->settings->get();
		$inactive   = (int) $settings['conversation_inactive_days'];
		$body_days  = (int) $settings['conversation_archived_body_days'];
		$purge_days = (int) $settings['conversation_purge_days'];

		$resolved = 0;
		$archived = 0;
		$nulled   = 0;
		$purged   = 0;

		// ADR-0017 §6: a non-null override expiry in the past is reaped lazily
		// on read; this cheap tick on the existing daily job is the cron-side
		// half of that, so an override does not sit expired-but-stored until
		// the widget, Hub, Settings page, or Diagnostics happens to read it.
		// This has no effect on the counts below and is skipped on a dry run.
		if ( ! $dry_run && null !== $this->availability ) {
			$this->availability->current_override();
		}

		foreach ( $this->conversations->find_inactive_open( $inactive, 50 ) as $conversation ) {
			++$resolved;
			if ( ! $dry_run ) {
				$this->auto_resolve( $conversation );
			}
		}

		foreach ( $this->conversations->find_resolved( 50 ) as $conversation ) {
			++$archived;
			if ( ! $dry_run ) {
				$this->conversations->transition( $conversation, ConversationStatus::ARCHIVED );
			}
		}

		$body_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $body_days * DAY_IN_SECONDS ) );
		foreach ( $this->conversations->find_archived_before( $body_cutoff, 50 ) as $conversation ) {
			++$nulled;
			if ( ! $dry_run ) {
				$this->messages->null_bodies_for_conversation( $conversation->id() );
			}
		}

		$purge_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $purge_days * DAY_IN_SECONDS ) );
		foreach ( $this->conversations->find_archived_before( $purge_cutoff, 50 ) as $conversation ) {
			++$purged;
			if ( ! $dry_run ) {
				$this->messages->delete_for_conversation( $conversation->id() );
				$this->notes->delete_for_conversation( $conversation->id() );
				if ( null !== $this->dispatch_outbox ) {
					$this->dispatch_outbox->delete_for_conversation( $conversation->id() );
				}
				if ( null !== $this->ai_turns ) {
					$this->ai_turns->delete_for_conversation( $conversation->id() );
				}
				$this->conversations->delete_by_id( $conversation->id() );
			}
		}

		$this->audit->record(
			'conversation.retention_cleanup',
			'system',
			null,
			array(
				'dry_run'  => $dry_run ? 'yes' : 'no',
				'resolved' => (string) $resolved,
				'archived' => (string) $archived,
				'nulled'   => (string) $nulled,
				'purged'   => (string) $purged,
			),
			array(
				'dry_run'  => Classification::PUBLIC,
				'resolved' => Classification::PUBLIC,
				'archived' => Classification::PUBLIC,
				'nulled'   => Classification::PUBLIC,
				'purged'   => Classification::PUBLIC,
			),
			Classification::INTERNAL
		);

		return array(
			'resolved'      => $resolved,
			'archived'      => $archived,
			'bodies_nulled' => $nulled,
			'purged'        => $purged,
		);
	}

	/**
	 * Resolves an inactive conversation through the status map.
	 *
	 * @param Conversation $conversation Conversation snapshot.
	 */
	private function auto_resolve( Conversation $conversation ): void {
		$current = $conversation;

		if ( ConversationStatus::NEW === $current->status() ) {
			$opened = $this->conversations->transition( $current, ConversationStatus::OPEN );
			if ( null === $opened ) {
				return;
			}
			$current = $opened;
		}

		$this->conversations->transition( $current, ConversationStatus::RESOLVED );
	}
}
