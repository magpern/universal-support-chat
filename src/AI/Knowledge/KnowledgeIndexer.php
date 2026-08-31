<?php
/**
 * Approved-knowledge indexing (ADR-0018 §9, SC-M07).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\AI\Knowledge;

use UniversalSupportChat\Audit\AuditLogger;
use UniversalSupportChat\Core\Security\CredentialUnavailableException;
use UniversalSupportChat\Privacy\Classification;
use WP_Post;

/**
 * Extracts a canonical plain-text snapshot of approved content and keeps it
 * in sync (ADR-0018 §9):
 *
 * - content is **copied at approval and on every reindex**, never read live;
 * - a post that is unpublished / trashed / made private / password-protected
 *   / deleted is **revoked** (its ciphertext NULLed immediately);
 * - a post whose content changed is marked **stale** and excluded until an
 *   admin explicitly re-approves it;
 * - operator snippets carry the same lifecycle minus the WordPress hooks.
 */
final class KnowledgeIndexer {

	/**
	 * Source repository.
	 *
	 * @var KnowledgeSourceRepository
	 */
	private KnowledgeSourceRepository $repo;

	/**
	 * Audit logger, or null.
	 *
	 * @var AuditLogger|null
	 */
	private ?AuditLogger $audit;

	/**
	 * Daily re-checksum sweep cron hook.
	 */
	public const SWEEP_HOOK = 'universal_support_chat_ai_knowledge_recheck';

	/**
	 * Constructor.
	 *
	 * @param KnowledgeSourceRepository $repo  Source repository.
	 * @param AuditLogger|null          $audit Optional audit logger.
	 */
	public function __construct( KnowledgeSourceRepository $repo, ?AuditLogger $audit = null ) {
		$this->repo  = $repo;
		$this->audit = $audit;
	}

	/**
	 * Registers the WordPress content-lifecycle hooks and the daily sweep.
	 */
	public function register(): void {
		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 1 );
		add_action( 'wp_trash_post', array( $this, 'on_remove_post' ), 20, 1 );
		add_action( 'before_delete_post', array( $this, 'on_remove_post' ), 20, 1 );

