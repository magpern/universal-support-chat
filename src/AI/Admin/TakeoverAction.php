<?php
/**
 * "Take over from AI" Hub admin-post action (ADR-0018 §6).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\AI\Admin;

use UniversalSupportChat\AI\Turn\AiTurnRepository;
use UniversalSupportChat\Administration\Hub\HubPage;
use UniversalSupportChat\Audit\AuditLogger;
use UniversalSupportChat\Conversations\ConversationRepository;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;
use UniversalSupportChat\Privacy\Classification;

/**
 * An operator claims the conversation via the existing
 * {@see ConversationRepository::claim()} primitive, and every queued or
 * running AI turn for the conversation is marked `skipped`. Nonce +
 * `MANAGE` gated. Records `ai.takeover` (ids / actor only).
 */
final class TakeoverAction {

	public const ACTION = 'universal_support_chat_ai_takeover';
	public const NONCE  = 'usc_ai_takeover';

	/**
	 * Constructor.
	 *
	 * @param ConversationRepository $conversations Conversation repository.
	 * @param AiTurnRepository       $turns         Turn repository.
	 * @param AuditLogger|null       $audit         Optional audit logger.
	 */
	public function __construct(
		private readonly ConversationRepository $conversations,
		private readonly AiTurnRepository $turns,
		private readonly ?AuditLogger $audit = null
	) {}

	/**
	 * Registers the admin-post hook.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Handles a takeover submission.
	 */
	public function handle(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die(
				esc_html__( 'You do not have permission to take over conversations.', 'universal-support-chat' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified immediately above.
		$conversation_id = isset( $_POST['conversation_id'] ) ? absint( wp_unslash( $_POST['conversation_id'] ) ) : 0;
		$conversation    = $conversation_id > 0 ? $this->conversations->find_by_id( $conversation_id ) : null;

		if ( null === $conversation ) {
			$this->redirect( 0, 'ai_takeover_error' );
		}

		$operator = get_current_user_id();
		$claimed  = $this->conversations->claim( $conversation, $operator );

		if ( null === $claimed ) {
			// Already claimed by someone — treat as success for this operator's
			// intent only if they are the assignee; otherwise report a clash.
			$this->redirect( $conversation_id, null !== $conversation->assigned_operator_id() ? 'ai_takeover_claimed' : 'ai_takeover_error' );
		}

		$this->turns->skip_pending_for_conversation( $conversation_id );

		if ( null !== $this->audit ) {
			$this->audit->record(
				'ai.takeover',
				'operator',
				$operator,
				array( 'conversation_id' => (string) $conversation_id ),
				array( 'conversation_id' => Classification::PUBLIC ),
				Classification::INTERNAL
			);
		}

		$this->redirect( $conversation_id, 'ai_takeover_ok' );
	}

	/**
	 * Redirects back to the conversation detail page.
	 *
	 * @param int    $conversation_id Conversation id.
	 * @param string $notice          Notice code.
	 */
	private function redirect( int $conversation_id, string $notice ): void {
		$args = array(
			'page'       => HubPage::SLUG,
			'usc_notice' => $notice,
		);

		if ( $conversation_id > 0 ) {
			$args['conversation_id'] = $conversation_id;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
