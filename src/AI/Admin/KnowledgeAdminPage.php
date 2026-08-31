<?php
/**
 * "AI Knowledge" Hub submenu (ADR-0018 §9, SC-M07).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\AI\Admin;

use UniversalSupportChat\AI\Knowledge\KnowledgeIndexer;
use UniversalSupportChat\AI\Knowledge\KnowledgeSourceRepository;
use UniversalSupportChat\Administration\Hub\HubPage;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;

/**
 * Operators approve published, non-password-protected posts/pages and
 * author support snippets here. Every write goes through the nonce +
 * `MANAGE` gated {@see KnowledgeAdminAction}; this class only renders.
 *
 * The page shows source labels and status counts — never a decrypted
 * snapshot, never a visitor identifier.
 */
final class KnowledgeAdminPage {

	public const SLUG = 'universal-support-chat-ai-knowledge';

	/**
	 * Source repository.
	 *
	 * @var KnowledgeSourceRepository
	 */
	private KnowledgeSourceRepository $repo;

	/**
	 * Constructor.
	 *
	 * @param KnowledgeSourceRepository $repo Source repository.
	 */
	public function __construct( KnowledgeSourceRepository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Registers the submenu.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	/**
	 * Adds the submenu under the Support Chat menu.
	 */
	public function add_menu(): void {
		add_submenu_page(
			HubPage::SLUG,
			__( 'AI Knowledge', 'universal-support-chat' ),
			__( 'AI Knowledge', 'universal-support-chat' ),
			CapabilityRegistrar::MANAGE,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'universal-support-chat' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'AI Knowledge', 'universal-support-chat' ) . '</h1>';
		echo '<p>' . esc_html__( 'The AI assistant answers only from the approved content below. Editing an approved post marks it “needs re-approval” until you approve it again; unpublishing, trashing, or password-protecting it revokes it automatically.', 'universal-support-chat' ) . '</p>';

		settings_errors( 'usc_ai_knowledge' );

		$this->render_sources_table();
		$this->render_approve_post_form();
		$this->render_snippet_form();

		echo '</div>';
	}

	/**
	 * Renders the current source list.
	 */
	private function render_sources_table(): void {
		$rows = $this->repo->all_for_admin();

		echo '<h2>' . esc_html__( 'Approved content', 'universal-support-chat' ) . '</h2>';

		if ( array() === $rows ) {
			echo '<p><em>' . esc_html__( 'Nothing approved yet.', 'universal-support-chat' ) . '</em></p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Label', 'universal-support-chat' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'universal-support-chat' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'universal-support-chat' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'universal-support-chat' ) . '</th>';
		echo '</tr></thead><tbody>';

		$status_labels = array(
			KnowledgeSourceRepository::STATUS_APPROVED => __( 'approved', 'universal-support-chat' ),
			KnowledgeSourceRepository::STATUS_STALE    => __( 'needs re-approval', 'universal-support-chat' ),
			KnowledgeSourceRepository::STATUS_REVOKED  => __( 'revoked', 'universal-support-chat' ),
		);

		foreach ( $rows as $row ) {
			$id     = (int) $row['id'];
			$status = (string) $row['status'];

			echo '<tr>';
			echo '<td>' . esc_html( (string) $row['label'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['source_type'] ) . '</td>';
			echo '<td>' . esc_html( $status_labels[ $status ] ?? $status ) . '</td>';
			echo '<td>';

			if ( KnowledgeSourceRepository::TYPE_POST === $row['source_type'] && KnowledgeSourceRepository::STATUS_REVOKED !== $status ) {
				$this->row_button( 'reindex', $id, __( 'Re-approve / reindex', 'universal-support-chat' ), 'button' );
			}

			$this->row_button( 'remove', $id, __( 'Remove', 'universal-support-chat' ), 'button button-link-delete' );
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Renders one row action button as its own tiny form.
	 *
	 * @param string $op    Operation.
	 * @param int    $id    Source id.
	 * @param string $label Button label.
	 * @param string $button_class Button class.
	 */
	private function row_button( string $op, int $id, string $label, string $button_class ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		wp_nonce_field( KnowledgeAdminAction::NONCE );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( KnowledgeAdminAction::ACTION ) );
		printf( '<input type="hidden" name="knowledge_op" value="%s" />', esc_attr( $op ) );
		printf( '<input type="hidden" name="source_id" value="%d" />', (int) $id );
		printf( '<button type="submit" class="%s">%s</button> ', esc_attr( $button_class ), esc_html( $label ) );
		echo '</form>';
	}

	/**
	 * Renders the "approve a post/page" form.
	 */
	private function render_approve_post_form(): void {
		$posts = get_posts(
			array(
				'post_type'        => array( 'post', 'page' ),
				'post_status'      => 'publish',
				'numberposts'      => 100,
				'has_password'     => false,
				'suppress_filters' => false,
			)
		);

		echo '<h2>' . esc_html__( 'Approve a published post or page', 'universal-support-chat' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( KnowledgeAdminAction::NONCE );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( KnowledgeAdminAction::ACTION ) );
		echo '<input type="hidden" name="knowledge_op" value="approve_post" />';
		echo '<select name="post_id">';
		echo '<option value="0">' . esc_html__( '— select —', 'universal-support-chat' ) . '</option>';
		foreach ( $posts as $post ) {
			printf( '<option value="%d">%s</option>', (int) $post->ID, esc_html( get_the_title( $post ) ) );
		}
		echo '</select> ';
		printf( '<button type="submit" class="button button-primary">%s</button>', esc_html__( 'Approve', 'universal-support-chat' ) );
		echo '</form>';
	}

	/**
	 * Renders the "add a snippet" form.
	 */
	private function render_snippet_form(): void {
		echo '<h2>' . esc_html__( 'Add a support snippet', 'universal-support-chat' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( KnowledgeAdminAction::NONCE );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( KnowledgeAdminAction::ACTION ) );
		echo '<input type="hidden" name="knowledge_op" value="add_snippet" />';
		echo '<p><label>' . esc_html__( 'Name', 'universal-support-chat' ) . '<br /><input type="text" class="regular-text" name="snippet_label" maxlength="191" /></label></p>';
		echo '<p><label>' . esc_html__( 'Text', 'universal-support-chat' ) . '<br /><textarea class="large-text" rows="4" name="snippet_body" maxlength="8000"></textarea></label></p>';
		printf( '<button type="submit" class="button button-primary">%s</button>', esc_html__( 'Save snippet', 'universal-support-chat' ) );
		echo '</form>';
	}
}
