<?php
/**
 * Conversation persistence.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Conversations;

use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;

/**
 * CRUD and status transitions for conversations. No channel identifiers.
 */
class ConversationRepository {

	/**
	 * Schema availability gate.
	 *
	 * @var SchemaHealth
	 */
	private SchemaHealth $schema_health;

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health Schema availability gate.
	 */
	public function __construct( SchemaHealth $schema_health ) {
		$this->schema_health = $schema_health;
	}

	/**
	 * Creates a conversation owned by the given WordPress user.
	 *
	 * @param int         $owner_user_id         Owner user ID.
	 * @param string|null $start_idempotency_key Optional start idempotency key.
	 */
	public function create( int $owner_user_id, ?string $start_idempotency_key = null ): ?Conversation {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		if ( null !== $start_idempotency_key && '' !== $start_idempotency_key ) {
			$existing = $this->find_by_start_idempotency_key( $start_idempotency_key );
			if ( null !== $existing ) {
				return $existing;
			}
		}

		global $wpdb;

		$table   = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		$now     = current_time( 'mysql', true );
		$uuid    = wp_generate_uuid4();
		$data    = array(
			'conversation_uuid' => $uuid,
			'owner_user_id'     => $owner_user_id,
			'status'            => ConversationStatus::NEW,
			'created_at'        => $now,
			'updated_at'        => $now,
		);
		$formats = array( '%s', '%d', '%s', '%s', '%s' );

		if ( null !== $start_idempotency_key && '' !== $start_idempotency_key ) {
			$data['start_idempotency_key'] = $start_idempotency_key;
			$formats[]                     = '%s';
		}

		$inserted = $wpdb->insert( $table, $data, $formats );

		if ( false === $inserted ) {
			if ( null !== $start_idempotency_key && '' !== $start_idempotency_key ) {
				return $this->find_by_start_idempotency_key( $start_idempotency_key );
			}

			return null;
		}

		return $this->find_by_uuid( $uuid );
	}

	/**
	 * Finds a conversation by UUID.
	 *
	 * @param string $uuid Conversation UUID.
	 */
	public function find_by_uuid( string $uuid ): ?Conversation {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE conversation_uuid = %s LIMIT 1",
				$uuid
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? Conversation::from_row( $row ) : null;
	}

	/**
	 * Finds a conversation by start idempotency key.
	 *
	 * @param string $key Start idempotency key.
	 */
	public function find_by_start_idempotency_key( string $key ): ?Conversation {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE start_idempotency_key = %s LIMIT 1",
				$key
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? Conversation::from_row( $row ) : null;
	}

	/**
	 * Finds the owner's most recent non-terminal conversation.
	 *
	 * @param int $owner_user_id Owner user ID.
	 */
	public function find_active_for_owner( int $owner_user_id ): ?Conversation {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE owner_user_id = %d AND status NOT IN (%s, %s) ORDER BY id DESC LIMIT 1",
				$owner_user_id,
				ConversationStatus::RESOLVED,
				ConversationStatus::ARCHIVED
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? Conversation::from_row( $row ) : null;
	}

