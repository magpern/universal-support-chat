<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Conversations;

use UniversalSupportChat\Conversations\ConversationMessage;
use UniversalSupportChat\Conversations\ConversationRepository;
use UniversalSupportChat\Conversations\MessageRepository;
use UniversalSupportChat\Core\Security\CredentialUnavailableException;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use WP_UnitTestCase;

final class MessageEncryptionTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
	}

	public function test_message_body_encrypted_at_rest_and_decrypts_on_read(): void {
		global $wpdb;

		$health        = new SchemaHealth();
		$conversations = new ConversationRepository( $health );
		$messages      = new MessageRepository( $health, new CredentialVault() );
		$user_id       = self::factory()->user->create();
		$conversation  = $conversations->create( $user_id );
		$this->assertNotNull( $conversation );

		$created = $messages->create(
			$conversation->id(),
			ConversationMessage::DIRECTION_VISITOR,
			'secret visitor text',
			'stored',
			wp_generate_uuid4()
		);
		$this->assertNotNull( $created );
		$this->assertSame( 'secret visitor text', $created->plaintext_body() );

		$table = $wpdb->prefix . Migrator::CONVERSATION_MESSAGES_TABLE;
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT body_ciphertext FROM {$table} WHERE message_uuid = %s", $created->uuid() ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$this->assertIsArray( $row );
		$this->assertStringStartsWith( 'usc1:', (string) $row['body_ciphertext'] );
		$this->assertStringNotContainsString( 'secret visitor text', (string) $row['body_ciphertext'] );
	}

	public function test_message_idempotency_returns_same_uuid(): void {
		$health        = new SchemaHealth();
		$conversations = new ConversationRepository( $health );
		$messages      = new MessageRepository( $health, new CredentialVault() );
		$user_id       = self::factory()->user->create();
		$conversation  = $conversations->create( $user_id );
		$this->assertNotNull( $conversation );
		$key = wp_generate_uuid4();

		$a = $messages->create( $conversation->id(), ConversationMessage::DIRECTION_VISITOR, 'hello', 'stored', $key );
		$b = $messages->create( $conversation->id(), ConversationMessage::DIRECTION_VISITOR, 'hello', 'stored', $key );

		$this->assertNotNull( $a );
		$this->assertNotNull( $b );
		$this->assertSame( $a->uuid(), $b->uuid() );
	}

	public function test_ordering_by_id_ascending(): void {
		$health        = new SchemaHealth();
		$conversations = new ConversationRepository( $health );
		$messages      = new MessageRepository( $health, new CredentialVault() );
		$user_id       = self::factory()->user->create();
		$conversation  = $conversations->create( $user_id );
		$this->assertNotNull( $conversation );

		$first  = $messages->create( $conversation->id(), ConversationMessage::DIRECTION_VISITOR, 'one' );
		$second = $messages->create( $conversation->id(), ConversationMessage::DIRECTION_VISITOR, 'two' );
		$this->assertNotNull( $first );
		$this->assertNotNull( $second );

		$list = $messages->list_for_conversation( $conversation->id(), 0, 10 );
		$this->assertCount( 2, $list );
		$this->assertSame( $first->uuid(), $list[0]->uuid() );
		$this->assertSame( $second->uuid(), $list[1]->uuid() );
		$this->assertLessThan( $list[1]->id(), $list[0]->id() );
	}

	public function test_vault_unavailable_fails_closed(): void {
		$health        = new SchemaHealth();
		$conversations = new ConversationRepository( $health );
		$user_id       = self::factory()->user->create();
		$conversation  = $conversations->create( $user_id );
		$this->assertNotNull( $conversation );

		$vault = new class() extends CredentialVault {
			public function encrypt( string $plaintext, string $context ): string {
				throw new CredentialUnavailableException( 'forced' );
			}
		};

		$messages = new MessageRepository( $health, $vault );
		$result   = $messages->create( $conversation->id(), ConversationMessage::DIRECTION_VISITOR, 'nope' );
		$this->assertNull( $result );
	}
}
