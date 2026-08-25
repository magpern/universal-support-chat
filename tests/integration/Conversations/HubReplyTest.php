<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Conversations;

use UniversalSupportChat\Audit\AuditLogger;
use UniversalSupportChat\Audit\AuditLogRepository;
use UniversalSupportChat\Conversations\ConversationMessage;
use UniversalSupportChat\Conversations\ConversationRepository;
use UniversalSupportChat\Conversations\ConversationStatus;
use UniversalSupportChat\Conversations\MessageRepository;
use UniversalSupportChat\Conversations\NoteRepository;
use UniversalSupportChat\Conversations\Rest\ConversationsController;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalSupportChat\Privacy\Classification;
use UniversalSupportChat\Privacy\Redactor;
use WP_REST_Request;
use WP_UnitTestCase;

final class HubReplyTest extends WP_UnitTestCase {

	private SchemaHealth $health;
	private ConversationRepository $conversations;
	private MessageRepository $messages;
	private NoteRepository $notes;
	private ConversationsController $visitor_rest;

	public function set_up(): void {
		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		$this->health        = new SchemaHealth();
		$vault               = new CredentialVault();
		$this->conversations = new ConversationRepository( $this->health );
		$this->messages      = new MessageRepository( $this->health, $vault );
		$this->notes         = new NoteRepository( $this->health, $vault );
		$this->visitor_rest  = new ConversationsController( $this->health, $this->conversations, $this->messages );

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( CapabilityRegistrar::MANAGE );
		}
	}

	public function test_hub_reply_encrypted_and_visible_as_support_team(): void {
		global $wpdb;

		$visitor_id   = self::factory()->user->create();
		$conversation = $this->conversations->create( $visitor_id );
		$this->assertNotNull( $conversation );
		$opened = $this->conversations->transition( $conversation, ConversationStatus::OPEN );
		$this->assertNotNull( $opened );

		$msg = $this->messages->create(
			$opened->id(),
			ConversationMessage::DIRECTION_OPERATOR,
			'Hello from hub',
			'stored',
			null
		);
		$this->assertNotNull( $msg );

		$table = $wpdb->prefix . Migrator::CONVERSATION_MESSAGES_TABLE;
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT body_ciphertext, direction FROM {$table} WHERE message_uuid = %s", $msg->uuid() ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$this->assertSame( 'operator', $row['direction'] );
		$this->assertStringStartsWith( 'usc1:', (string) $row['body_ciphertext'] );
		$this->assertStringNotContainsString( 'Hello from hub', (string) $row['body_ciphertext'] );

		wp_set_current_user( $visitor_id );
		$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );
		$poll                       = new WP_REST_Request( 'GET', '/universal-support-chat/v1/conversations/' . $opened->uuid() );
		$poll['conversation_uuid']  = $opened->uuid();
		$response                   = $this->visitor_rest->handle_poll( $poll );
		$data                       = $response->get_data();

		$this->assertTrue( $data['ok'] );
		$found = false;
		foreach ( $data['messages'] as $m ) {
			if ( 'Hello from hub' === $m['text'] ) {
				$found = true;
				$this->assertSame( 'operator', $m['direction'] );
				$this->assertSame( 'Support team', $m['author_label'] );
			}
		}
		$this->assertTrue( $found );
	}

	public function test_subscriber_cannot_use_hub_capability(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );
		$this->assertFalse( current_user_can( CapabilityRegistrar::MANAGE ) );
	}

	public function test_hub_note_is_not_in_visitor_poll(): void {
		$visitor_id = self::factory()->user->create();
		$operator   = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$conversation = $this->conversations->create( $visitor_id );
		$this->assertNotNull( $conversation );
		$note = $this->notes->create( $conversation->id(), $operator, 'secret internal note' );
		$this->assertNotNull( $note );

		wp_set_current_user( $visitor_id );
		$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );
		$poll                       = new WP_REST_Request( 'GET', '/universal-support-chat/v1/conversations/' . $conversation->uuid() );
		$poll['conversation_uuid']  = $conversation->uuid();
		$data                       = $this->visitor_rest->handle_poll( $poll )->get_data();

		$blob = wp_json_encode( $data );
		$this->assertStringNotContainsString( 'secret internal note', (string) $blob );
	}

	public function test_audit_reply_has_no_plaintext(): void {
		$visitor_id = self::factory()->user->create();
		$operator   = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$conversation = $this->conversations->create( $visitor_id );
		$this->assertNotNull( $conversation );
		$opened = $this->conversations->transition( $conversation, ConversationStatus::OPEN );
		$msg    = $this->messages->create( $opened->id(), ConversationMessage::DIRECTION_OPERATOR, 'Plain secret body' );
		$this->assertNotNull( $msg );

		$audit = new AuditLogger( $this->health, new Redactor() );
		$audit->record(
			'hub.reply_sent',
			'operator',
			$operator,
			array(
				'conversation_uuid' => $opened->uuid(),
				'message_uuid'      => $msg->uuid(),
			),
			array(
				'conversation_uuid' => Classification::INTERNAL,
				'message_uuid'      => Classification::INTERNAL,
			),
			Classification::INTERNAL
		);

		$repo    = new AuditLogRepository( $this->health );
		$recent  = $repo->recent( 5 );
		$context = (string) $recent[0]['context'];
		$this->assertStringNotContainsString( 'Plain secret body', $context );
	}
}