	/**
	 * Applies a validated status transition.
	 *
	 * @param Conversation $conversation Conversation snapshot.
	 * @param string       $to           Target status.
	 */
	public function transition( Conversation $conversation, string $to ): ?Conversation {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		if ( ! ConversationStatus::is_valid_transition( $conversation->status(), $to ) ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		$now   = current_time( 'mysql', true );
		$data  = array(
			'status'     => $to,
			'updated_at' => $now,
		);

		if ( ConversationStatus::RESOLVED === $to ) {
			$data['resolved_at'] = $now;
		}

		$updated = $wpdb->update(
			$table,
			$data,
			array( 'id' => $conversation->id() ),
			null,
			array( '%d' )
		);

		if ( false === $updated ) {
			return null;
		}

		return $this->find_by_uuid( $conversation->uuid() );
	}

	/**
	 * Inactive open/waiting conversations for retention.
	 *
	 * @param int $inactive_days Inactivity threshold in days.
	 * @param int $limit         Max rows.
	 *
	 * @return array<int, Conversation>
	 */
	public function find_inactive_open( int $inactive_days, int $limit = 100 ): array {
		if ( ! $this->schema_health->is_available() ) {
			return array();
		}

		global $wpdb;

		$table        = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		$cutoff       = gmdate( 'Y-m-d H:i:s', time() - ( $inactive_days * DAY_IN_SECONDS ) );
		$actives      = array(
			ConversationStatus::NEW,
			ConversationStatus::OPEN,
			ConversationStatus::WAITING_FOR_VISITOR,
			ConversationStatus::WAITING_FOR_OPERATOR,
		);
		$placeholders = implode( ',', array_fill( 0, count( $actives ), '%s' ) );
		$params       = array_merge( $actives, array( $cutoff, $limit ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- dynamic IN() placeholders with splat.
		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE status IN ({$placeholders}) AND updated_at < %s ORDER BY id ASC LIMIT %d",
			...$params
		);
		// phpcs:enable

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( static fn( array $row ) => Conversation::from_row( $row ), $rows );
	}

	/**
	 * Resolved conversations awaiting archival.
	 *
	 * @param int $limit Max rows.
	 *
	 * @return array<int, Conversation>
	 */
	public function find_resolved( int $limit = 100 ): array {
		if ( ! $this->schema_health->is_available() ) {
			return array();
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY id ASC LIMIT %d",
				ConversationStatus::RESOLVED,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( static fn( array $row ) => Conversation::from_row( $row ), $rows );
	}

	/**
	 * Archived conversations older than a cutoff.
	 *
	 * @param string $cutoff UTC mysql datetime.
	 * @param int    $limit  Max rows.
	 *
	 * @return array<int, Conversation>
	 */
	public function find_archived_before( string $cutoff, int $limit = 100 ): array {
		if ( ! $this->schema_health->is_available() ) {
			return array();
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s AND updated_at < %s ORDER BY id ASC LIMIT %d",
				ConversationStatus::ARCHIVED,
				$cutoff,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( static fn( array $row ) => Conversation::from_row( $row ), $rows );
	}


	/**
	 * Finds a conversation by primary key.
	 *
	 * @param int $id Conversation primary key.
	 */
	public function find_by_id( int $id ): ?Conversation {
		if ( ! $this->schema_health->is_available() || $id <= 0 ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
				$id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? Conversation::from_row( $row ) : null;
	}

	/**
	 * Lists conversations for the Hub inbox (newest activity first).
	 *
	 * @param string|null $status Optional status filter.
	 * @param int         $page   1-based page.
	 * @param int         $per_page Page size.
	 *
	 * @return array{items: array<int, Conversation>, total: int}
	 */
	public function list_for_hub( ?string $status = null, int $page = 1, int $per_page = 20 ): array {
		if ( ! $this->schema_health->is_available() ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		global $wpdb;

		$table    = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		$page     = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		if ( null !== $status && '' !== $status ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE status = %s",
					$status
				)
			);
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status = %s ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d",
					$status,
					$per_page,
					$offset
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d",
					$per_page,
					$offset
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		return array(
			'items' => array_map( static fn( array $row ) => Conversation::from_row( $row ), $rows ),
			'total' => $total,
		);
	}

	/**
	 * Deletes a conversation by primary key.
	 *
	 * @param int $id Conversation primary key.
	 */
	public function delete_by_id( int $id ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;

		return false !== $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Bumps updated_at without changing status.
	 *
	 * @param Conversation $conversation Conversation snapshot.
	 */
	public function touch( Conversation $conversation ): void {
		if ( ! $this->schema_health->is_available() ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;
		$wpdb->update(
			$table,
			array( 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => $conversation->id() ),
			array( '%s' ),
			array( '%d' )
		);
	}
}
