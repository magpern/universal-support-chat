<?php
/**
 * Conversation retention cleanup.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Conversations;

use UniversalSupportChat\Audit\AuditLogger;
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
	 * Constructor.
	 *
	 * @param ConversationRepository        $conversations    Conversation repository.
	 * @param MessageRepository             $messages         Message repository.
	 * @param NoteRepository                $notes            Note repository.
	 * @param Settings                      $settings         Plugin settings.
	 * @param AuditLogger                   $audit            Audit logger.
	 * @param DispatchOutboxRepository|null $dispatch_outbox  Optional Telegram dispatch outbox.
	 */
	public function __construct(
		ConversationRepository $conversations,
		MessageRepository $messages,
		NoteRepository $notes,
		Settings $settings,
		AuditLogger $audit,
		?DispatchOutboxRepository $dispatch_outbox = null
	) {
		$this->conversations   = $conversations;
		$this->messages        = $messages;
		$this->notes           = $notes;
		$this->settings        = $settings;
		$this->audit           = $audit;
		$this->dispatch_outbox = $dispatch_outbox;
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
