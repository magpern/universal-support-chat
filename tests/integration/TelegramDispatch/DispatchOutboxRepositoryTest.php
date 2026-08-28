<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\TelegramDispatch;

use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalSupportChat\TelegramDispatch\DispatchOutboxRepository;
use UniversalSupportChat\TelegramDispatch\DispatchRecord;
use WP_UnitTestCase;

final class DispatchOutboxRepositoryTest extends WP_UnitTestCase {

	private DispatchOutboxRepository $outbox;

	public function set_up(): void {
		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		$this->outbox = new DispatchOutboxRepository( new SchemaHealth() );
	}

	public function test_enqueue_is_idempotent_on_message_uuid(): void {
		$uuid = wp_generate_uuid4();

		$this->assertTrue( $this->outbox->enqueue( $uuid, 1, wp_generate_uuid4(), 'visitor' ) );
		$this->assertFalse( $this->outbox->enqueue( $uuid, 1, wp_generate_uuid4(), 'visitor' ) );

		$record = $this->outbox->find( $uuid );
		$this->assertNotNull( $record );
		$this->assertSame( DispatchRecord::STATE_PENDING, $record->state() );
		$this->assertSame( DispatchRecord::ORIGIN_SUPPORT_CHAT, $record->origin() );
	}

	public function test_telegram_origin_marker_wins_and_blocks_a_later_enqueue(): void {
		$uuid = wp_generate_uuid4();

		$this->assertTrue( $this->outbox->mark_telegram_origin( $uuid, 5, wp_generate_uuid4(), 'operator' ) );
		$this->assertFalse( $this->outbox->enqueue( $uuid, 5, wp_generate_uuid4(), 'operator' ) );

		$record = $this->outbox->find( $uuid );
		$this->assertSame( DispatchRecord::STATE_SUPPRESSED, $record->state() );
		$this->assertSame( DispatchRecord::ORIGIN_TELEGRAM, $record->origin() );
	}

	public function test_claim_due_only_returns_pending_or_failed_that_are_due(): void {
		$due   = wp_generate_uuid4();
		$suppr = wp_generate_uuid4();
		$this->outbox->enqueue( $due, 1, wp_generate_uuid4(), 'visitor' );
		$this->outbox->mark_telegram_origin( $suppr, 1, wp_generate_uuid4(), 'operator' );

		$claimed = $this->outbox->claim_due( 20 );
		$this->assertCount( 1, $claimed );
		$this->assertSame( $due, $claimed[0]->message_uuid() );
		$this->assertSame( DispatchRecord::STATE_DELIVERING, $claimed[0]->state() );
		$this->assertSame( 1, $claimed[0]->attempts() );

		// A second sweep does not re-claim the now-`delivering` row.
		$this->assertCount( 0, $this->outbox->claim_due( 20 ) );
	}

	public function test_mark_failed_backs_off_then_becomes_due_again(): void {
		$uuid = wp_generate_uuid4();
		$this->outbox->enqueue( $uuid, 1, wp_generate_uuid4(), 'visitor' );
		$claimed = $this->outbox->claim_due( 1 )[0];

		$this->outbox->mark_failed( $claimed->id(), 'channel_unavailable:peer_not_usable', 3600 );
		$record = $this->outbox->find_by_id( $claimed->id() );
		$this->assertSame( DispatchRecord::STATE_FAILED, $record->state() );
		$this->assertSame( 'channel_unavailable:peer_not_usable', $record->last_reason() );
		$this->assertGreaterThan( gmdate( 'Y-m-d H:i:s' ), $record->next_attempt_at() );

		// Not due yet.
		$this->assertCount( 0, $this->outbox->claim_due( 20 ) );

		// Once due again it is retried.
		$this->outbox->mark_failed( $claimed->id(), 'again', 1 );
		sleep( 2 );
		$reclaimed = $this->outbox->claim_due( 20 );
		$this->assertCount( 1, $reclaimed );
		$this->assertSame( 2, $reclaimed[0]->attempts() );
	}

	public function test_count_by_state_and_delete_for_conversation(): void {
		$this->outbox->enqueue( wp_generate_uuid4(), 7, wp_generate_uuid4(), 'visitor' );
		$this->outbox->enqueue( wp_generate_uuid4(), 7, wp_generate_uuid4(), 'operator' );
		$this->outbox->mark_telegram_origin( wp_generate_uuid4(), 8, wp_generate_uuid4(), 'operator' );

		$counts = $this->outbox->count_by_state();
		$this->assertSame( 2, $counts[ DispatchRecord::STATE_PENDING ] );
		$this->assertSame( 1, $counts[ DispatchRecord::STATE_SUPPRESSED ] );

		$this->outbox->delete_for_conversation( 7 );
		$after = $this->outbox->count_by_state();
		$this->assertArrayNotHasKey( DispatchRecord::STATE_PENDING, $after );
		$this->assertSame( 1, $after[ DispatchRecord::STATE_SUPPRESSED ] );
	}
}
