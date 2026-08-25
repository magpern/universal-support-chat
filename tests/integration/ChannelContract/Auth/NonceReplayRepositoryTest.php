<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\ChannelContract\Auth;

use UniversalSupportChat\ChannelContract\Auth\NonceReplayRepository;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use WP_UnitTestCase;

final class NonceReplayRepositoryTest extends WP_UnitTestCase {

	private NonceReplayRepository $nonces;

	public function set_up(): void {
		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		$this->nonces = new NonceReplayRepository( new SchemaHealth() );
	}

	public function test_first_use_is_accepted_and_replay_is_rejected(): void {
		$this->assertTrue( $this->nonces->record_if_new( 'universal-telegram', 'universal-telegram.aaaaaaaaaaaaaaaa', 'nonce-one' ) );
		$this->assertFalse( $this->nonces->record_if_new( 'universal-telegram', 'universal-telegram.aaaaaaaaaaaaaaaa', 'nonce-one' ) );
	}

	public function test_same_nonce_is_distinct_per_sender_and_key(): void {
		$this->assertTrue( $this->nonces->record_if_new( 'universal-telegram', 'universal-telegram.aaaaaaaaaaaaaaaa', 'shared-nonce' ) );
		$this->assertTrue( $this->nonces->record_if_new( 'other-adapter', 'other-adapter.bbbbbbbbbbbbbbbb', 'shared-nonce' ) );
	}

	public function test_purge_expired_removes_only_stale_rows(): void {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONTRACT_NONCES_TABLE;
		$wpdb->insert(
			$table,
			array(
				'sender'      => 'universal-telegram',
				'key_id'      => 'universal-telegram.aaaaaaaaaaaaaaaa',
				'nonce'       => 'stale-nonce',
				'recorded_at' => gmdate( 'Y-m-d H:i:s', time() - NonceReplayRepository::RETENTION_SECONDS - 60 ),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		$this->assertTrue( $this->nonces->record_if_new( 'universal-telegram', 'universal-telegram.aaaaaaaaaaaaaaaa', 'fresh-nonce' ) );

		$removed = $this->nonces->purge_expired();
		$this->assertSame( 1, $removed );

		// The stale nonce is gone; the fresh one is still tracked (replay rejected).
		$this->assertTrue( $this->nonces->record_if_new( 'universal-telegram', 'universal-telegram.aaaaaaaaaaaaaaaa', 'stale-nonce' ) );
		$this->assertFalse( $this->nonces->record_if_new( 'universal-telegram', 'universal-telegram.aaaaaaaaaaaaaaaa', 'fresh-nonce' ) );
	}
}
