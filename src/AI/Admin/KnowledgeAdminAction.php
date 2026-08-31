<?php
/**
 * "AI Knowledge" admin-post write action (ADR-0018 §9, SC-M07).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\AI\Admin;

use UniversalSupportChat\AI\Knowledge\KnowledgeIndexer;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;

/**
 * The single write path for the approved-knowledge allow-list. Nonce +
 * `MANAGE` gated. Operations: `approve_post`, `add_snippet`, `reindex`,
 * `remove`. All content extraction / encryption / revoke semantics live in
 * {@see KnowledgeIndexer}.
 */
final class KnowledgeAdminAction {

	public const ACTION = 'universal_support_chat_ai_knowledge';
	public const NONCE  = 'usc_ai_knowledge';

	/**
	 * Indexer.
	 *
	 * @var KnowledgeIndexer
	 */
	private KnowledgeIndexer $indexer;

	/**
	 * Constructor.
	 *
	 * @param KnowledgeIndexer $indexer Indexer.
	 */
	public function __construct( KnowledgeIndexer $indexer ) {
		$this->indexer = $indexer;
	}

	/**
	 * Registers the admin-post hook.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Handles a submission.
	 */
	public function handle(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage AI knowledge.', 'universal-support-chat' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified immediately above.
		$op      = isset( $_POST['knowledge_op'] ) ? sanitize_key( wp_unslash( (string) $_POST['knowledge_op'] ) ) : '';
		$user_id = get_current_user_id();

		switch ( $op ) {
			case 'approve_post':
				$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
				$ok      = $post_id > 0 && $this->indexer->approve_post( $post_id, $user_id );
				$this->redirect( $ok ? 'ai_source_approved' : 'ai_source_error' );
				break;

			case 'reindex':
				$source_id = isset( $_POST['source_id'] ) ? absint( wp_unslash( $_POST['source_id'] ) ) : 0;
				$row       = $source_id > 0 ? $this->indexer_repo_find( $source_id ) : null;
				$ok        = null !== $row
					&& null !== $row['post_id']
					&& $this->indexer->approve_post( (int) $row['post_id'], $user_id );
				$this->redirect( $ok ? 'ai_source_approved' : 'ai_source_error' );
				break;

			case 'add_snippet':
				$label = isset( $_POST['snippet_label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['snippet_label'] ) ) : '';
				$body  = isset( $_POST['snippet_body'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['snippet_body'] ) ) : '';
				$ok    = $this->indexer->create_snippet( $label, $body, $user_id );
				$this->redirect( $ok ? 'ai_snippet_added' : 'ai_source_error' );
				break;

			case 'remove':
				$source_id = isset( $_POST['source_id'] ) ? absint( wp_unslash( $_POST['source_id'] ) ) : 0;
				if ( $source_id > 0 ) {
					$this->indexer->remove( $source_id );
				}
				$this->redirect( 'ai_source_removed' );
				break;

			default:
				$this->redirect( 'ai_source_error' );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Looks up a source row for the reindex operation. Kept as a seam so the
	 * action does not itself depend on the repository type.
	 *
	 * @param int $source_id Source id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function indexer_repo_find( int $source_id ): ?array {
		return $this->indexer->find_source( $source_id );
	}

	/**
	 * Redirects back to the AI Knowledge page with a notice code.
	 *
	 * @param string $notice Notice code.
	 */
	private function redirect( string $notice ): void {
		wp_safe_redirect(
			add_query_arg( 'usc_notice', $notice, admin_url( 'admin.php?page=' . KnowledgeAdminPage::SLUG ) )
		);
		exit;
	}
}
