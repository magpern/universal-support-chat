<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Conversations;

use UniversalSupportChat\Audit\AuditLogger;
use UniversalSupportChat\Audit\AuditLogRepository;
use UniversalSupportChat\Conversations\ConversationRepository;
use UniversalSupportChat\Conversations\ConversationStatus;
use UniversalSupportChat\Conversations\MessageRepository;
use UniversalSupportChat\Conversations\RetentionCleanupHandler;
use UniversalSupportChat\Core\Configuration\Settings;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalSupportChat\Privacy\Redactor;
use WP_UnitTestCase;

final class RetentionCleanupHandlerTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		update_option(
			Settings::OPTION_NAME,
			array(
				'conversation_inactive_days'      => 1,
				'conversation_archived_body_days' => 1,
				'conversation_purge_days'         => 2,
			)
		);
	}

	public function test_dry_run_does_not_mutate_and_audits(): void {
		global $wpdb;

		$health        = new SchemaHealth();
		$conversations = new ConversationRepository( $health );
		$messages      = new MessageRepository( $health, new CredentialVault() );
		$audit         = new AuditLogger( $health, new Redactor() );
		$handler       = new RetentionCleanupHandler( $conversations, $messages, new Settings(), $audit );

		$user_id = self::factory()->user->create();
		$created = $conversations->create( $user_id );
		$this->assertNotNull( $created );
		$opened = $conversations->transition( $created, ConversationStatus::OPEN );
		$this->assertNotNull( $opened );

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		$wpdb->update(
			$table,
			array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - ( 3 * DAY_IN_SECONDS ) ) ),
			array( 'id' => $opened->id() ),
			array( '%s' ),
			array( '%d' )
		);

		$result = $handler->run( true );
		$this->assertGreaterThanOrEqual( 1, $result['resolved'] );

		$still = $conversations->find_by_uuid( $opened->uuid() );
		$this->assertNotNull( $still );
		$this->assertSame( ConversationStatus::OPEN, $still->status() );

		$repo    = new AuditLogRepository( $health );
		$recent  = $repo->recent( 5 );
		$actions = array_column( $recent, 'action' );
		$this->assertContains( 'conversation.retention_cleanup', $actions );
	}

	public function test_purge_deletes_old_archived_conversations(): void {
		global $wpdb;

		$health        = new SchemaHealth();
		$conversations = new ConversationRepository( $health );
		$messages      = new MessageRepository( $health, new CredentialVault() );
		$handler       = new RetentionCleanupHandler(
			$conversations,
			$messages,
			new Settings(),
			new AuditLogger( $health, new Redactor() )
		);

		$user_id = self::factory()->user->create();
		$created = $conversations->create( $user_id );
		$this->assertNotNull( $created );
		$opened   = $conversations->transition( $created, ConversationStatus::OPEN );
		$resolved = $conversations->transition( $opened, ConversationStatus::RESOLVED );
		$archived = $conversations->transition( $resolved, ConversationStatus::ARCHIVED );
		$this->assertNotNull( $archived );

		$msg = $messages->create( $archived->id(), 'visitor', 'old body' );
		$this->assertNotNull( $msg );

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		$wpdb->update(
			$table,
			array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - ( 10 * DAY_IN_SECONDS ) ) ),
			array( 'id' => $archived->id() ),
			array( '%s' ),
			array( '%d' )
		);

		$result = $handler->run( false );
		$this->assertGreaterThanOrEqual( 1, $result['purged'] );
		$this->assertNull( $conversations->find_by_uuid( $archived->uuid() ) );
	}
}
