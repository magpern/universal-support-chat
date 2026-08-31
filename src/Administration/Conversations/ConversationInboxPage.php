<?php
/**
 * Hub conversation inbox list.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Administration\Conversations;

use UniversalSupportChat\Administration\Hub\HubPage;
use UniversalSupportChat\Availability\AvailabilityOverride;
use UniversalSupportChat\Availability\AvailabilityService;
use UniversalSupportChat\Availability\Admin\OverrideAction;
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
	 * The Hub "Waiting" pseudo-filter slug (ADR-0017 §9).
	 */
	private const FILTER_WAITING = 'waiting';

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
	 * Availability service (Hub override control + status), or null.
	 *
	 * @var AvailabilityService|null
	 */
	private ?AvailabilityService $availability;

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth             $schema_health Schema health.
	 * @param ConversationRepository   $conversations Conversations.
	 * @param AvailabilityService|null $availability   Optional availability service.
	 */
	public function __construct( SchemaHealth $schema_health, ConversationRepository $conversations, ?AvailabilityService $availability = null ) {
		$this->schema_health = $schema_health;
		$this->conversations = $conversations;
		$this->availability  = $availability;
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

		$this->render_notice();
		$this->render_override_control();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';
		if ( '' !== $status && self::FILTER_WAITING !== $status && ! in_array( $status, ConversationStatus::all(), true ) ) {
			$status = '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.
		$page = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

		if ( self::FILTER_WAITING === $status ) {
			$result = $this->conversations->list_waiting( $page, self::PER_PAGE );
		} else {
			$result = $this->conversations->list_for_hub( '' === $status ? null : $status, $page, self::PER_PAGE );
		}
		$items = $result['items'];
		$total = $result['total'];

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

		$waiting_url = add_query_arg( 'status', self::FILTER_WAITING, $base );
		echo '<li><a href="' . esc_url( $waiting_url ) . '"' . ( self::FILTER_WAITING === $current ? ' class="current"' : '' ) . '>' . esc_html__( 'Waiting', 'universal-support-chat' ) . '</a> | </li>';

		$statuses = ConversationStatus::all();
		$last     = end( $statuses );
		foreach ( $statuses as $status ) {
			$url = add_query_arg( 'status', $status, $base );
			echo '<li><a href="' . esc_url( $url ) . '"' . ( $current === $status ? ' class="current"' : '' ) . '>' . esc_html( $status ) . '</a>' . ( $status === $last ? '' : ' | ' ) . '</li>';
		}
		echo '</ul><br class="clear" />';
	}

	/**
	 * Renders the availability notice for a completed override action.
	 */
	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, display-only mapping.
		$notice = isset( $_GET['usc_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['usc_notice'] ) ) : '';

		$messages = array(
			'override_set'     => array( 'success', __( 'Manual availability override saved.', 'universal-support-chat' ) ),
			'override_cleared' => array( 'success', __( 'Manual availability override cleared — availability is back on the schedule.', 'universal-support-chat' ) ),
			'override_invalid' => array( 'error', __( 'That override could not be saved. Choose Force online or Force offline, and an expiry in the future (or leave it blank).', 'universal-support-chat' ) ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $notice ][0] ),
			esc_html( $messages[ $notice ][1] )
		);
	}

	/**
	 * Renders the compact manual-availability control (ADR-0017 §10). The
	 * operator's daily surface for `Automatic` / `Force online` /
	 * `Force offline`, with an optional expiry.
	 */
	private function render_override_control(): void {
		if ( null === $this->availability ) {
			return;
		}

		$state    = $this->availability->resolve_state()->value;
		$mode     = $this->availability->current_mode();
		$override = $this->availability->current_override();

		$mode_label = array(
			AvailabilityService::MODE_AUTOMATIC      => __( 'Automatic (follow the schedule)', 'universal-support-chat' ),
			AvailabilityOverride::MODE_FORCE_ONLINE  => __( 'Forced online', 'universal-support-chat' ),
			AvailabilityOverride::MODE_FORCE_OFFLINE => __( 'Forced offline', 'universal-support-chat' ),
		);

		echo '<div class="usc-availability-control notice notice-info" style="padding:12px;">';
		printf(
			'<p style="margin-top:0;"><strong>%s</strong> %s &mdash; <strong>%s</strong> %s</p>',
			esc_html__( 'Visitors currently see:', 'universal-support-chat' ),
			esc_html( 'available' === $state ? __( 'Online', 'universal-support-chat' ) : __( 'Offline', 'universal-support-chat' ) ),
			esc_html__( 'Mode:', 'universal-support-chat' ),
			esc_html( $mode_label[ $mode ] ?? $mode )
		);

		if ( null !== $override ) {
			$expiry = $override->expires_at();
			printf(
				'<p>%s</p>',
				null === $expiry
					? esc_html__( 'This override stays until you clear it.', 'universal-support-chat' )
					: sprintf(
						/* translators: %s: local date/time. */
						esc_html__( 'This override expires at %s.', 'universal-support-chat' ),
						esc_html( wp_date( 'Y-m-d H:i', $expiry ) )
					)
			);
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">';
		wp_nonce_field( OverrideAction::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( OverrideAction::ACTION ) . '" />';
		echo '<select name="override_mode">';
		echo '<option value="' . esc_attr( AvailabilityOverride::MODE_FORCE_OFFLINE ) . '">' . esc_html__( 'Force offline', 'universal-support-chat' ) . '</option>';
		echo '<option value="' . esc_attr( AvailabilityOverride::MODE_FORCE_ONLINE ) . '">' . esc_html__( 'Force online', 'universal-support-chat' ) . '</option>';
		echo '</select>';
		echo '<label>' . esc_html__( 'Until (optional):', 'universal-support-chat' ) . ' <input type="datetime-local" name="override_expires_at" /></label>';
		echo '<button type="submit" name="override_op" value="set" class="button button-primary">' . esc_html__( 'Apply override', 'universal-support-chat' ) . '</button>';
		if ( null !== $override ) {
			echo '<button type="submit" name="override_op" value="clear" class="button">' . esc_html__( 'Clear override', 'universal-support-chat' ) . '</button>';
		}
		echo '</form>';
		echo '</div>';
	}
}
