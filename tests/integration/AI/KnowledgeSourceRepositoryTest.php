<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\AI;

use UniversalSupportChat\AI\Knowledge\KnowledgeSourceRepository;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use WP_UnitTestCase;

/**
 * SC-M07 WP1 — knowledge source persistence: encrypted at rest, decrypts on
 * read, revoke NULLs the ciphertext, hard-delete removes the row.
 */
final class KnowledgeSourceRepositoryTest extends WP_UnitTestCase {

	private KnowledgeSourceRepository $repo;

	private string $table;

	public function set_up(): void {
		global $wpdb;

		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		$this->repo  = new KnowledgeSourceRepository( new CredentialVault() );
		$this->table = $wpdb->prefix . Migrator::AI_KNOWLEDGE_SOURCES_TABLE;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function row_for_uuid( string $uuid ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE source_uuid = %s", $uuid ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function test_snapshot_is_encrypted_at_rest_and_decrypts_for_ranking(): void {
		$uuid = $this->repo->create_approved(
			KnowledgeSourceRepository::TYPE_SNIPPET,
			null,
			'Returns policy',
			'You may return any item within 30 days for a full refund.',
			1
		);

		$stored = (string) $this->row_for_uuid( $uuid )['indexed_text_ciphertext'];
		$this->assertStringNotContainsString( 'refund', $stored );
		$this->assertStringStartsWith( 'usc1:', $stored );

		$snapshots = $this->repo->approved_snapshots();
		$this->assertCount( 1, $snapshots );
		$this->assertStringContainsString( 'full refund', $snapshots[0]['text'] );
		$this->assertSame( 'Returns policy', $snapshots[0]['label'] );
	}

	public function test_revoke_nulls_ciphertext_and_keeps_tombstone(): void {
		$uuid = $this->repo->create_approved( KnowledgeSourceRepository::TYPE_SNIPPET, null, 'Hours', 'We are open 9 to 5.', 1 );

		$this->repo->revoke( (int) $this->row_for_uuid( $uuid )['id'] );

		$after = $this->row_for_uuid( $uuid );
		$this->assertSame( KnowledgeSourceRepository::STATUS_REVOKED, $after['status'] );
		$this->assertNull( $after['indexed_text_ciphertext'] );
		$this->assertSame( 'Hours', $after['label'] );
		$this->assertSame( array(), $this->repo->approved_snapshots() );
	}

	public function test_hard_delete_removes_the_row(): void {
		$uuid = $this->repo->create_approved( KnowledgeSourceRepository::TYPE_SNIPPET, null, 'Shipping', 'Free shipping over 50.', 1 );

		$this->repo->delete( (int) $this->row_for_uuid( $uuid )['id'] );

		$this->assertNull( $this->row_for_uuid( $uuid ) );
	}

	public function test_count_by_status_is_a_safe_aggregate(): void {
		$this->repo->create_approved( KnowledgeSourceRepository::TYPE_SNIPPET, null, 'A', 'alpha text', 1 );
		$second = $this->repo->create_approved( KnowledgeSourceRepository::TYPE_SNIPPET, null, 'B', 'bravo text', 1 );

		$this->repo->mark_stale( (int) $this->row_for_uuid( $second )['id'] );

		$counts = $this->repo->count_by_status();
		$this->assertSame( 1, $counts[ KnowledgeSourceRepository::STATUS_APPROVED ] );
		$this->assertSame( 1, $counts[ KnowledgeSourceRepository::STATUS_STALE ] );
	}
}
