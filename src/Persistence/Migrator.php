<?php
/**
 * Schema migration runner.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Persistence;

/**
 * Runs schema changes as numbered, ordered steps using raw $wpdb->query()
 * DDL, never dbDelta(). The schema-version option advances only after a
 * step's statements and postcondition both succeed.
 */
class Migrator {

	public const AUDIT_LOG_TABLE             = 'universal_support_chat_audit_log';
	public const CONVERSATIONS_TABLE         = 'universal_support_chat_conversations';
	public const CONVERSATION_MESSAGES_TABLE = 'universal_support_chat_conversation_messages';
	public const CONVERSATION_NOTES_TABLE    = 'universal_support_chat_conversation_notes';

	private const DB_VERSION_OPTION = 'universal_support_chat_db_version';

	/**
	 * Coordinates concurrent migration attempts.
	 *
	 * @var MigrationLock
	 */
	private MigrationLock $lock;

	/**
	 * Constructor.
	 *
	 * @param MigrationLock $lock Coordinates concurrent migration attempts.
	 */
	public function __construct( MigrationLock $lock ) {
		$this->lock = $lock;
	}

	/**
	 * Highest step number this migrator knows how to run.
	 */
	protected function target_version(): int {
		return 4;
	}

	/**
	 * Runs pending steps under the migration lock.
	 *
	 * @throws MigrationFailedException If lock or step fails while behind.
	 */
	public function maybe_migrate(): void {
		$current_version = (int) get_option( self::DB_VERSION_OPTION, 0 );

		if ( $current_version >= $this->target_version() ) {
			return;
		}

		$handle = $this->lock->acquire();

		if ( null === $handle ) {
			$current_version = (int) get_option( self::DB_VERSION_OPTION, 0 );

			if ( $current_version < $this->target_version() ) {
				throw new MigrationFailedException( MigrationFailureCode::LOCK_UNAVAILABLE );
			}

			return;
		}

		try {
			$this->run_pending_steps( $current_version );
		} finally {
			$this->lock->release( $handle );
		}
	}

	/**
	 * Runs every step between the recorded version and the target.
	 *
	 * @param int $from_version The schema version already recorded.
	 *
	 * @throws MigrationFailedException If any pending step fails.
	 */
	private function run_pending_steps( int $from_version ): void {
		$target = $this->target_version();

		for ( $number = $from_version + 1; $number <= $target; $number++ ) {
			$this->run_step( $number );
			update_option( self::DB_VERSION_OPTION, $number );
		}
	}

	/**
	 * Runs one step.
	 *
	 * @param int $number The step number.
	 *
	 * @throws MigrationFailedException If the step fails.
	 */
	protected function run_step( int $number ): void {
		$steps = array(
			1 => array( array( $this, 'step_1_create_audit_log_table' ), array( $this, 'verify_step_1' ) ),
			2 => array( array( $this, 'step_2_create_conversations_table' ), array( $this, 'verify_step_2' ) ),
			3 => array( array( $this, 'step_3_create_conversation_messages_table' ), array( $this, 'verify_step_3' ) ),
			4 => array( array( $this, 'step_4_create_conversation_notes_table' ), array( $this, 'verify_step_4' ) ),
		);

		if ( ! isset( $steps[ $number ] ) ) {
			throw new MigrationFailedException( MigrationFailureCode::STEP_FAILED );
		}

		list( $run, $verify ) = $steps[ $number ];

		$run();

		if ( ! $verify() ) {
			throw new MigrationFailedException( MigrationFailureCode::POSTCONDITION_FAILED );
		}
	}

