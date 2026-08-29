<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\TelegramDispatch;

use UniversalSupportChat\Audit\AuditLogger;
use UniversalSupportChat\ChannelContract\Outbound\AdapterContractClient;
use UniversalSupportChat\Conversations\ConversationMessage;
use UniversalSupportChat\Conversations\MessageRepository;
use UniversalSupportChat\Core\Configuration\Settings;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalSupportChat\Privacy\Redactor;
use UniversalSupportChat\TelegramDispatch\DispatchOutboxRepository;
use UniversalSupportChat\TelegramDispatch\DispatchRecord;
use UniversalSupportChat\TelegramDispatch\TelegramDispatchService;
use UniversalSupportChat\Tests\Integration\TelegramDispatch\Support\RecordingAdapterContractClient;
use WP_UnitTestCase;

final class TelegramDispatchServiceTest extends WP_UnitTestCase {

	private DispatchOutboxRepository $outbox;
	private MessageRepository $messages;
	private RecordingAdapterContractClient $client;

	public function set_up(): void {
		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		// Another test file's enabled path commits real rows (it opens an
		// explicit transaction); clear any that leaked in.
		global $wpdb;
		foreach ( array( Migrator::TELEGRAM_DISPATCH_TABLE, Migrator::CONVERSATION_MESSAGES_TABLE ) as $table_constant ) {
			$table = $wpdb->prefix . $table_constant;
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test cleanup.
		}

		$health         = new SchemaHealth();
		$this->outbox   = new DispatchOutboxRepository( $health );
		$this->messages = new MessageRepository( $health, new CredentialVault() );
		$this->client   = new RecordingAdapterContractClient();

		update_option( Settings::OPTION_NAME, array( 'telegram_dispatch_enabled' => true ) );
	}

	private function service(): TelegramDispatchService {
		return new TelegramDispatchService(
			new Settings(),
			$this->outbox,
			$this->messages,
			$this->client,
			new AuditLogger( new SchemaHealth(), new Redactor() )
		);
	}

	private function seed_message( string $direction, string $body ): ConversationMessage {
		$message = $this->messages->create( 4242, $direction, $body, 'stored', null );
		$this->assertNotNull( $message );

		return $message;
	}

	public function test_disabled_feature_is_a_no_op(): void {
		update_option( Settings::OPTION_NAME, array( 'telegram_dispatch_enabled' => false ) );
		$message = $this->seed_message( ConversationMessage::DIRECTION_VISITOR, 'hi' );
		$this->outbox->enqueue( $message->uuid(), 4242, wp_generate_uuid4(), 'visitor' );

		$result = $this->service()->dispatch_due();

		$this->assertSame( 0, $result['processed'] );
		$this->assertSame( array(), $this->client->calls );
		$this->assertSame( DispatchRecord::STATE_PENDING, $this->outbox->find( $message->uuid() )->state() );
	}

	public function test_visitor_message_is_ensured_notified_and_delivered(): void {
		$conversation_uuid = wp_generate_uuid4();
		$message           = $this->seed_message( ConversationMessage::DIRECTION_VISITOR, 'Where is my order?' );
		$this->outbox->enqueue( $message->uuid(), 4242, $conversation_uuid, 'visitor' );

		$result = $this->service()->dispatch_due();

		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( 1, $result['delivered'] );

		$ensure = $this->client->calls_for( 'ensure_channel_case' )[0];
		$this->assertSame( 'universal-telegram', $ensure['peer_id'] );
		$this->assertSame( $conversation_uuid, $ensure['conversation_uuid'] );

		// notify_operators only because ensure reported `created`.
		$this->assertCount( 1, $this->client->calls_for( 'notify_operators' ) );

		$deliver = $this->client->calls_for( 'deliver_message' )[0];
		$this->assertSame( 'ref-abc', $deliver['channel_case_ref'] );
		$this->assertSame( $message->uuid(), $deliver['message_uuid'] );
		$this->assertSame( 'Where is my order?', $deliver['body'] );
		$this->assertSame( 'Visitor', $deliver['attribution'] );

		$record = $this->outbox->find( $message->uuid() );
		$this->assertSame( DispatchRecord::STATE_DELIVERED, $record->state() );
		$this->assertSame( 'ref-abc', $record->channel_case_ref() );
	}