		add_action( self::SWEEP_HOOK, array( $this, 'run_recheck_sweep' ) );
		add_action(
			'init',
			static function (): void {
				if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
					wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::SWEEP_HOOK );
				}
			}
		);
	}

	/**
	 * Whether a post is currently eligible to be an approved source:
	 * published and not password-protected.
	 *
	 * @param WP_Post $post Post object.
	 */
	public static function post_is_eligible( WP_Post $post ): bool {
		return 'publish' === $post->post_status && '' === (string) $post->post_password;
	}

	/**
	 * The canonical extracted plain text for a post: shortcodes stripped,
	 * blocks rendered then tag-stripped, whitespace normalised. The title is
	 * prepended so a title-only query can still match.
	 *
	 * @param WP_Post $post Post object.
	 */
	public static function canonical_text( WP_Post $post ): string {
		$raw = (string) $post->post_title . "\n\n" . (string) $post->post_content;

		return self::normalise( $raw );
	}

	/**
	 * Normalises arbitrary content to canonical plain text.
	 *
	 * @param string $raw Raw content (may contain shortcodes / blocks / HTML).
	 */
	public static function normalise( string $raw ): string {
		$text = strip_shortcodes( $raw );

		if ( function_exists( 'do_blocks' ) ) {
			$text = do_blocks( $text );
		}

		$text = wp_strip_all_tags( $text );
		$text = preg_replace( '/[ \t]+/', ' ', (string) $text );
		$text = preg_replace( '/\s*\n\s*/', "\n", (string) $text );

		return trim( (string) $text );
	}

	/**
	 * Approves (or re-approves / reindexes) a WordPress post as a source.
	 * Returns false if the post is not eligible or the vault is unavailable.
	 *
	 * @param int $post_id Post id.
	 * @param int $user_id Approving operator user id.
	 */
	public function approve_post( int $post_id, int $user_id ): bool {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! self::post_is_eligible( $post ) ) {
			return false;
		}

		$text  = self::canonical_text( $post );
		$label = (string) $post->post_title;

		try {
			$existing = $this->repo->find_by_post( $post_id );

			if ( null === $existing ) {
				$this->repo->create_approved( KnowledgeSourceRepository::TYPE_POST, $post_id, $label, $text, $user_id );
			} else {
				$this->repo->reindex( (string) $existing['source_uuid'], $label, $text );
			}
		} catch ( CredentialUnavailableException $exception ) {
			return false;
		}

		$this->audit_change( 'approved_post', array( 'post_id' => (string) $post_id ) );

		return true;
	}

	/**
	 * Creates an operator-authored snippet source.
	 *
	 * @param string $label   Snippet name.
	 * @param string $body    Snippet body.
	 * @param int    $user_id Author user id.
	 */
	public function create_snippet( string $label, string $body, int $user_id ): bool {
		$label = trim( wp_strip_all_tags( $label ) );
		$text  = self::normalise( $body );

		if ( '' === $label || '' === $text ) {
			return false;
		}

		try {
			$this->repo->create_approved( KnowledgeSourceRepository::TYPE_SNIPPET, null, $label, $text, $user_id );
		} catch ( CredentialUnavailableException $exception ) {
			return false;
		}

		$this->audit_change( 'added_snippet', array() );

		return true;
	}

	/**
	 * A source row by id (metadata only).
	 *
	 * @param int $id Row id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_source( int $id ): ?array {
		return $this->repo->find( $id );
	}

	/**
	 * Operator explicitly removes a source (hard delete).
	 *
	 * @param int $id Row id.
	 */
	public function remove( int $id ): void {
		$this->repo->delete( $id );
		$this->audit_change( 'removed', array( 'id' => (string) $id ) );
	}

	/**
	 * `save_post` handler — revoke if no longer eligible, stale if the
	 * content changed.
	 *
	 * @param int $post_id Saved post id.
	 */
	public function on_save_post( $post_id ): void {
		$row = $this->repo->find_by_post( (int) $post_id );

		if ( null === $row ) {
			return;
		}

		$post = get_post( (int) $post_id );

		if ( ! $post instanceof WP_Post || ! self::post_is_eligible( $post ) ) {
			$this->repo->revoke( (int) $row['id'] );
			$this->audit_change( 'revoked', array( 'reason' => 'ineligible' ) );

			return;
		}

		if ( KnowledgeSourceRepository::checksum( self::canonical_text( $post ) ) !== (string) $row['content_checksum'] ) {
			$this->repo->mark_stale( (int) $row['id'] );
			$this->audit_change( 'stale', array() );
		}
	}

	/**
	 * `wp_trash_post` / `before_delete_post` handler — revoke.
	 *
	 * @param int $post_id Post id.
	 */
	public function on_remove_post( $post_id ): void {
		$row = $this->repo->find_by_post( (int) $post_id );

		if ( null !== $row ) {
			$this->repo->revoke( (int) $row['id'] );
			$this->audit_change( 'revoked', array( 'reason' => 'deleted' ) );
		}
	}

	/**
	 * Daily sweep: any approved post source whose live content no longer
	 * matches its stored checksum is marked stale (a safety net for edits
	 * that bypassed `save_post`).
	 */
	public function run_recheck_sweep(): void {
		foreach ( $this->repo->approved_post_rows() as $row ) {
			$post = get_post( $row['post_id'] );

			if ( ! $post instanceof WP_Post || ! self::post_is_eligible( $post ) ) {
				$this->repo->revoke( $row['id'] );
				continue;
			}

			if ( KnowledgeSourceRepository::checksum( self::canonical_text( $post ) ) !== $row['content_checksum'] ) {
				$this->repo->mark_stale( $row['id'] );
			}
		}
	}

	/**
	 * Records `ai.knowledge_source_changed` with ids / operation only.
	 *
	 * @param string                $op      Operation marker.
	 * @param array<string, string> $context Extra safe context.
	 */
	private function audit_change( string $op, array $context ): void {
		if ( null === $this->audit ) {
			return;
		}

		$context = array_merge( array( 'op' => $op ), $context );
		$map     = array();
		foreach ( array_keys( $context ) as $key ) {
			$map[ $key ] = Classification::PUBLIC;
		}

		$user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;

		$this->audit->record(
			'ai.knowledge_source_changed',
			$user_id > 0 ? 'operator' : 'system',
			$user_id,
			$context,
			$map,
			Classification::INTERNAL
		);
	}
}
