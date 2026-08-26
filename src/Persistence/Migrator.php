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

	public const AUDIT_LOG_TABLE                    = 'universal_support_chat_audit_log';
	public const CONVERSATIONS_TABLE                = 'universal_support_chat_conversations';
	public const CONVERSATION_MESSAGES_TABLE        = 'universal_support_chat_conversation_messages';
	public const CONVERSATION_NOTES_TABLE           = 'universal_support_chat_conversation_notes';
	public const CHANNEL_PEERS_TABLE                = 'universal_support_chat_channel_peers';
	public const CONTRACT_NONCES_TABLE              = 'universal_support_chat_contract_nonces';
	public const CHANNEL_STATUS_TABLE               = 'universal_support_chat_channel_status';
	public const LEGACY_MIGRATION_RUNS_TABLE        = 'universal_support_chat_legacy_migration_runs';
	public const LEGACY_MIGRATION_MAP_TABLE         = 'universal_support_chat_legacy_migration_map';
	public const LEGACY_MIGRATION_MESSAGE_MAP_TABLE = 'universal_support_chat_legacy_migration_message_map';
	public const LEGACY_MIGRATION_BATCH_LOG_TABLE   = 'universal_support_chat_legacy_migration_batch_log';

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
		return 9;
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
			5 => array( array( $this, 'step_5_create_channel_peers_table' ), array( $this, 'verify_step_5' ) ),
			6 => array( array( $this, 'step_6_create_contract_nonces_table' ), array( $this, 'verify_step_6' ) ),
			7 => array( array( $this, 'step_7_create_channel_status_table' ), array( $this, 'verify_step_7' ) ),
			8 => array( array( $this, 'step_8_add_channel_peers_outbound_route_base' ), array( $this, 'verify_step_8' ) ),
			9 => array( array( $this, 'step_9_create_legacy_migration_tables' ), array( $this, 'verify_step_9' ) ),
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
	 * Creates the ADR-0007 peer key store (mutual signed adapter auth).
	 * Never stores Telegram-native identifiers, only an opaque peer slug.
	 */
	private function step_5_create_channel_peers_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::CHANNEL_PEERS_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				peer_id VARCHAR(191) NOT NULL,
				public_key VARCHAR(64) NOT NULL,
				key_id VARCHAR(191) NOT NULL,
				allowed_operations LONGTEXT NOT NULL,
				required_peer_capability VARCHAR(191) NULL,
				status VARCHAR(16) NOT NULL DEFAULT 'active',
				created_at DATETIME NOT NULL,
				last_rotated_at DATETIME NULL,
				last_used_at DATETIME NULL,
				expires_at DATETIME NULL,
				revoked_at DATETIME NULL,
				PRIMARY KEY (id),
				UNIQUE KEY peer_id (peer_id),
				UNIQUE KEY key_id (key_id)
			) {$charset_collate}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies step 5 columns exist and channel-native columns do not.
	 */
	private function verify_step_5(): bool {
		global $wpdb;

		$table = $wpdb->prefix . self::CHANNEL_PEERS_TABLE;
		$ok    = $this->table_has_columns(
			$table,
			array(
				'id',
				'peer_id',
				'public_key',
				'key_id',
				'allowed_operations',
				'required_peer_capability',
				'status',
				'created_at',
				'last_rotated_at',
				'last_used_at',
				'expires_at',
				'revoked_at',
			)
		);

		if ( ! $ok ) {
			return false;
		}

		return ! $this->table_has_any_column(
			$table,
			array( 'telegram_topic_id', 'bot_id', 'destination_id', 'telegram_bot_token' )
		);
	}

	/**
	 * Creates the ADR-0007 nonce replay store. Holds only the fields the
	 * ADR permits: sender, key id, nonce, and when it was recorded.
	 */
	private function step_6_create_contract_nonces_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::CONTRACT_NONCES_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				sender VARCHAR(191) NOT NULL,
				key_id VARCHAR(191) NOT NULL,
				nonce VARCHAR(64) NOT NULL,
				recorded_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY sender_key_nonce (sender, key_id, nonce),
				KEY recorded_at (recorded_at)
			) {$charset_collate}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies step 6 columns exist.
	 */
	private function verify_step_6(): bool {
		global $wpdb;

		return $this->table_has_columns(
			$wpdb->prefix . self::CONTRACT_NONCES_TABLE,
			array( 'id', 'sender', 'key_id', 'nonce', 'recorded_at' )
		);
	}

	/**
	 * Creates the per-conversation channel status table (ADR-0005 §3:
	 * Support Chat stores only the opaque reference plus channel status
	 * derived from adapter callbacks).
	 */
	private function step_7_create_channel_status_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::CHANNEL_STATUS_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				conversation_id BIGINT UNSIGNED NOT NULL,
				status VARCHAR(16) NOT NULL DEFAULT 'available',
				reason_code VARCHAR(64) NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY conversation_id (conversation_id)
			) {$charset_collate}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies step 7 columns exist and channel-native columns do not.
	 */
	private function verify_step_7(): bool {
		global $wpdb;

		$table = $wpdb->prefix . self::CHANNEL_STATUS_TABLE;
		$ok    = $this->table_has_columns(
			$table,
			array( 'id', 'conversation_id', 'status', 'reason_code', 'updated_at' )
		);

		if ( ! $ok ) {
			return false;
		}

		return ! $this->table_has_any_column(
			$table,
			array( 'telegram_topic_id', 'telegram_message_id', 'bot_id', 'destination_id' )
		);
	}

	/**
	 * Adds the peer's outbound REST route base (ADR-0007 §1's "future
	 * adapter" note): the registered route prefix Support Chat targets when
	 * it, itself, calls that peer for the four Support-Chat-to-adapter
	 * Contract v1 operations (`ensure_channel_case`, `notify_operators`,
	 * `deliver_transcript_backfill`, `deliver_message`). Non-secret routing
	 * metadata only — never a credential, never used for verification of
	 * inbound calls, and null (no outbound calls possible yet) for every
	 * peer paired before this column existed.
	 */
	private function step_8_add_channel_peers_outbound_route_base(): void {
		global $wpdb;

		$table = $wpdb->prefix . self::CHANNEL_PEERS_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'outbound_route_base'",
				$wpdb->dbname,
				$table
			)
		);

		if ( '0' === (string) $exists ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN outbound_route_base VARCHAR(191) NULL AFTER required_peer_capability" );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies step 8's column exists.
	 */
	private function verify_step_8(): bool {
		global $wpdb;

		return $this->table_has_columns(
			$wpdb->prefix . self::CHANNEL_PEERS_TABLE,
			array( 'outbound_route_base' )
		);
	}

	/**
	 * Creates the SC-M03 work packages 3-4 legacy migration metadata tables
	 * (ADR-0008, sc-m03-wp3-wp4-legacy-migration-engine-plan-v1.md §4.3).
	 * Every non-ciphertext column here holds only IDs, timestamps, booleans,
	 * or counts — never plaintext or a content-derived digest (plan §4.5).
	 */
	private function step_9_create_legacy_migration_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$runs_table = $wpdb->prefix . self::LEGACY_MIGRATION_RUNS_TABLE;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$runs_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				run_uuid CHAR(36) NOT NULL,
				phase VARCHAR(16) NOT NULL,
				status VARCHAR(16) NOT NULL DEFAULT 'running',
				dry_run TINYINT(1) NOT NULL DEFAULT 0,
				batch_size INT UNSIGNED NOT NULL,
				checkpoint_cursor BIGINT UNSIGNED NOT NULL DEFAULT 0,
				started_at DATETIME NOT NULL,
				completed_at DATETIME NULL,
				created_by_user_id BIGINT UNSIGNED NULL,
				PRIMARY KEY (id),
				UNIQUE KEY run_uuid (run_uuid),
				KEY phase_status (phase, status)
			) {$charset_collate}"
		);

		$map_table = $wpdb->prefix . self::LEGACY_MIGRATION_MAP_TABLE;
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$map_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				source_conversation_id BIGINT UNSIGNED NOT NULL,
				source_conversation_uuid CHAR(36) NOT NULL,
				target_conversation_id BIGINT UNSIGNED NULL,
				target_conversation_uuid CHAR(36) NULL,
				status VARCHAR(16) NOT NULL DEFAULT 'pending',
				legacy_bot_id BIGINT UNSIGNED NULL,
				legacy_destination_id BIGINT UNSIGNED NULL,
				legacy_telegram_topic_id BIGINT UNSIGNED NULL,
				legacy_topic_creation_state VARCHAR(32) NULL,
				legacy_topic_lifecycle_state VARCHAR(32) NULL,
				message_count_source INT UNSIGNED NOT NULL DEFAULT 0,
				message_count_target INT UNSIGNED NOT NULL DEFAULT 0,
				note_count_source INT UNSIGNED NOT NULL DEFAULT 0,
				note_count_target INT UNSIGNED NOT NULL DEFAULT 0,
				validation_passed TINYINT(1) NULL,
				validated_at DATETIME NULL,
				error_reason VARCHAR(191) NULL,
				migrated_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY source_conversation_id (source_conversation_id),
				UNIQUE KEY source_conversation_uuid (source_conversation_uuid),
				KEY status (status)
			) {$charset_collate}"
		);

		$message_map_table = $wpdb->prefix . self::LEGACY_MIGRATION_MESSAGE_MAP_TABLE;
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$message_map_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				conversation_map_id BIGINT UNSIGNED NOT NULL,
				kind VARCHAR(16) NOT NULL,
				source_id BIGINT UNSIGNED NOT NULL,
				source_uuid CHAR(36) NOT NULL,
				target_id BIGINT UNSIGNED NULL,
				target_uuid CHAR(36) NULL,
				idempotency_key CHAR(36) NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY conversation_kind_source (conversation_map_id, kind, source_id),
				KEY conversation_map_id (conversation_map_id)
			) {$charset_collate}"
		);

		$batch_log_table = $wpdb->prefix . self::LEGACY_MIGRATION_BATCH_LOG_TABLE;
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$batch_log_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				run_id BIGINT UNSIGNED NOT NULL,
				batch_number INT UNSIGNED NOT NULL,
				cursor_start BIGINT UNSIGNED NOT NULL,
				cursor_end BIGINT UNSIGNED NOT NULL,
				rows_processed INT UNSIGNED NOT NULL DEFAULT 0,
				rows_migrated INT UNSIGNED NOT NULL DEFAULT 0,
				rows_skipped INT UNSIGNED NOT NULL DEFAULT 0,
				rows_failed INT UNSIGNED NOT NULL DEFAULT 0,
				started_at DATETIME NOT NULL,
				completed_at DATETIME NULL,
				error_summary VARCHAR(191) NULL,
				PRIMARY KEY (id),
				KEY run_id (run_id)
			) {$charset_collate}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies step 9's four tables and their columns exist, and that no
	 * plaintext/content-derived column was accidentally added (only IDs,
	 * timestamps, booleans, counts, and short stable reason strings).
	 */
	private function verify_step_9(): bool {
		global $wpdb;

		$runs_ok = $this->table_has_columns(
			$wpdb->prefix . self::LEGACY_MIGRATION_RUNS_TABLE,
			array( 'id', 'run_uuid', 'phase', 'status', 'dry_run', 'batch_size', 'checkpoint_cursor', 'started_at', 'completed_at', 'created_by_user_id' )
		);

		$map_ok = $this->table_has_columns(
			$wpdb->prefix . self::LEGACY_MIGRATION_MAP_TABLE,
			array(
				'id',
				'source_conversation_id',
				'source_conversation_uuid',
				'target_conversation_id',
				'target_conversation_uuid',
				'status',
				'legacy_bot_id',
				'legacy_destination_id',
				'legacy_telegram_topic_id',
				'legacy_topic_creation_state',
				'legacy_topic_lifecycle_state',
				'message_count_source',
				'message_count_target',
				'note_count_source',
				'note_count_target',
				'validation_passed',
				'validated_at',
				'error_reason',
				'migrated_at',
				'created_at',
				'updated_at',
			)
		);

		$message_map_ok = $this->table_has_columns(
			$wpdb->prefix . self::LEGACY_MIGRATION_MESSAGE_MAP_TABLE,
			array( 'id', 'conversation_map_id', 'kind', 'source_id', 'source_uuid', 'target_id', 'target_uuid', 'idempotency_key', 'created_at' )
		);

		$batch_log_ok = $this->table_has_columns(
			$wpdb->prefix . self::LEGACY_MIGRATION_BATCH_LOG_TABLE,
			array( 'id', 'run_id', 'batch_number', 'cursor_start', 'cursor_end', 'rows_processed', 'rows_migrated', 'rows_skipped', 'rows_failed', 'started_at', 'completed_at', 'error_summary' )
		);

		if ( ! $runs_ok || ! $map_ok || ! $message_map_ok || ! $batch_log_ok ) {
			return false;
		}

		// Never any plaintext/body column, and never a Telegram-native
		// identifier column (bot_id/destination_id/topic id are already
		// covered above as legacy_*-prefixed, non-content routing evidence
		// only — this forbids anything additional creeping in).
		$forbidden_content_columns = array( 'body', 'body_ciphertext', 'plaintext', 'content_hash', 'digest' );

		return ! $this->table_has_any_column( $wpdb->prefix . self::LEGACY_MIGRATION_MAP_TABLE, $forbidden_content_columns )
			&& ! $this->table_has_any_column( $wpdb->prefix . self::LEGACY_MIGRATION_MESSAGE_MAP_TABLE, $forbidden_content_columns )
			&& ! $this->table_has_any_column( $wpdb->prefix . self::LEGACY_MIGRATION_BATCH_LOG_TABLE, $forbidden_content_columns );
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