	public function test_operator_message_is_delivered_with_support_attribution_and_no_notify_when_reused(): void {
		$this->client->ensure_result['case_status'] = 'reused';
		$message                                    = $this->seed_message( ConversationMessage::DIRECTION_OPERATOR, 'On its way!' );
		$this->outbox->enqueue( $message->uuid(), 4242, wp_generate_uuid4(), 'operator' );

		$this->service()->dispatch_due();

		$this->assertSame( array(), $this->client->calls_for( 'notify_operators' ) );
		$this->assertSame( 'Support', $this->client->calls_for( 'deliver_message' )[0]['attribution'] );
		$this->assertSame( DispatchRecord::STATE_DELIVERED, $this->outbox->find( $message->uuid() )->state() );
	}

	public function test_unavailable_channel_leaves_the_row_retryable(): void {
		$this->client->ensure_result = array(
			'ok'               => false,
			'status'           => 503,
			'reason'           => AdapterContractClient::REASON_NOT_PAIRED,
			'channel_case_ref' => '',
			'case_status'      => null,
		);
		$message                     = $this->seed_message( ConversationMessage::DIRECTION_VISITOR, 'hello' );
		$this->outbox->enqueue( $message->uuid(), 4242, wp_generate_uuid4(), 'visitor' );

		$result = $this->service()->dispatch_due();

		$this->assertSame( 1, $result['failed'] );
		$this->assertSame( array(), $this->client->calls_for( 'deliver_message' ) );
		$record = $this->outbox->find( $message->uuid() );
		$this->assertSame( DispatchRecord::STATE_FAILED, $record->state() );
		$this->assertStringContainsString( 'channel_unavailable', (string) $record->last_reason() );
	}

	public function test_permanent_delivery_rejection_is_abandoned(): void {
		$this->client->deliver_result = array(
			'ok'     => false,
			'status' => 400,
			'reason' => AdapterContractClient::REASON_INVALID_INPUT,
			'reused' => false,
		);
		$message                      = $this->seed_message( ConversationMessage::DIRECTION_VISITOR, 'x' );
		$this->outbox->enqueue( $message->uuid(), 4242, wp_generate_uuid4(), 'visitor' );

		$result = $this->service()->dispatch_due();

		$this->assertSame( 1, $result['abandoned'] );
		$this->assertSame( DispatchRecord::STATE_ABANDONED, $this->outbox->find( $message->uuid() )->state() );
	}

	public function test_missing_message_row_is_abandoned(): void {
		$this->outbox->enqueue( wp_generate_uuid4(), 4242, wp_generate_uuid4(), 'visitor' );

		$result = $this->service()->dispatch_due();

		$this->assertSame( 1, $result['abandoned'] );
		$this->assertSame( array(), $this->client->calls );
	}

	public function test_retry_converges_without_a_duplicate_delivery(): void {
		$message = $this->seed_message( ConversationMessage::DIRECTION_VISITOR, 'once' );
		$this->outbox->enqueue( $message->uuid(), 4242, wp_generate_uuid4(), 'visitor' );

		$this->service()->dispatch_due();
		// Row is now `delivered`; a second sweep must not touch it or the client.
		$before = count( $this->client->calls_for( 'deliver_message' ) );
		$this->service()->dispatch_due();

		$this->assertSame( $before, count( $this->client->calls_for( 'deliver_message' ) ) );
	}

	public function test_crash_before_remote_delivery_is_reclaimed_and_delivered(): void {
		$message = $this->seed_message( ConversationMessage::DIRECTION_VISITOR, 'crash before send' );
		$this->outbox->enqueue( $message->uuid(), 4242, wp_generate_uuid4(), 'visitor' );

		// Simulate a worker that claimed the row and then died before
		// calling the adapter.
		$claimed = $this->outbox->claim_due( 1 )[0];
		$this->assertSame( DispatchRecord::STATE_DELIVERING, $this->outbox->find_by_id( $claimed->id() )->state() );
		$this->assertSame( array(), $this->client->calls );
		$this->expire_lease( $claimed->id() );

		// A later sweep reclaims the stale claim and delivers it.
		$result = $this->service()->dispatch_due();

		$this->assertSame( 1, $result['delivered'] );
		$this->assertCount( 1, $this->client->calls_for( 'deliver_message' ) );
		$this->assertSame( DispatchRecord::STATE_DELIVERED, $this->outbox->find( $message->uuid() )->state() );
	}

	public function test_crash_after_remote_acceptance_reclaims_and_converges_via_idempotent_reuse(): void {
		// The adapter already accepted the delivery on the crashed attempt;
		// a second call for the same message UUID returns `reused`.
		$this->client->deliver_result = array(
			'ok'     => true,
			'status' => 200,
			'reason' => null,
			'reused' => true,
		);

		$message = $this->seed_message( ConversationMessage::DIRECTION_OPERATOR, 'crash after send' );
		$this->outbox->enqueue( $message->uuid(), 4242, wp_generate_uuid4(), 'operator' );

		$claimed = $this->outbox->claim_due( 1 )[0];
		$this->expire_lease( $claimed->id() );

		$result = $this->service()->dispatch_due();

		$this->assertSame( 1, $result['delivered'] );
		$this->assertSame( DispatchRecord::STATE_DELIVERED, $this->outbox->find( $message->uuid() )->state() );
	}

