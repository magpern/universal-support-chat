<?php
/**
 * Conversation message persistence.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Conversations;

use UniversalSupportChat\Core\Security\CredentialState;
use UniversalSupportChat\Core\Security\CredentialUnavailableException;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;

/**
 * Encrypts on write and decrypts on read via CredentialVault.
 */
class MessageRepository {

	/**
	 * Schema availability gate.
	 *
	 * @var SchemaHealth
	 */
	private SchemaHealth $schema_health;

	/**
	 * Support Chat vault.
	 *
	 * @var CredentialVault
	 */
	private CredentialVault $vault;

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth    $schema_health Schema availability gate.
	 * @param CredentialVault $vault         Support Chat vault.
	 */
	public function __construct( SchemaHealth $schema_health, CredentialVault $vault ) {
		$this->schema_health = $schema_health;
		$this->vault         = $vault;
	}

	/**
	 * Authenticated encryption context for a message UUID.
	 *
	 * @param string $message_uuid Message UUID.
	 */
	private function context( string $message_uuid ): string {
		return 'conversation_message:' . $message_uuid;
	}

	/**
	 * Creates an encrypted message.
	 *
	 * @param int         $conversation_id Conversation primary key.
	 * @param string      $direction       Message direction.
	 * @param string      $plaintext_body  Plaintext body.
	 * @param string      $delivery_state  Delivery state.
	 * @param string|null $idempotency_key Optional idempotency key.
	 */
	public function create(
		int $conversation_id,
		string $direction,
		string $plaintext_body,
		string $delivery_state = 'stored',
		?string $idempotency_key = null
	): ?ConversationMessage {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		if ( null !== $idempotency_key && '' !== $idempotency_key ) {
			$existing = $this->find_by_idempotency_key( $conversation_id, $idempotency_key );
			if ( null !== $existing ) {
				return $existing;
			}
		}

		$message_uuid = wp_generate_uuid4();

		try {
			$ciphertext = $this->vault->encrypt( $plaintext_body, $this->context( $message_uuid ) );
		} catch ( CredentialUnavailableException $exception ) {
			return null;
		}

		global $wpdb;

		$table   = $wpdb->prefix . Migrator::CONVERSATION_MESSAGES_TABLE;
		$now     = current_time( 'mysql', true );
		$data    = array(
			'conversation_id' => $conversation_id,
			'message_uuid'    => $message_uuid,
			'direction'       => $direction,
			'body_ciphertext' => $ciphertext,
			'delivery_state'  => $delivery_state,
			'created_at'      => $now,
		);
		$formats = array( '%d', '%s', '%s', '%s', '%s', '%s' );

		if ( null !== $idempotency_key && '' !== $idempotency_key ) {
			$data['idempotency_key'] = $idempotency_key;
			$formats[]               = '%s';
		}

		$inserted = $wpdb->insert( $table, $data, $formats );

		if ( false === $inserted ) {
			if ( null !== $idempotency_key && '' !== $idempotency_key ) {
				return $this->find_by_idempotency_key( $conversation_id, $idempotency_key );
			}
			return null;
		}

		return $this->find_by_uuid( $message_uuid );
	}

