<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\AI;

use UniversalSupportChat\AI\Knowledge\KnowledgeIndexer;
use UniversalSupportChat\AI\Knowledge\KnowledgeRetriever;
use UniversalSupportChat\AI\Knowledge\KnowledgeSourceRepository;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use WP_UnitTestCase;

/**
 * SC-M07 WP5 — the indexer: copied-not-live snapshots, eligibility rules,
 * stale/revoke lifecycle, and end-to-end retrieval exclusion.
 */
final class KnowledgeIndexerTest extends WP_UnitTestCase {

	private KnowledgeSourceRepository $repo;
	private KnowledgeIndexer $indexer;
	private KnowledgeRetriever $retriever;

	public function set_up(): void {
		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		$this->repo      = new KnowledgeSourceRepository( new CredentialVault() );
		$this->indexer   = new KnowledgeIndexer( $this->repo, null );
		$this->retriever = new KnowledgeRetriever( $this->repo );
		$this->indexer->register();
	}

	private function admin(): int {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );

		return $id;
	}

	public function test_canonical_text_strips_shortcodes_blocks_and_html(): void {
		$raw   = '<!-- wp:paragraph --><p>Free <strong>shipping</strong> to Norway.</p><!-- /wp:paragraph -->[gallery id="1"]';
		$clean = KnowledgeIndexer::normalise( $raw );

		$this->assertStringNotContainsString( '<', $clean );
		$this->assertStringNotContainsString( '[gallery', $clean );
		$this->assertStringContainsString( 'Free shipping to Norway.', $clean );
	}

	public function test_only_published_non_password_posts_can_be_approved(): void {
		$user = $this->admin();

		$draft = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_content' => 'draft shipping content',
			)
		);
		$this->assertFalse( $this->indexer->approve_post( $draft, $user ) );

		$private = self::factory()->post->create(
			array(
				'post_status'  => 'private',
				'post_content' => 'private content',
			)
		);
		$this->assertFalse( $this->indexer->approve_post( $private, $user ) );

		$protected = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
				'post_content'  => 'protected content',
			)
		);
		$this->assertFalse( $this->indexer->approve_post( $protected, $user ) );

		$ok = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Shipping',
				'post_content' => 'We ship parcels to Norway and Sweden.',
			)
		);
		$this->assertTrue( $this->indexer->approve_post( $ok, $user ) );

		$this->assertCount( 1, $this->repo->approved_snapshots() );
	}

	public function test_editing_an_approved_post_marks_it_stale_and_excludes_it(): void {
		$user = $this->admin();
		$post = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Returns',
				'post_content' => 'Returns within 30 days.',
			)
		);
		$this->indexer->approve_post( $post, $user );

		$this->assertNotEmpty( $this->retriever->retrieve( 'returns policy', 5000 ) );

		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => 'Returns within 14 days only.',
			)
		);

		$row = $this->repo->find_by_post( $post );
		$this->assertSame( KnowledgeSourceRepository::STATUS_STALE, $row['status'] );
		$this->assertSame( array(), $this->retriever->retrieve( 'returns policy', 5000 ) );

		// Re-approve refreshes the snapshot and restores retrieval.
		$this->indexer->approve_post( $post, $user );
		$this->assertNotEmpty( $this->retriever->retrieve( 'returns policy', 5000 ) );
	}

	public function test_unpublishing_revokes_and_nulls_the_ciphertext(): void {
		global $wpdb;
		$user = $this->admin();
		$post = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Hours',
				'post_content' => 'Open nine to five weekdays.',
			)
		);
		$this->indexer->approve_post( $post, $user );

		wp_update_post(
			array(
				'ID'          => $post,
				'post_status' => 'draft',
			)
		);

		$row = $this->repo->find_by_post( $post );
		$this->assertSame( KnowledgeSourceRepository::STATUS_REVOKED, $row['status'] );
		$this->assertNull( $row['indexed_text_ciphertext'] );
		$this->assertSame( 'Hours', $row['label'] );
	}

	public function test_trashing_the_post_revokes_the_source(): void {
		$user = $this->admin();
		$post = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'trash me shipping',
			)
		);
		$this->indexer->approve_post( $post, $user );

		wp_trash_post( $post );

		$this->assertSame( KnowledgeSourceRepository::STATUS_REVOKED, $this->repo->find_by_post( $post )['status'] );
	}

	public function test_snippet_round_trip_and_operator_remove_hard_deletes(): void {
		$user = $this->admin();
		$this->assertTrue( $this->indexer->create_snippet( 'Gift wrap', 'Gift wrapping is free on request.', $user ) );

		$hits = $this->retriever->retrieve( 'gift wrapping', 5000 );
		$this->assertNotEmpty( $hits );

		$id = $hits[0]['id'];
		$this->indexer->remove( $id );

		$this->assertNull( $this->repo->find( $id ) );
	}
}