	/**
	 * Creates the audit log table.
	 */
	private function step_1_create_audit_log_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::AUDIT_LOG_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				occurred_at DATETIME NOT NULL,
				actor_type VARCHAR(32) NOT NULL,
				actor_id BIGINT UNSIGNED NULL,
				action VARCHAR(191) NOT NULL,
				context LONGTEXT NULL,
				privacy_classification VARCHAR(16) NOT NULL,
				PRIMARY KEY (id),
				KEY occurred_at (occurred_at),
				KEY action (action)
			) {$charset_collate}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies step 1 columns exist.
	 */
	private function verify_step_1(): bool {
		global $wpdb;

		return $this->table_has_columns(
			$wpdb->prefix . self::AUDIT_LOG_TABLE,
			array( 'id', 'occurred_at', 'actor_type', 'actor_id', 'action', 'context', 'privacy_classification' )
		);
	}

	/**
	 * Creates Support Chat–owned conversations table. No Telegram/channel columns.
	 */
	private function step_2_create_conversations_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::CONVERSATIONS_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				conversation_uuid CHAR(36) NOT NULL,
				owner_user_id BIGINT UNSIGNED NOT NULL,
				status VARCHAR(32) NOT NULL DEFAULT 'new',
				assigned_operator_id BIGINT UNSIGNED NULL,
				start_idempotency_key CHAR(36) NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				resolved_at DATETIME NULL,
				expires_at DATETIME NULL,
				assignee_last_seen_message_id BIGINT UNSIGNED NULL,
				PRIMARY KEY (id),
				UNIQUE KEY conversation_uuid (conversation_uuid),
				UNIQUE KEY start_idempotency_key (start_idempotency_key),
				KEY owner_status (owner_user_id, status),
				KEY status_updated (status, updated_at)
			) {$charset_collate}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies step 2 columns exist and channel-native columns do not.
	 */
	private function verify_step_2(): bool {
		global $wpdb;

		$table = $wpdb->prefix . self::CONVERSATIONS_TABLE;
		$ok    = $this->table_has_columns(
			$table,
			array(
				'id',
				'conversation_uuid',
				'owner_user_id',
				'status',
				'assigned_operator_id',
				'start_idempotency_key',
				'created_at',
				'updated_at',
				'resolved_at',
				'expires_at',
				'assignee_last_seen_message_id',
			)
		);

		if ( ! $ok ) {
			return false;
		}

		$forbidden = array(
			'telegram_topic_id',
			'bot_id',
			'destination_id',
			'topic_creation_state',
			'secret_hash',
			'outbound_message_uuid',
			'telegram_message_id',
			'channel_case_ref',
		);

		return ! $this->table_has_any_column( $table, $forbidden );
	}

	/**
	 * Creates encrypted conversation messages table. No Telegram/channel columns.
	 */
	private function step_3_create_conversation_messages_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::CONVERSATION_MESSAGES_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				conversation_id BIGINT UNSIGNED NOT NULL,
				message_uuid CHAR(36) NOT NULL,
				direction VARCHAR(16) NOT NULL,
				body_ciphertext LONGTEXT NULL,
				delivery_state VARCHAR(16) NOT NULL DEFAULT 'stored',
				idempotency_key CHAR(36) NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY message_uuid (message_uuid),
				UNIQUE KEY conversation_idempotency (conversation_id, idempotency_key),
				KEY conversation_id_seq (conversation_id, id)
			) {$charset_collate}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies step 3 columns exist and channel-native columns do not.
	 */
	private function verify_step_3(): bool {
		global $wpdb;

		$table = $wpdb->prefix . self::CONVERSATION_MESSAGES_TABLE;
		$ok    = $this->table_has_columns(
			$table,
			array(
				'id',
				'conversation_id',
				'message_uuid',
				'direction',
				'body_ciphertext',
				'delivery_state',
				'idempotency_key',
				'created_at',
			)
		);

		if ( ! $ok ) {
			return false;
		}

		$forbidden = array(
			'telegram_message_id',
			'outbound_message_uuid',
			'bot_id',
			'destination_id',
			'telegram_topic_id',
		);

		return ! $this->table_has_any_column( $table, $forbidden );
	}


	/**
	 * Creates Support Chat–owned internal notes table (Hub-only).
	 */
	private function step_4_create_conversation_notes_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::CONVERSATION_NOTES_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				conversation_id BIGINT UNSIGNED NOT NULL,
				operator_user_id BIGINT UNSIGNED NOT NULL,
				note_uuid CHAR(36) NOT NULL,
				body_ciphertext LONGTEXT NOT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY note_uuid (note_uuid),
				KEY conversation_id (conversation_id)
			) {$charset_collate}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies step 4 columns exist and channel-native columns do not.
	 */
	private function verify_step_4(): bool {
		global $wpdb;

		$table = $wpdb->prefix . self::CONVERSATION_NOTES_TABLE;
		$ok    = $this->table_has_columns(
			$table,
			array(
				'id',
				'conversation_id',
				'operator_user_id',
				'note_uuid',
				'body_ciphertext',
				'created_at',
			)
		);

		if ( ! $ok ) {
			return false;
		}

		return ! $this->table_has_any_column(
			$table,
			array(
				'telegram_message_id',
				'telegram_topic_id',
				'bot_id',
				'destination_id',
			)
		);
	}

	/**
	 * Whether a table contains every expected column.
	 *
	 * @param string             $table    Table name including prefix.
	 * @param array<int, string> $expected Expected column names.
	 */
	private function table_has_columns( string $table, array $expected ): bool {
		global $wpdb;

		$columns = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$table
			)
		);

		if ( ! is_array( $columns ) ) {
			return false;
		}

		return array() === array_diff( $expected, $columns );
	}

	/**
	 * Whether a table contains any of the named columns.
	 *
	 * @param string             $table Table name including prefix.
	 * @param array<int, string> $names Column names that must not exist.
	 */
	private function table_has_any_column( string $table, array $names ): bool {
		global $wpdb;

		$columns = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				$wpdb->dbname,
				$table
			)
		);

		if ( ! is_array( $columns ) ) {
			return false;
		}

		return array() !== array_intersect( $names, $columns );
	}
}
