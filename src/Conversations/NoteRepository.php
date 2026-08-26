<?php
/**
 * Internal conversation notes persistence.
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
 * Encrypts notes at rest. Notes are Hub-only (never visitor REST).
 */
class NoteRepository {

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
	 * Encryption AAD for a note UUID.
	 *
	 * @param string $note_uuid Note UUID.
	 */
	private function context( string $note_uuid ): string {
		return 'conversation_note:' . $note_uuid;
	}

	/**
	 * Creates an encrypted internal note.
	 *
	 * @param int    $conversation_id  Conversation primary key.
	 * @param int    $operator_user_id Operator user ID.
	 * @param string $plaintext_body   Plaintext note.
	 */
	public function create( int $conversation_id, int $operator_user_id, string $plaintext_body ): ?ConversationNote {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		$note_uuid = wp_generate_uuid4();

		try {
			$ciphertext = $this->vault->encrypt( $plaintext_body, $this->context( $note_uuid ) );
		} catch ( CredentialUnavailableException $exception ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATION_NOTES_TABLE;
		$now   = current_time( 'mysql', true );

		$inserted = $wpdb->insert(
			$table,
			array(
				'conversation_id'  => $conversation_id,
				'operator_user_id' => $operator_user_id,
				'note_uuid'        => $note_uuid,
				'body_ciphertext'  => $ciphertext,
				'created_at'       => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return null;
		}

		return $this->find_by_uuid( $note_uuid );
	}

	/**
	 * Inserts a note row with an explicit historical `created_at`,
	 * encrypting the plaintext body through this plugin's own vault
	 * immediately before persistence. Used only by the SC-M03 legacy
	 * migration engine. `$operator_user_id` must already be verified
	 * non-null by the caller — this table's column is `NOT NULL`; a legacy
	 * note whose authoring operator was anonymized (a real, observed
	 * Universal Telegram state) cannot be represented here and must instead
	 * fail the whole conversation's migration, never be silently coerced to
	 * a placeholder value.
	 *
	 * @param int    $conversation_id  Target conversation primary key.
	 * @param int    $operator_user_id Copied 1:1 from the legacy row (already verified non-null).
	 * @param string $plaintext_body   Decrypted legacy plaintext.
	 * @param string $created_at       Preserved legacy `created_at`.
	 *
	 * @return ConversationNote|null Null if the schema/key is unavailable or the write failed.
	 */
	public function import_legacy( int $conversation_id, int $operator_user_id, string $plaintext_body, string $created_at ): ?ConversationNote {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		$note_uuid = wp_generate_uuid4();

		try {
			$ciphertext = $this->vault->encrypt( $plaintext_body, $this->context( $note_uuid ) );
		} catch ( CredentialUnavailableException $exception ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATION_NOTES_TABLE;

		$inserted = $wpdb->insert(
			$table,
			array(
				'conversation_id'  => $conversation_id,
				'operator_user_id' => $operator_user_id,
				'note_uuid'        => $note_uuid,
				'body_ciphertext'  => $ciphertext,
				'created_at'       => $created_at,
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return null;
		}

		return $this->find_by_uuid( $note_uuid );
	}

	/**
	 * Finds a note by UUID.
	 *
	 * @param string $note_uuid Note UUID.
	 */
	public function find_by_uuid( string $note_uuid ): ?ConversationNote {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATION_NOTES_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE note_uuid = %s LIMIT 1",
				$note_uuid
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Lists notes for a conversation (oldest first).
	 *
	 * @param int $conversation_id Conversation primary key.
	 * @param int $limit           Max rows.
	 *
	 * @return array<int, ConversationNote>
	 */
	public function list_for_conversation( int $conversation_id, int $limit = 50 ): array {
		if ( ! $this->schema_health->is_available() ) {
			return array();
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATION_NOTES_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE conversation_id = %d ORDER BY id ASC LIMIT %d",
				$conversation_id,
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
			$out[] = $this->hydrate( $row );
		}

		return $out;
	}

	/**
	 * Deletes notes for a conversation (retention/uninstall).
	 *
	 * @param int $conversation_id Conversation primary key.
	 */
	public function delete_for_conversation( int $conversation_id ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATION_NOTES_TABLE;

		return false !== $wpdb->delete( $table, array( 'conversation_id' => $conversation_id ), array( '%d' ) );
	}

	/**
	 * Hydrates and decrypts a note row.
	 *
	 * @param array<string, mixed> $row Database row.
	 */
	private function hydrate( array $row ): ConversationNote {
		$plaintext = null;
		$cipher    = $row['body_ciphertext'] ?? null;

		if ( is_string( $cipher ) && '' !== $cipher ) {
			$result = $this->vault->decrypt( $cipher, $this->context( (string) $row['note_uuid'] ) );
			if ( CredentialState::AVAILABLE === $result->state() ) {
				$plaintext = $result->plaintext();
			}
		}

		return new ConversationNote(
			(int) $row['id'],
			(int) $row['conversation_id'],
			(int) $row['operator_user_id'],
			(string) $row['note_uuid'],
			$plaintext,
			(string) $row['created_at']
		);
	}
}
