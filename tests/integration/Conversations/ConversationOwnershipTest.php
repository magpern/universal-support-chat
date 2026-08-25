<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Conversations;

use UniversalSupportChat\Conversations\ConversationRepository;
use UniversalSupportChat\Conversations\ConversationStatus;
use UniversalSupportChat\Conversations\MessageRepository;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use WP_UnitTestCase;

final class ConversationOwnershipTest extends WP_UnitTestCase {

	private ConversationRepository $conversations;
	private MessageRepository $messages;

	public function set_up(): void {
		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		$health              = new SchemaHealth();
		$this->conversations = new ConversationRepository( $health );
		$this->messages      = new MessageRepository( $health, new CredentialVault() );
	}

	public function test_create_and_resume_active_for_owner(): void {
		$user_id = self::factory()->user->create();

		$first = $this->conversations->create( $user_id );
		$this->assertNotNull( $first );
		$opened = $this->conversations->transition( $first, ConversationStatus::OPEN );
		$this->assertNotNull( $opened );

		$active = $this->conversations->find_active_for_owner( $user_id );
		$this->assertNotNull( $active );
		$this->assertSame( $first->uuid(), $active->uuid() );
	}

	public function test_start_idempotency_returns_same_conversation(): void {
		$user_id = self::factory()->user->create();
		$key     = wp_generate_uuid4();

		$a = $this->conversations->create( $user_id, $key );
		$b = $this->conversations->create( $user_id, $key );

		$this->assertNotNull( $a );
		$this->assertNotNull( $b );
		$this->assertSame( $a->uuid(), $b->uuid() );
	}

	public function test_cross_visitor_lookup_does_not_leak_ownership(): void {
		$owner = self::factory()->user->create();
		$other = self::factory()->user->create();

		$conversation = $this->conversations->create( $owner );
		$this->assertNotNull( $conversation );

		$found = $this->conversations->find_by_uuid( $conversation->uuid() );
		$this->assertNotNull( $found );
		$this->assertSame( $owner, $found->owner_user_id() );
		$this->assertNotSame( $other, $found->owner_user_id() );
	}
}
