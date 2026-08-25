<?php
/**
 * Hub conversation inbox list.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Administration\Conversations;

use UniversalSupportChat\Administration\Hub\HubPage;
use UniversalSupportChat\Conversations\ConversationRepository;
use UniversalSupportChat\Conversations\ConversationStatus;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;
use UniversalSupportChat\Persistence\SchemaHealth;

/**
 * Lists conversations for authorized operators (no plaintext bodies).
 */
final class ConversationInboxPage {

	private const PER_PAGE = 20;

	/**
	 * Schema availability gate.
	 *
	 * @var SchemaHealth
	 */
	private SchemaHealth $schema_health;

	/**
	 * Conversation repository.
	 *
	 * @var ConversationRepository
	 */
	private ConversationRepository $conversations;

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth           $schema_health Schema health.
	 * @param ConversationRepository $conversations Conversations.
	 */
	public function __construct( SchemaHealth $schema_health, ConversationRepository $conversations ) {
		$this->schema_health = $schema_health;
		$this->conversations = $conversations;
	}

	/**
	 * Renders the inbox table.
	 */
	public function render(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			return;
		}

		if ( ! $this->schema_health->is_available() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Conversation schema is unavailable.', 'universal-support-chat' ) . '</p></div>';
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';
		if ( '' !== $status && ! in_array( $status, ConversationStatus::all(), true ) ) {
			$status = '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.
		$page = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

		$result = $this->conversations->list_for_hub( '' === $status ? null : $status, $page, self::PER_PAGE );
		$items  = $result['items'];
		$total  = $result['total'];

		$this->render_filters( $status );

		echo '<table class="widefat striped usc-hub-inbox">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Conversation', 'universal-support-chat' ) . '</th>';
		echo '<th>' . esc_html__( 'Visitor', 'universal-support-chat' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'universal-support-chat' ) . '</th>';
		echo '<th>' . esc_html__( 'Updated', 'universal-support-chat' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( array() === $items ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No conversations yet.', 'universal-support-chat' ) . '</td></tr>';
		}

		foreach ( $items as $conversation ) {
			$url   = admin_url( 'admin.php?page=' . HubPage::SLUG . '&conversation_id=' . $conversation->id() );
			$owner = get_userdata( $conversation->owner_user_id() );
			$label = $owner ? $owner->user_login : ( '#' . $conversation->owner_user_id() );

			echo '<tr>';
			echo '<td><a href="' . esc_url( $url ) . '"><code>' . esc_html( $conversation->uuid() ) . '</code></a></td>';
			echo '<td>' . esc_html( $label ) . '</td>';
			echo '<td>' . esc_html( $conversation->status() ) . '</td>';
			echo '<td>' . esc_html( $conversation->updated_at() ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		$total_pages = (int) ceil( $total / self::PER_PAGE );
		if ( $total_pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'current'   => $page,
						'total'     => $total_pages,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
					)
				)
			);
			echo '</div></div>';
		}
	}

	/**
	 * Renders status filter links.
	 *
	 * @param string $current Current status filter.
	 */
	private function render_filters( string $current ): void {
		$base = admin_url( 'admin.php?page=' . HubPage::SLUG );

		echo '<ul class="subsubsub usc-hub-filters">';
		echo '<li><a href="' . esc_url( $base ) . '"' . ( '' === $current ? ' class="current"' : '' ) . '>' . esc_html__( 'All', 'universal-support-chat' ) . '</a> | </li>';

		$statuses = ConversationStatus::all();
		$last     = end( $statuses );
		foreach ( $statuses as $status ) {
			$url = add_query_arg( 'status', $status, $base );
			echo '<li><a href="' . esc_url( $url ) . '"' . ( $current === $status ? ' class="current"' : '' ) . '>' . esc_html( $status ) . '</a>' . ( $status === $last ? '' : ' | ' ) . '</li>';
		}
		echo '</ul><br class="clear" />';
	}
}
