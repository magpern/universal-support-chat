<?php
/**
 * Hub conversation detail and reply form.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Administration\Conversations;

use UniversalSupportChat\Administration\Hub\HubPage;
use UniversalSupportChat\Conversations\ConversationMessage;
use UniversalSupportChat\Conversations\ConversationRepository;
use UniversalSupportChat\Conversations\MessageRepository;
use UniversalSupportChat\Conversations\NoteRepository;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;
use UniversalSupportChat\Persistence\SchemaHealth;

/**
 * Renders transcript (server-side decrypt) and reply/note forms.
 */
final class ConversationDetailPage {

	public const REPLY_ACTION = 'usc_hub_reply';
	public const NOTE_ACTION  = 'usc_hub_add_note';

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
	 * Constructor.
	 *
	 * @param SchemaHealth           $schema_health Schema health.
	 * @param ConversationRepository $conversations Conversations.
	 * @param MessageRepository      $messages      Messages.
	 * @param NoteRepository         $notes         Notes.
	 */
	public function __construct(
		SchemaHealth $schema_health,
		ConversationRepository $conversations,
		MessageRepository $messages,
		NoteRepository $notes
	) {
		$this->schema_health = $schema_health;
		$this->conversations = $conversations;
		$this->messages      = $messages;
		$this->notes         = $notes;
	}

	/**
	 * Renders one conversation.
	 *
	 * @param int $conversation_id Conversation primary key.
	 */
	public function render( int $conversation_id ): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			return;
		}

		$back = admin_url( 'admin.php?page=' . HubPage::SLUG );
		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Back to inbox', 'universal-support-chat' ) . '</a></p>';

		if ( ! $this->schema_health->is_available() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Conversation schema is unavailable.', 'universal-support-chat' ) . '</p></div>';
			return;
		}

		$conversation = $this->conversations->find_by_id( $conversation_id );
		if ( null === $conversation ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Conversation not found.', 'universal-support-chat' ) . '</p></div>';
			return;
		}

		$this->render_notice();

		$owner = get_userdata( $conversation->owner_user_id() );
		$label = $owner ? $owner->user_login : ( '#' . $conversation->owner_user_id() );

		echo '<h2>' . esc_html__( 'Conversation', 'universal-support-chat' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:720px"><tbody>';
		echo '<tr><th>' . esc_html__( 'UUID', 'universal-support-chat' ) . '</th><td><code>' . esc_html( $conversation->uuid() ) . '</code></td></tr>';
		echo '<tr><th>' . esc_html__( 'Visitor', 'universal-support-chat' ) . '</th><td>' . esc_html( $label ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Status', 'universal-support-chat' ) . '</th><td>' . esc_html( $conversation->status() ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Updated', 'universal-support-chat' ) . '</th><td>' . esc_html( $conversation->updated_at() ) . '</td></tr>';
		echo '</tbody></table>';

		$messages = $this->messages->list_for_conversation( $conversation->id(), 0, 500 );
		echo '<h2>' . esc_html__( 'Transcript', 'universal-support-chat' ) . '</h2>';
		echo '<div class="usc-hub-transcript" role="log" aria-live="polite">';
		if ( array() === $messages ) {
			echo '<p>' . esc_html__( 'No messages yet.', 'universal-support-chat' ) . '</p>';
		}
		foreach ( $messages as $message ) {
			switch ( $message->direction() ) {
				case ConversationMessage::DIRECTION_VISITOR:
					$who = __( 'Visitor', 'universal-support-chat' );
					break;
				case ConversationMessage::DIRECTION_AI:
					$who = __( 'AI assistant', 'universal-support-chat' );
					break;
				default:
					$who = __( 'Support team', 'universal-support-chat' );
					break;
			}
			$body = $message->plaintext_body();
			echo '<div class="usc-hub-message usc-hub-message--' . esc_attr( $message->direction() ) . '">';
			echo '<strong>' . esc_html( $who ) . '</strong> ';
			echo '<span class="usc-hub-message__meta">' . esc_html( $message->created_at() ) . '</span>';
			echo '<p>' . esc_html( null !== $body ? $body : __( '(unavailable)', 'universal-support-chat' ) ) . '</p>';
			echo '</div>';
		}
		echo '</div>';

		echo '<h2>' . esc_html__( 'Reply to visitor', 'universal-support-chat' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="usc-hub-reply-form">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::REPLY_ACTION ) . '" />';
		echo '<input type="hidden" name="conversation_id" value="' . esc_attr( (string) $conversation->id() ) . '" />';
		wp_nonce_field( self::REPLY_ACTION . ':' . $conversation->id(), '_usc_hub_nonce' );
		echo '<p><label for="usc-hub-reply-text" class="screen-reader-text">' . esc_html__( 'Reply', 'universal-support-chat' ) . '</label>';
		echo '<textarea id="usc-hub-reply-text" name="text" rows="4" cols="60" maxlength="4096" required></textarea></p>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Send as Support team', 'universal-support-chat' ) . '</button></p>';
		echo '</form>';

		$notes = $this->notes->list_for_conversation( $conversation->id(), 50 );
		echo '<h2>' . esc_html__( 'Internal notes', 'universal-support-chat' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Notes are never shown to the visitor.', 'universal-support-chat' ) . '</p>';
		echo '<div class="usc-hub-notes">';
		foreach ( $notes as $note ) {
			$op   = get_userdata( $note->operator_user_id() );
			$who  = $op ? $op->user_login : ( '#' . $note->operator_user_id() );
			$body = $note->plaintext_body();
			echo '<div class="usc-hub-note">';
			echo '<strong>' . esc_html( $who ) . '</strong> ';
			echo '<span>' . esc_html( $note->created_at() ) . '</span>';
			echo '<p>' . esc_html( null !== $body ? $body : __( '(unavailable)', 'universal-support-chat' ) ) . '</p>';
			echo '</div>';
		}
		echo '</div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="usc-hub-note-form">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::NOTE_ACTION ) . '" />';
		echo '<input type="hidden" name="conversation_id" value="' . esc_attr( (string) $conversation->id() ) . '" />';
		wp_nonce_field( self::NOTE_ACTION . ':' . $conversation->id(), '_usc_hub_nonce' );
		echo '<p><label for="usc-hub-note-text" class="screen-reader-text">' . esc_html__( 'Note', 'universal-support-chat' ) . '</label>';
		echo '<textarea id="usc-hub-note-text" name="text" rows="3" cols="60" maxlength="4096" required></textarea></p>';
		echo '<p><button type="submit" class="button">' . esc_html__( 'Add internal note', 'universal-support-chat' ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * Renders flash notices from redirects.
	 */
	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash.
		$code = isset( $_GET['usc_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['usc_notice'] ) ) : '';
		if ( '' === $code ) {
			return;
		}

		$map = array(
			'reply_sent'   => __( 'Reply sent to the visitor as Support team.', 'universal-support-chat' ),
			'note_added'   => __( 'Internal note saved.', 'universal-support-chat' ),
			'reply_failed' => __( 'Could not send the reply.', 'universal-support-chat' ),
			'note_failed'  => __( 'Could not save the note.', 'universal-support-chat' ),
			'forbidden'    => __( 'You do not have permission to perform that action.', 'universal-support-chat' ),
			'invalid'      => __( 'Invalid request.', 'universal-support-chat' ),
		);

		if ( ! isset( $map[ $code ] ) ) {
			return;
		}

		$class = in_array( $code, array( 'reply_sent', 'note_added' ), true ) ? 'notice-success' : 'notice-error';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $map[ $code ] ) . '</p></div>';
	}
}
