<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\AI;

use UniversalSupportChat\AI\Turn\AiTurnRepository;
use UniversalSupportChat\Conversations\ConversationMessage;
use UniversalSupportChat\Conversations\ConversationRepository;
use UniversalSupportChat\Conversations\MessageRepository;
use UniversalSupportChat\Conversations\Rest\ConversationsController;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use WP_REST_Request;
use UniversalSupportChat\Tests\Integration\AI\Support\TruncatesAiTables;
use WP_UnitTestCase;

/**
 * SC-M07 WP4 — the `ai` direction in the visitor poll: distinct
 * "AI assistant" author label; additive `ai_pending` flag.
 */
final class ConversationAiMessageTest extends WP_UnitTestCase {

	use TruncatesAiTables;

	private SchemaHealth $health;
	private ConversationRepository $conversations;
	private MessageRepository $messages;
	private AiTurnRepository $turns;

	public function set_up(): void {
		parent::set_up();
		$this->truncate_ai_tables();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		$this->health        = new SchemaHealth();
		$this->conversations = new ConversationRepository( $this->health );
		$this->messages      = new MessageRepository( $this->health, new CredentialVault() );
		$this->turns         = new AiTurnRepository();
	}

	private function poll( int $conversation_uuid_owner, string $uuid ): array {
		wp_set_current_user( $conversation_uuid_owner );
		$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );

		$controller = new ConversationsController(
			$this->health,
			$this->conversations,
			$this->messages,
			null,
			null,
			$this->turns
		);

		$request = new WP_REST_Request( 'GET', '/universal-support-chat/v1/conversations/' . $uuid );
		$request->set_param( 'conversation_uuid', $uuid );

		return $controller->handle_poll( $request )->get_data();
	}

	public function test_ai_message_is_labelled_ai_assistant_and_pending_flag_tracks_queue(): void {
		$user = self::factory()->user->create();
		$conv = $this->conversations->create( $user );
		$this->messages->create( $conv->id(), ConversationMessage::DIRECTION_VISITOR, 'Do you ship to Norway?', 'stored', wp_generate_uuid4() );

		$data = $this->poll( $user, $conv->uuid() );
		$this->assertArrayHasKey( 'ai_pending', $data );
		$this->assertFalse( $data['ai_pending'] );

		$this->turns->insert_queued( wp_generate_uuid4(), $conv->id(), 1, gmdate( 'Y-m-d H:i:s' ) );
		$this->messages->create( $conv->id(), ConversationMessage::DIRECTION_AI, 'Yes, we ship worldwide.', 'stored', wp_generate_uuid4() );

		$data = $this->poll( $user, $conv->uuid() );
		$this->assertTrue( $data['ai_pending'] );

		$ai = null;
		foreach ( $data['messages'] as $message ) {
			if ( ConversationMessage::DIRECTION_AI === $message['direction'] ) {
				$ai = $message;
			}
		}

		$this->assertNotNull( $ai );
		$this->assertSame( 'AI assistant', $ai['author_label'] );
		$this->assertSame( 'Yes, we ship worldwide.', $ai['text'] );
	}
}