	public function test_stale_delivering_rows_never_stay_stranded(): void {
		$message = $this->seed_message( ConversationMessage::DIRECTION_VISITOR, 'x' );
		$this->outbox->enqueue( $message->uuid(), 4242, wp_generate_uuid4(), 'visitor' );
		$claimed = $this->outbox->claim_due( 1 )[0];
		$this->expire_lease( $claimed->id() );

		$reclaimed = $this->outbox->reclaim_expired_leases();

		$this->assertSame( 1, $reclaimed );
		$record = $this->outbox->find( $message->uuid() );
		$this->assertSame( DispatchRecord::STATE_FAILED, $record->state() );
		$this->assertSame( 'lease_expired', $record->last_reason() );
		$this->assertNull( $record->lease_expires_at() );
	}

	private function expire_lease( int $id ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . Migrator::TELEGRAM_DISPATCH_TABLE,
			array( 'lease_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	// ---- ADR-0014 Amendment 1: delivery is worker-only, class is interactive_chat ----

	public function test_worker_delivers_with_delivery_class_interactive_chat(): void {
		$message = $this->seed_message( ConversationMessage::DIRECTION_VISITOR, 'Where is my order?' );
		$this->outbox->enqueue( $message->uuid(), 4242, wp_generate_uuid4(), 'visitor' );

		$this->service()->dispatch_due( 10 );

		$deliver = $this->client->calls_for( 'deliver_message' );
		$this->assertCount( 1, $deliver );
		$this->assertSame( TelegramDispatchService::DELIVERY_CLASS_INTERACTIVE, $deliver[0]['delivery_class'] );
		$this->assertSame( DispatchRecord::STATE_DELIVERED, $this->outbox->find( $message->uuid() )->state() );
	}

	public function test_new_conversation_topic_creation_and_delivery_happen_in_the_worker_and_converge(): void {
		$this->client->ensure_result['case_status'] = 'created';
		$message                                    = $this->seed_message( ConversationMessage::DIRECTION_VISITOR, 'first contact' );
		$this->outbox->enqueue( $message->uuid(), 4242, wp_generate_uuid4(), 'visitor' );

		$this->service()->dispatch_due( 10 );

		$this->assertCount( 1, $this->client->calls_for( 'ensure_channel_case' ) );
		$this->assertCount( 1, $this->client->calls_for( 'notify_operators' ) );
		$this->assertCount( 1, $this->client->calls_for( 'deliver_message' ) );
		$this->assertSame( DispatchRecord::STATE_DELIVERED, $this->outbox->find( $message->uuid() )->state() );

		$this->service()->dispatch_due( 10 );
		$this->assertCount( 1, $this->client->calls_for( 'deliver_message' ) );
	}

	public function test_worker_converges_after_a_transient_failure_with_no_duplicate(): void {
		$this->client->ensure_result = array(
			'ok'               => false,
			'status'           => 503,
			'reason'           => AdapterContractClient::REASON_TRANSPORT_FAILED,
			'channel_case_ref' => '',
			'case_status'      => null,
		);
		$message                     = $this->seed_message( ConversationMessage::DIRECTION_OPERATOR, 'reply' );
		$this->outbox->enqueue( $message->uuid(), 4242, wp_generate_uuid4(), 'operator' );

		$this->service()->dispatch_due( 10 );
		$this->assertSame( DispatchRecord::STATE_FAILED, $this->outbox->find( $message->uuid() )->state() );
		$this->assertSame( array(), $this->client->calls_for( 'deliver_message' ) );

		$this->client->ensure_result = array(
			'ok'               => true,
			'status'           => 200,
			'reason'           => null,
			'channel_case_ref' => 'ref-abc',
			'case_status'      => 'reused',
		);
		$this->make_due( $message->uuid() );
		$this->service()->dispatch_due( 10 );

		$this->assertCount( 1, $this->client->calls_for( 'deliver_message' ) );
		$this->assertSame( DispatchRecord::STATE_DELIVERED, $this->outbox->find( $message->uuid() )->state() );
	}

	private function make_due( string $message_uuid ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . Migrator::TELEGRAM_DISPATCH_TABLE,
			array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
			array( 'message_uuid' => $message_uuid ),
			array( '%s' ),
			array( '%s' )
		);
	}
}