	/**
	 * Inserts a message row with an explicit historical `created_at` and a
	 * pre-derived idempotency key, encrypting the plaintext body through
	 * this plugin's own vault immediately before persistence — or, if
	 * `$plaintext_body` is `null` (the legacy row's own body was already
	 * retention-nulled at the source), inserting a `NULL` ciphertext
	 * directly, preserving that same "body no longer available" state
	 * rather than encrypting an empty string. `delivery_state` is always
	 * Support Chat's own `'stored'` constant, never a copy of the legacy
	 * transport state (sc-m03-wp3-wp4 plan §4.1). Used only by the SC-M03
	 * legacy migration engine.
	 *
	 * @param int         $conversation_id Target conversation primary key.
	 * @param string      $direction       Copied 1:1 from the legacy row.
	 * @param string|null $plaintext_body  Decrypted legacy plaintext, or null if legacy body was already retention-nulled.
	 * @param string      $idempotency_key A pre-derived, collision-safe target key.
	 * @param string      $created_at      Preserved legacy `created_at`.
	 *
	 * @return ConversationMessage|null Null if the schema/key is unavailable or the write failed.
	 */
	public function import_legacy(
		int $conversation_id,
		string $direction,
		?string $plaintext_body,
		string $idempotency_key,
		string $created_at
	): ?ConversationMessage {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		$message_uuid = wp_generate_uuid4();
		$ciphertext   = null;

		if ( null !== $plaintext_body ) {
			try {
				$ciphertext = $this->vault->encrypt( $plaintext_body, $this->context( $message_uuid ) );
			} catch ( CredentialUnavailableException $exception ) {
				return null;
			}
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATION_MESSAGES_TABLE;

		$inserted = $wpdb->insert(
			$table,
			array(
				'conversation_id' => $conversation_id,
				'message_uuid'    => $message_uuid,
				'direction'       => $direction,
				'body_ciphertext' => $ciphertext,
				'delivery_state'  => 'stored',
				'idempotency_key' => $idempotency_key,
				'created_at'      => $created_at,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return null;
		}

		return $this->find_by_uuid( $message_uuid );
	}

	/**
	 * Finds a message by UUID.
	 *
	 * @param string $message_uuid Message UUID.
	 */
	public function find_by_uuid( string $message_uuid ): ?ConversationMessage {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATION_MESSAGES_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE message_uuid = %s LIMIT 1",
				$message_uuid
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Finds a message by per-conversation idempotency key.
	 *
	 * @param int    $conversation_id Conversation primary key.
	 * @param string $key             Idempotency key.
	 */
	public function find_by_idempotency_key( int $conversation_id, string $key ): ?ConversationMessage {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATION_MESSAGES_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE conversation_id = %d AND idempotency_key = %s LIMIT 1",
				$conversation_id,
				$key
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Lists visitor-visible messages after a cursor.
	 *
	 * @param int $conversation_id Conversation primary key.
	 * @param int $after_id        Exclusive id cursor.
	 * @param int $limit           Max rows.
	 *
	 * @return array<int, ConversationMessage>
	 */
	public function list_for_conversation( int $conversation_id, int $after_id = 0, int $limit = 100 ): array {
		if ( ! $this->schema_health->is_available() ) {
			return array();
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATION_MESSAGES_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE conversation_id = %d AND id > %d AND body_ciphertext IS NOT NULL ORDER BY id ASC LIMIT %d",
				$conversation_id,
				$after_id,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$message = $this->hydrate( $row );
			if ( null !== $message->plaintext_body() ) {
				$out[] = $message;
			}
		}

		return $out;
	}

	/**
	 * Nulls ciphertext for retention body expiry.
	 *
	 * @param int $conversation_id Conversation primary key.
	 */
	public function null_bodies_for_conversation( int $conversation_id ): int {
		if ( ! $this->schema_health->is_available() ) {
			return 0;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATION_MESSAGES_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET body_ciphertext = NULL WHERE conversation_id = %d AND body_ciphertext IS NOT NULL",
				$conversation_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return false === $updated ? 0 : (int) $updated;
	}

	/**
	 * Deletes all messages for a conversation.
	 *
	 * @param int $conversation_id Conversation primary key.
	 */
	public function delete_for_conversation( int $conversation_id ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATION_MESSAGES_TABLE;

		return false !== $wpdb->delete( $table, array( 'conversation_id' => $conversation_id ), array( '%d' ) );
	}

	/**
	 * Hydrates and decrypts a message row.
	 *
	 * @param array<string, mixed> $row Database row.
	 */
	private function hydrate( array $row ): ConversationMessage {
		$plaintext = null;
		$cipher    = $row['body_ciphertext'] ?? null;

		if ( is_string( $cipher ) && '' !== $cipher ) {
			$result = $this->vault->decrypt( $cipher, $this->context( (string) $row['message_uuid'] ) );
			if ( CredentialState::AVAILABLE === $result->state() ) {
				$plaintext = $result->plaintext();
			}
		}

		$idempotency = $row['idempotency_key'] ?? null;
		$key         = ( is_string( $idempotency ) && '' !== $idempotency ) ? $idempotency : null;

		return new ConversationMessage(
			(int) $row['id'],
			(int) $row['conversation_id'],
			(string) $row['message_uuid'],
			(string) $row['direction'],
			$plaintext,
			(string) $row['delivery_state'],
			(string) $row['created_at'],
			$key
		);
	}
}
