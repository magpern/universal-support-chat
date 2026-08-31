<?php
/**
 * Hub conversation-detail AI panel (ADR-0018 §6).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\AI\Admin;

use UniversalSupportChat\AI\Knowledge\KnowledgeSourceRepository;
use UniversalSupportChat\AI\Turn\AiTurnRepository;
use UniversalSupportChat\Conversations\Conversation;

/**
 * A read-only panel on the conversation detail page. It renders **only**
 * enum labels, counts, token totals, provider error classes, and knowledge
 * source labels — never a prompt, an answer body, a key, an identifier, a
 * timestamp, or a raw provider error (ADR-0018 §6, §11; ADR-0015 §3).
 *
 * When there is no AI turn for the conversation the panel is not rendered.
 */
final class HubAiPanel {

	/**
	 * Constructor.
	 *
	 * @param AiTurnRepository          $turns     Turn repository.
	 * @param KnowledgeSourceRepository $knowledge Knowledge source repository.
	 */
	public function __construct(
		private readonly AiTurnRepository $turns,
		private readonly KnowledgeSourceRepository $knowledge
	) {}

	/**
	 * Renders the panel for a conversation.
	 *
	 * @param Conversation $conversation Conversation.
	 */
	public function render( Conversation $conversation ): void {
		$row = $this->turns->latest_for_conversation( $conversation->id() );

		if ( null === $row ) {
			return;
		}

		echo '<h2>' . esc_html__( 'AI assistant', 'universal-support-chat' ) . '</h2>';

		if ( null === $conversation->assigned_operator_id() && $this->turns->has_handoff( $conversation->id() ) ) {
			// handed off, no operator yet
			$state = __( 'handed off — waiting for an operator', 'universal-support-chat' );
		} elseif ( null !== $conversation->assigned_operator_id() ) {
			$state = __( 'an operator has taken over', 'universal-support-chat' );
		} else {
			$state = __( 'active', 'universal-support-chat' );
		}

		$turn_count = $this->turns->count_for_conversation( $conversation->id() );
		$prompt     = (int) ( $row['prompt_tokens'] ?? 0 );
		$completion = (int) ( $row['completion_tokens'] ?? 0 );

		echo '<table class="widefat striped" style="max-width:720px"><tbody>';
		$this->kv( __( 'State', 'universal-support-chat' ), $state );
		$this->kv( __( 'AI turns in this conversation', 'universal-support-chat' ), (string) $turn_count );
		$this->kv( __( 'Last turn status', 'universal-support-chat' ), (string) $row['status'] );

		if ( null !== ( $row['outcome'] ?? null ) ) {
			$this->kv( __( 'Last outcome', 'universal-support-chat' ), (string) $row['outcome'] );
		}
		if ( null !== ( $row['handoff_reason'] ?? null ) ) {
			$this->kv( __( 'Handoff reason', 'universal-support-chat' ), (string) $row['handoff_reason'] );
		}
		if ( null !== ( $row['finish_reason'] ?? null ) ) {
			$this->kv( __( 'Finish reason', 'universal-support-chat' ), (string) $row['finish_reason'] );
		}
		if ( null !== ( $row['provider_error_class'] ?? null ) ) {
			$this->kv( __( 'Last provider error class', 'universal-support-chat' ), (string) $row['provider_error_class'] );
		}

		$this->kv( __( 'Tool calls', 'universal-support-chat' ), '0' );
		$this->kv( __( 'Tokens (prompt / completion)', 'universal-support-chat' ), $prompt . ' / ' . $completion );
		echo '</tbody></table>';

		$this->render_sources( (string) ( $row['source_ids'] ?? '' ), (string) ( $row['source_checksums'] ?? '' ) );

		if ( null === $conversation->assigned_operator_id() ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( TakeoverAction::NONCE );
			printf( '<input type="hidden" name="action" value="%s" />', esc_attr( TakeoverAction::ACTION ) );
			printf( '<input type="hidden" name="conversation_id" value="%d" />', (int) $conversation->id() );
			printf(
				'<button type="submit" class="button button-primary">%s</button>',
				esc_html__( 'Take over from AI', 'universal-support-chat' )
			);
			echo ' <span class="description">' . esc_html__( 'Claims this conversation and stops any queued AI replies.', 'universal-support-chat' ) . '</span>';
			echo '</form>';
		}
	}

	/**
	 * Renders the provenance list: each source id resolved to its current
	 * label, with a "same text / content changed since this turn" flag.
	 *
	 * @param string $source_ids       Comma-joined ids.
	 * @param string $source_checksums Comma-joined checksum prefixes.
	 */
	private function render_sources( string $source_ids, string $source_checksums ): void {
		$ids = array_values( array_filter( array_map( 'intval', explode( ',', $source_ids ) ) ) );

		if ( array() === $ids ) {
			return;
		}

		$checksums = array_values( array_filter( explode( ',', $source_checksums ) ) );
		$labels    = $this->knowledge->labels_for_ids( $ids );

		echo '<h3>' . esc_html__( 'Knowledge sources used for the last answer', 'universal-support-chat' ) . '</h3>';
		echo '<ul>';

		foreach ( $ids as $index => $id ) {
			$label = $labels[ $id ] ?? __( '(removed)', 'universal-support-chat' );
			$row   = $this->knowledge->find( $id );

			$flag = '';
			if ( null !== $row && isset( $checksums[ $index ] ) ) {
				$current = substr( (string) $row['content_checksum'], 0, strlen( $checksums[ $index ] ) );
				$flag    = $current === $checksums[ $index ]
					? __( 'same text', 'universal-support-chat' )
					: __( 'content changed since this turn', 'universal-support-chat' );
			}

			echo '<li>' . esc_html( $label ) . ( '' !== $flag ? ' — <em>' . esc_html( $flag ) . '</em>' : '' ) . '</li>';
		}

		echo '</ul>';
	}

	/**
	 * Prints one key/value row.
	 *
	 * @param string $key   Label.
	 * @param string $value Value.
	 */
	private function kv( string $key, string $value ): void {
		echo '<tr><th>' . esc_html( $key ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}
}
