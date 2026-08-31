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
	public const LEGACY_HANDOFF_MAP_TABLE           = 'universal_support_chat_legacy_handoff_map';
	public const TELEGRAM_DISPATCH_TABLE            = 'universal_support_chat_telegram_dispatch';
	public const AI_TURNS_TABLE                     = 'universal_support_chat_ai_turns';
	public const AI_KNOWLEDGE_SOURCES_TABLE         = 'universal_support_chat_knowledge_sources';

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
		return 13;
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
			1  => array( array( $this, 'step_1_create_audit_log_table' ), array( $this, 'verify_step_1' ) ),
			2  => array( array( $this, 'step_2_create_conversations_table' ), array( $this, 'verify_step_2' ) ),
			3  => array( array( $this, 'step_3_create_conversation_messages_table' ), array( $this, 'verify_step_3' ) ),
			4  => array( array( $this, 'step_4_create_conversation_notes_table' ), array( $this, 'verify_step_4' ) ),
			5  => array( array( $this, 'step_5_create_channel_peers_table' ), array( $this, 'verify_step_5' ) ),
			6  => array( array( $this, 'step_6_create_contract_nonces_table' ), array( $this, 'verify_step_6' ) ),
			7  => array( array( $this, 'step_7_create_channel_status_table' ), array( $this, 'verify_step_7' ) ),
			8  => array( array( $this, 'step_8_add_channel_peers_outbound_route_base' ), array( $this, 'verify_step_8' ) ),
			9  => array( array( $this, 'step_9_create_legacy_migration_tables' ), array( $this, 'verify_step_9' ) ),
			10 => array( array( $this, 'step_10_add_legacy_migration_map_binding_columns' ), array( $this, 'verify_step_10' ) ),
			11 => array( array( $this, 'step_11_create_legacy_handoff_map_table' ), array( $this, 'verify_step_11' ) ),
			12 => array( array( $this, 'step_12_create_telegram_dispatch_table' ), array( $this, 'verify_step_12' ) ),
			13 => array( array( $this, 'step_13_create_ai_tables' ), array( $this, 'verify_step_13' ) ),
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
	 * Steps 9, 10, and 11 — SC-M03 legacy-migration / final-cutover schema —
	 * are RETIRED (ADR-0013). Universal Telegram ADR-0044 made that plugin
	 * transport/adapter-only, so the legacy export, Phase A/Phase B
	 * migration, quiescence, binding-preparation, and cutover-handoff
	 * machinery these tables served can no longer operate and has been
	 * removed from `src/`.
	 *
	 * On a FRESH install these three steps are inert no-ops: they create no
	 * `legacy_migration_*` and no `legacy_handoff_map` table. The schema
	 * version still advances through 9 → 10 → 11 → 12 so `db_version` stays
	 * monotonic at 12 and step 12 (the ADR-0012 dispatch outbox) installs
	 * exactly as before.
	 *
	 * On an install that was ALREADY upgraded past these steps the tables
	 * remain in place, untouched — they are historical, inert data. This
	 * migrator never drops, purges, or reinterprets them. Their name-only
	 * manifest constants (`LEGACY_MIGRATION_*_TABLE`, `LEGACY_HANDOFF_MAP_TABLE`)
	 * are retained for uninstall compatibility and diagnostics. Removing
	 * that historical data, if ever desired, needs a separately approved,
	 * guarded cleanup task — it is deliberately out of scope here.
	 */
	private function step_9_create_legacy_migration_tables(): void {
		// Retired (ADR-0013) — intentionally does nothing. See the block comment above.
	}

	/**
	 * Retired (ADR-0013). See {@see step_9_create_legacy_migration_tables()}.
	 */
	private function step_10_add_legacy_migration_map_binding_columns(): void {
		// Retired (ADR-0013) — intentionally does nothing.
	}

	/**
	 * Retired (ADR-0013). See {@see step_9_create_legacy_migration_tables()}.
	 */
	private function step_11_create_legacy_handoff_map_table(): void {
		// Retired (ADR-0013) — intentionally does nothing.
	}

	/**
	 * Postcondition for the retired SC-M03 steps 9–11 (ADR-0013): there is
	 * nothing to verify — a fresh install creates none of these tables, and
	 * an upgraded install's historical tables are deliberately left
	 * untouched. Always satisfied.
	 */
	private function verify_step_9(): bool {
		return true;
	}

	/**
	 * Postcondition for retired step 10 (ADR-0013). Always satisfied.
	 */
	private function verify_step_10(): bool {
		return true;
	}

	/**
	 * Postcondition for retired step 11 (ADR-0013). Always satisfied.
	 */
	private function verify_step_11(): bool {
		return true;
	}

	/**
	 * Creates the Support Chat -> Telegram automatic-dispatch outbox
	 * (ADR-0012). One durable, Support-Chat-owned row per committed
	 * conversation message that is a candidate for mirroring into the
	 * linked Telegram forum topic, so a committed message is never lost
	 * when the Universal Telegram adapter is unavailable. No column here is
	 * content-bearing: the body is read live from the encrypted messages
	 * table at delivery time.
	 */
	private function step_12_create_telegram_dispatch_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::TELEGRAM_DISPATCH_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				message_uuid CHAR(36) NOT NULL,
				conversation_id BIGINT UNSIGNED NOT NULL,
				conversation_uuid CHAR(36) NOT NULL,
				direction VARCHAR(16) NOT NULL,
				origin VARCHAR(16) NOT NULL DEFAULT 'support_chat',
				state VARCHAR(24) NOT NULL DEFAULT 'pending',
				attempts INT UNSIGNED NOT NULL DEFAULT 0,
				channel_case_ref CHAR(36) NULL,
				last_reason VARCHAR(191) NULL,
				next_attempt_at DATETIME NOT NULL,
				claimed_at DATETIME NULL,
				lease_expires_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY message_uuid (message_uuid),
				KEY state_due (state, next_attempt_at),
				KEY state_lease (state, lease_expires_at),
				KEY conversation_id (conversation_id)
			) {$charset_collate}"
		);

		// Additive, in case an earlier (pre-release) build of this step
		// created the table without the crash-recovery lease columns.
		// SHOW COLUMNS on this connection, not INFORMATION_SCHEMA — same
		// reasoning as step 10.
		foreach (
			array(
				'claimed_at'       => "ALTER TABLE {$table} ADD COLUMN claimed_at DATETIME NULL AFTER next_attempt_at",
				'lease_expires_at' => "ALTER TABLE {$table} ADD COLUMN lease_expires_at DATETIME NULL AFTER claimed_at",
			) as $column => $alter_sql
		) {
			if ( empty( $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE '{$column}'" ) ) ) {
				$wpdb->query( $alter_sql );
			}
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Verifies the dispatch outbox exists with the expected columns and
	 * carries no forbidden content column.
	 */
	private function verify_step_12(): bool {
		global $wpdb;

		$table = $wpdb->prefix . self::TELEGRAM_DISPATCH_TABLE;

		$columns_ok = $this->table_has_columns(
			$table,
			array(
				'id',
				'message_uuid',
				'conversation_id',
				'conversation_uuid',
				'direction',
				'origin',
				'state',
				'attempts',
				'channel_case_ref',
				'last_reason',
				'next_attempt_at',
				'claimed_at',
				'lease_expires_at',
				'created_at',
				'updated_at',
			)
		);

		$forbidden_content_columns = array( 'body', 'body_ciphertext', 'plaintext', 'content_hash', 'digest', 'text' );

		return $columns_ok && ! $this->table_has_any_column( $table, $forbidden_content_columns );
	}

	/**
	 * Creates the SC-M07 AI-first visitor support tables (ADR-0018,
	 * migration step 13):
	 *
	 * - `ai_turns` — metadata only. One row per AI turn queued from a visitor
	 *   message. No prompt, no answer, no visitor text, no retrieved content:
	 *   the answer lives only as an `ai`-direction row in the encrypted
	 *   messages table; the prompt is never persisted anywhere. Every column
	 *   is an id, uuid, fixed-vocabulary string, small int, count, or
	 *   timestamp, plus the `source_ids` / `source_checksums` provenance
	 *   references (comma-joined integer ids and SHA-256 hex prefixes).
	 * - `knowledge_sources` — encrypted content only. The approved plain-text
	 *   snapshot lives solely in `indexed_text_ciphertext` as a
	 *   {@see \UniversalSupportChat\Core\Security\CredentialVault} envelope
	 *   (AAD context `knowledge_source:<source_uuid>`). No plaintext content
	 *   column, and no visitor / PII column.
	 *
	 * Table-specific verification is enforced in {@see verify_step_13()}.
	 */
	private function step_13_create_ai_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$turns           = $wpdb->prefix . self::AI_TURNS_TABLE;
		$sources         = $wpdb->prefix . self::AI_KNOWLEDGE_SOURCES_TABLE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$turns} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				turn_uuid CHAR(36) NOT NULL,
				conversation_id BIGINT UNSIGNED NOT NULL,
				visitor_message_id BIGINT UNSIGNED NULL,
				ai_message_id BIGINT UNSIGNED NULL,
				status VARCHAR(16) NOT NULL DEFAULT 'queued',
				outcome VARCHAR(24) NULL,
				finish_reason VARCHAR(24) NULL,
				handoff_reason VARCHAR(32) NULL,
				provider_error_class VARCHAR(32) NULL,
				attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				prompt_tokens INT UNSIGNED NULL,
				completion_tokens INT UNSIGNED NULL,
				latency_ms INT UNSIGNED NULL,
				source_ids VARCHAR(255) NULL,
				source_checksums VARCHAR(255) NULL,
				claimed_at DATETIME NULL,
				lease_expires_at DATETIME NULL,
				available_at DATETIME NOT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY turn_uuid (turn_uuid),
				KEY status_due (status, available_at),
				KEY status_lease (status, lease_expires_at),
				KEY conversation_id (conversation_id)
			) {$charset_collate}"
		);

		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$sources} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				source_uuid CHAR(36) NOT NULL,
				source_type VARCHAR(16) NOT NULL,
				post_id BIGINT UNSIGNED NULL,
				label VARCHAR(191) NOT NULL DEFAULT '',
				indexed_text_ciphertext MEDIUMTEXT NULL,
				content_checksum CHAR(64) NOT NULL DEFAULT '',
				status VARCHAR(16) NOT NULL DEFAULT 'approved',
				approved_by BIGINT UNSIGNED NULL,
				approved_at DATETIME NULL,
				last_indexed_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY source_uuid (source_uuid),
				KEY status (status),
				KEY post_id (post_id)
			) {$charset_collate}"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Verifies step 13 — table-specific content-column boundary (ADR-0018
	 * "Schema verification boundary"):
	 *
	 * - `ai_turns` exists and carries **no** free-text/content column
	 *   (`body`, `prompt`, `response`, `message_text`, `content`, `text`,
	 *   `plaintext`, `ciphertext`, `transcript`). `*_id` references are
	 *   metadata and pass.
	 * - `knowledge_sources` exists, **has** `indexed_text_ciphertext`, and
	 *   carries **no** plaintext content column and **no** visitor / PII
	 *   column.
	 */
	private function verify_step_13(): bool {
		global $wpdb;

		$turns   = $wpdb->prefix . self::AI_TURNS_TABLE;
		$sources = $wpdb->prefix . self::AI_KNOWLEDGE_SOURCES_TABLE;

		$turns_ok = $this->table_has_columns(
			$turns,
			array(
				'id',
				'turn_uuid',
				'conversation_id',
				'visitor_message_id',
				'ai_message_id',
				'status',
				'outcome',
				'finish_reason',
				'handoff_reason',
				'provider_error_class',
				'attempts',
				'prompt_tokens',
				'completion_tokens',
				'latency_ms',
				'source_ids',
				'source_checksums',
				'claimed_at',
				'lease_expires_at',
				'available_at',
				'created_at',
				'updated_at',
			)
		);

		if ( ! $turns_ok ) {
			return false;
		}

		if ( $this->table_has_column_matching(
			$turns,
			'/^(body|prompt|response|message_text|content|text|plaintext|ciphertext|transcript)$/'
		) ) {
			return false;
		}

		$sources_ok = $this->table_has_columns(
			$sources,
			array(
				'id',
				'source_uuid',
				'source_type',
				'post_id',
				'label',
				'indexed_text_ciphertext',
				'content_checksum',
				'status',
				'approved_by',
				'approved_at',
				'last_indexed_at',
				'created_at',
				'updated_at',
			)
		);

		if ( ! $sources_ok ) {
			return false;
		}

		if ( $this->table_has_column_matching(
			$sources,
			'/^(indexed_text|body|raw_content|plaintext|content|snippet_text)$/'
		) ) {
			return false;
		}

		return ! $this->table_has_any_column(
			$sources,
			array( 'owner_user_id', 'user_email', 'visitor_email', 'conversation_id', 'message_uuid' )
		);
	}

	/**
	 * Whether a table contains any column whose exact name matches a pattern.
	 *
	 * @param string $table   Table name including prefix.
	 * @param string $pattern A PCRE pattern applied to each column name.
	 */
	private function table_has_column_matching( string $table, string $pattern ): bool {
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

		foreach ( $columns as $column ) {
			if ( 1 === preg_match( $pattern, (string) $column ) ) {
				return true;
			}
		}

		return false;
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
