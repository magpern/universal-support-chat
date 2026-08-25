<?php
/**
 * Hub admin-post handlers for reply and notes.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Administration\Conversations;

use UniversalSupportChat\Administration\Hub\HubPage;
use UniversalSupportChat\Audit\AuditLogger;
use UniversalSupportChat\Conversations\ConversationMessage;
use UniversalSupportChat\Conversations\ConversationRepository;
use UniversalSupportChat\Conversations\ConversationStatus;
use UniversalSupportChat\Conversations\MessageRepository;
use UniversalSupportChat\Conversations\NoteRepository;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalSupportChat\Privacy\Classification;

/**
 * Capability + CSRF gated Hub mutations. Never audits plaintext bodies.
 */
final class HubActions {

	private const MAX_TEXT_CHARS = 4096;

	/**
	 * Schema availability gate.
	 *
	 * @var SchemaHealth
	 */
	private SchemaHealth $schema_health;

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
	 * Audit logger.
	 *
	 * @var AuditLogger
	 */
	private AuditLogger $audit;

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth           $schema_health Schema health.
	 * @param ConversationRepository $conversations Conversations.
	 * @param MessageRepository      $messages      Messages.
	 * @param NoteRepository         $notes         Notes.
	 * @param AuditLogger            $audit         Audit logger.
	 */
	public function __construct(
		SchemaHealth $schema_health,
		ConversationRepository $conversations,
		MessageRepository $messages,
		NoteRepository $notes,
		AuditLogger $audit
	) {
		$this->schema_health = $schema_health;
		$this->conversations = $conversations;
		$this->messages      = $messages;
		$this->notes         = $notes;
		$this->audit         = $audit;
	}

	/**
	 * Registers admin-post hooks.
	 */
	public function register(): void {
		add_action( 'admin_post_' . ConversationDetailPage::REPLY_ACTION, array( $this, 'handle_reply' ) );
		add_action( 'admin_post_' . ConversationDetailPage::NOTE_ACTION, array( $this, 'handle_note' ) );
	}

	/**
	 * Handles Hub → visitor reply.
	 */
	public function handle_reply(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified immediately in guard().
		$conversation_id = isset( $_POST['conversation_id'] ) ? (int) $_POST['conversation_id'] : 0;
		$text            = isset( $_POST['text'] ) ? trim( wp_check_invalid_utf8( wp_unslash( (string) $_POST['text'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$this->guard( ConversationDetailPage::REPLY_ACTION, $conversation_id );

		if ( '' === $text || strlen( $text ) > self::MAX_TEXT_CHARS ) {
			$this->redirect( $conversation_id, 'invalid' );
		}

		if ( ! $this->schema_health->is_available() ) {
			$this->redirect( $conversation_id, 'reply_failed' );
		}

		$conversation = $this->conversations->find_by_id( $conversation_id );
		if ( null === $conversation ) {
			$this->redirect( 0, 'invalid' );
		}

		$message = $this->messages->create(
			$conversation->id(),
			ConversationMessage::DIRECTION_OPERATOR,
			$text,
			'stored',
			null
		);

		if ( null === $message ) {
			$this->redirect( $conversation_id, 'reply_failed' );
		}

		$current = $conversation;
		if ( ConversationStatus::NEW === $current->status() ) {
			$opened  = $this->conversations->transition( $current, ConversationStatus::OPEN );
			$current = $opened ?? $current;
		}

		if ( ConversationStatus::WAITING_FOR_OPERATOR === $current->status() ) {
			$opened  = $this->conversations->transition( $current, ConversationStatus::OPEN );
			$current = $opened ?? $current;
		}

		if ( ConversationStatus::OPEN === $current->status() ) {
			$this->conversations->transition( $current, ConversationStatus::WAITING_FOR_VISITOR );
		} else {
			$this->conversations->touch( $current );
		}

		$this->audit->record(
			'hub.reply_sent',
			'operator',
			get_current_user_id(),
			array(
				'conversation_uuid' => $conversation->uuid(),
				'message_uuid'      => $message->uuid(),
			),
			array(
				'conversation_uuid' => Classification::INTERNAL,
				'message_uuid'      => Classification::INTERNAL,
			),
			Classification::INTERNAL
		);

		$this->redirect( $conversation_id, 'reply_sent' );
	}

	/**
	 * Handles Hub internal note create.
	 */
	public function handle_note(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified immediately in guard().
		$conversation_id = isset( $_POST['conversation_id'] ) ? (int) $_POST['conversation_id'] : 0;
		$text            = isset( $_POST['text'] ) ? trim( wp_check_invalid_utf8( wp_unslash( (string) $_POST['text'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$this->guard( ConversationDetailPage::NOTE_ACTION, $conversation_id );

		if ( '' === $text || strlen( $text ) > self::MAX_TEXT_CHARS ) {
			$this->redirect( $conversation_id, 'invalid' );
		}

		if ( ! $this->schema_health->is_available() ) {
			$this->redirect( $conversation_id, 'note_failed' );
		}

		$conversation = $this->conversations->find_by_id( $conversation_id );
		if ( null === $conversation ) {
			$this->redirect( 0, 'invalid' );
		}

		$note = $this->notes->create( $conversation->id(), get_current_user_id(), $text );
		if ( null === $note ) {
			$this->redirect( $conversation_id, 'note_failed' );
		}

		$this->audit->record(
			'hub.note_added',
			'operator',
			get_current_user_id(),
			array(
				'conversation_uuid' => $conversation->uuid(),
				'note_uuid'         => $note->uuid(),
			),
			array(
				'conversation_uuid' => Classification::INTERNAL,
				'note_uuid'         => Classification::INTERNAL,
			),
			Classification::INTERNAL
		);

		$this->redirect( $conversation_id, 'note_added' );
	}

	/**
	 * Capability + CSRF gate.
	 *
	 * @param string $action           Action name.
	 * @param int    $conversation_id  Conversation ID.
	 */
	private function guard( string $action, int $conversation_id ): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			$this->redirect( $conversation_id, 'forbidden' );
		}

		check_admin_referer( $action . ':' . $conversation_id, '_usc_hub_nonce' );
	}

	/**
	 * Redirects back to Hub with a notice code.
	 *
	 * @param int    $conversation_id Conversation ID (0 = inbox).
	 * @param string $notice          Notice code.
	 */
	private function redirect( int $conversation_id, string $notice ): void {
		$url = admin_url( 'admin.php?page=' . HubPage::SLUG );
		if ( $conversation_id > 0 ) {
			$url = add_query_arg(
				array(
					'conversation_id' => $conversation_id,
					'usc_notice'      => $notice,
				),
				$url
			);
		} else {
			$url = add_query_arg( 'usc_notice', $notice, $url );
		}

		wp_safe_redirect( $url );
		exit;
	}
}
