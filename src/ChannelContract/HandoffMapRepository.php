<?php
/**
 * SC-owned final-cutover handoff-provenance map.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\ChannelContract;

use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;

/**
 * CRUD for `universal_support_chat_legacy_handoff_map` (ADR-0010 §4).
 * `insert()` must only ever be called from inside the same explicit
 * transaction as the domain effect it accompanies
 * (`ContractOperationDispatcher`'s own `dispatch_with_provenance()`
 * wrapper) — this class never opens or closes a transaction itself. No
 * method here ever reads or writes content: only ids, uuids, a fixed
 * `kind` vocabulary, and timestamps.
 */
final class HandoffMapRepository {

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health Checked before every operation.
	 */
	public function __construct( private readonly SchemaHealth $schema_health ) {}

	/**
	 * Looks up an existing row for `(bot_id, update_id)`, if any — used to
	 * distinguish a genuine retry (matching `kind`/`channel_case_ref`) from
	 * a provenance conflict (docs/adr/0010 §4).
	 *
	 * @param int $bot_id    Cutover-replay provenance: the source bot.
	 * @param int $update_id Cutover-replay provenance: the source Telegram update_id.
	 *
	 * @return array{kind: string, channel_case_ref: string, target_message_uuid: ?string}|null
	 */
	public function find( int $bot_id, int $update_id ): ?array {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::LEGACY_HANDOFF_MAP_TABLE;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT kind, channel_case_ref, target_message_uuid FROM {$table} WHERE bot_id = %d AND update_id = %d",
				$bot_id,
				$update_id
			),
			ARRAY_A
		);

		if ( null === $row ) {
			return null;
		}

		return array(
			'kind'                => (string) $row['kind'],
			'channel_case_ref'    => (string) $row['channel_case_ref'],
			'target_message_uuid' => null === $row['target_message_uuid'] ? null : (string) $row['target_message_uuid'],
		);
	}

	/**
	 * Inserts one provenance row. Must only ever be called from inside the
	 * same transaction as the domain effect it accompanies — never opens
	 * or commits a transaction of its own.
	 *
	 * @param int         $bot_id               Cutover-replay provenance: the source bot.
	 * @param int         $update_id            Cutover-replay provenance: the source Telegram update_id.
	 * @param string      $kind                 Server-derived disposition kind — never client-supplied.
	 * @param string      $channel_case_ref     The binding UUID this call resolved to.
	 * @param string|null $target_message_uuid  Populated only for `kind = 'message'`.
	 */
	public function insert( int $bot_id, int $update_id, string $kind, string $channel_case_ref, ?string $target_message_uuid ): void {
		if ( ! $this->schema_health->is_available() ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::LEGACY_HANDOFF_MAP_TABLE;

		$wpdb->insert(
			$table,
			array(
				'bot_id'              => $bot_id,
				'update_id'           => $update_id,
				'kind'                => $kind,
				'channel_case_ref'    => $channel_case_ref,
				'target_message_uuid' => $target_message_uuid,
				'created_at'          => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);
	}
}
