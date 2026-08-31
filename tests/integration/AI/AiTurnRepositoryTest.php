<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\AI;

use UniversalSupportChat\AI\Turn\AiTurnRepository;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use WP_UnitTestCase;

/**
 * SC-M07 WP1 — AI turn metadata repository: insert, safe counts, and the
 * retention delete hook.
 */
final class AiTurnRepositoryTest extends WP_UnitTestCase {

	private AiTurnRepository $repo;

	public function set_up(): void {
		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		$this->repo = new AiTurnRepository();
	}

	public function test_insert_queued_and_read_back(): void {
		$uuid = wp_generate_uuid4();
		$id   = $this->repo->insert_queued( $uuid, 42, 7, gmdate( 'Y-m-d H:i:s' ) );

		$this->assertGreaterThan( 0, $id );

		$row = $this->repo->find_by_uuid( $uuid );
		$this->assertIsArray( $row );
		$this->assertSame( 'queued', $row['status'] );
		$this->assertSame( 42, (int) $row['conversation_id'] );
		$this->assertTrue( $this->repo->has_pending_turn( 42 ) );
	}

	public function test_safe_counts(): void {
		$this->repo->insert_queued( wp_generate_uuid4(), 100, 1, gmdate( 'Y-m-d H:i:s' ) );
		$this->repo->insert_queued( wp_generate_uuid4(), 100, 2, gmdate( 'Y-m-d H:i:s' ) );

		$this->assertSame( 2, $this->repo->count_for_conversation( 100 ) );
		$this->assertSame( 2, $this->repo->count_created_since( gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) );
		$this->assertSame( 0, $this->repo->count_handoffs_since( gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) );
	}

	public function test_delete_for_conversation_is_used_by_retention(): void {
		$this->repo->insert_queued( wp_generate_uuid4(), 200, 1, gmdate( 'Y-m-d H:i:s' ) );
		$this->repo->insert_queued( wp_generate_uuid4(), 201, 2, gmdate( 'Y-m-d H:i:s' ) );

		$this->repo->delete_for_conversation( 200 );

		$this->assertSame( 0, $this->repo->count_for_conversation( 200 ) );
		$this->assertSame( 1, $this->repo->count_for_conversation( 201 ) );
	}
}
