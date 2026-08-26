<?php
/**
 * Complete, CI-enforced disposition registry for every Universal Telegram
 * legacy schema column this migration engine could encounter.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Migration;

/**
 * Every physical column of Universal Telegram's `conversations`,
 * `conversation_messages`, and `conversation_notes` tables has an explicit,
 * truthful disposition here (sc-m03-wp3-wp4-legacy-migration-engine-plan-v1.md
 * §4.1). `Interop\SchemaInventoryTest` introspects Universal Telegram's
 * real, merged schema and fails if any column is missing from this
 * registry — this is the sole source of truth for "every source column has
 * a disposition," not a document that can silently go stale.
 *
 * `exclude` means exactly that: the value is never read into this engine
 * at all (either Universal Telegram's own ADR-0008 export shape never
 * emits it, or this engine deliberately discards it), and it is never
 * written to any Support Chat table, including this engine's own metadata
 * tables. A column retained in `legacy_migration_map` or
 * `legacy_migration_message_map` — for audit, for work package 5's future
 * binding creation, or for correspondence lookups — is `preserve_for_map`,
 * never `exclude`, even though it is also never copied into the target
 * `conversations`/`conversation_messages`/`conversation_notes` row itself.
 */
final class LegacyFieldMap {

	public const DISPOSITION_COPY                  = 'copy';
	public const DISPOSITION_COPY_CONDITIONAL      = 'copy_conditional';
	public const DISPOSITION_REMAP                 = 'remap';
	public const DISPOSITION_TRANSFORM_TO_CONSTANT = 'transform_to_constant';
	public const DISPOSITION_PRESERVE_FOR_MAP      = 'preserve_for_map';
	public const DISPOSITION_EXCLUDE               = 'exclude';

	/**
	 * Every real column on Universal Telegram's three legacy tables (27 +
	 * 11 + 5 = 43 total), keyed by table name, then column name, mapped to
	 * its disposition. Not every column is present in Support Chat
	 * ADR-0008's export shape — only columns ADR-0008 §5 actually emits
	 * ever reach this engine at all; the remaining entries exist so
	 * `SchemaInventoryTest` can confirm the *entire* physical schema is
	 * accounted for, including the columns ADR-0008 already redacts at the
	 * Universal Telegram source.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function registry(): array {
		return array(
			'conversations'         => array(
				// Not copied into the target conversations row (a fresh
				// UUID/id is always minted, ADR-0008 §2), but retained
				// verbatim in legacy_migration_map as
				// source_conversation_id/source_conversation_uuid — the
				// map's own unique keys and the deterministic-ordering
				// cursor Phase A resumes from (PhaseABackfillService,
				// LegacyMigrationMapRepository::high_water_mark()).
				'id'                            => self::DISPOSITION_PRESERVE_FOR_MAP,
				'conversation_uuid'             => self::DISPOSITION_PRESERVE_FOR_MAP,
				'secret_hash'                   => self::DISPOSITION_EXCLUDE,
				// Not copied into the target row (Support Chat has no bot
				// concept); retained in legacy_migration_map as
				// legacy_bot_id/legacy_destination_id for work package 5's
				// future existing-topic binding creation.
				'bot_id'                        => self::DISPOSITION_PRESERVE_FOR_MAP,
				'destination_id'                => self::DISPOSITION_PRESERVE_FOR_MAP,
				'chat_profile'                  => self::DISPOSITION_EXCLUDE,
				'status'                        => self::DISPOSITION_COPY,
				'assigned_operator_id'          => self::DISPOSITION_COPY,
				// Not copied into the target row (Support Chat has no
				// topic-creation concept); retained in
				// legacy_migration_map as legacy_topic_creation_state /
				// legacy_telegram_topic_id / legacy_topic_lifecycle_state,
				// same work-package-5 rationale as bot_id/destination_id.
				'topic_creation_state'          => self::DISPOSITION_PRESERVE_FOR_MAP,
				'telegram_topic_id'             => self::DISPOSITION_PRESERVE_FOR_MAP,
				'ai_participation_state'        => self::DISPOSITION_EXCLUDE,
				'consent_state'                 => self::DISPOSITION_EXCLUDE,
				'session_ref'                   => self::DISPOSITION_EXCLUDE,
				'created_at'                    => self::DISPOSITION_COPY,
				'updated_at'                    => self::DISPOSITION_COPY,
				'resolved_at'                   => self::DISPOSITION_COPY,
				'expires_at'                    => self::DISPOSITION_COPY,
				'start_idempotency_key'         => self::DISPOSITION_REMAP,
				'topic_claim_expires_at'        => self::DISPOSITION_EXCLUDE,
				'display_name_ciphertext'       => self::DISPOSITION_EXCLUDE,
				'owner_user_id'                 => self::DISPOSITION_COPY_CONDITIONAL,
				'assignee_last_seen_message_id' => self::DISPOSITION_REMAP,
				'ai_ack_policy_version'         => self::DISPOSITION_EXCLUDE,
				'topic_lifecycle_state'         => self::DISPOSITION_PRESERVE_FOR_MAP,
				'topic_lifecycle_code'          => self::DISPOSITION_EXCLUDE,
				'topic_delete_claim_expires_at' => self::DISPOSITION_EXCLUDE,
				'owner_active_slot'             => self::DISPOSITION_EXCLUDE,
			),
			'conversation_messages' => array(
				// Not copied into the target message row (a fresh id/UUID
				// is always minted), but retained verbatim in
				// legacy_migration_message_map as source_id/source_uuid —
				// the authoritative per-message correspondence
				// assignee_last_seen_message_id remapping and Phase B's
				// drift detection both depend on.
				'id'                      => self::DISPOSITION_PRESERVE_FOR_MAP,
				// Never copied and never separately persisted: Universal
				// Telegram's ADR-0008 export shape does not even emit a
				// per-message conversation_id field (each message is
				// already nested under its owning conversation entry).
				// The parent/child relationship it expresses is not lost —
				// it is reconstructed by writing the *target*
				// conversation's own id (via the already-established
				// legacy_migration_map row) onto every target message,
				// which is exactly what "remap" means here.
				'conversation_id'         => self::DISPOSITION_REMAP,
				'message_uuid'            => self::DISPOSITION_PRESERVE_FOR_MAP,
				'direction'               => self::DISPOSITION_COPY,
				'body_ciphertext'         => self::DISPOSITION_COPY,
				'outbound_message_uuid'   => self::DISPOSITION_EXCLUDE,
				'telegram_message_id'     => self::DISPOSITION_EXCLUDE,
				'delivery_state'          => self::DISPOSITION_TRANSFORM_TO_CONSTANT,
				'idempotency_key'         => self::DISPOSITION_REMAP,
				'created_at'              => self::DISPOSITION_COPY,
				'telegram_sender_user_id' => self::DISPOSITION_EXCLUDE,
			),
			'conversation_notes'    => array(
				// Same rationale as conversation_messages.id above.
				'id'               => self::DISPOSITION_PRESERVE_FOR_MAP,
				// Same rationale as conversation_messages.conversation_id
				// above — Universal Telegram's export shape does not emit
				// a per-note conversation_id either.
				'conversation_id'  => self::DISPOSITION_REMAP,
				'operator_user_id' => self::DISPOSITION_COPY_CONDITIONAL,
				'body_ciphertext'  => self::DISPOSITION_COPY,
				'created_at'       => self::DISPOSITION_COPY,
			),
		);
	}

	/**
	 * Every registered column name for one legacy table.
	 *
	 * @param string $table One of 'conversations', 'conversation_messages', 'conversation_notes'.
	 *
	 * @return array<int, string>
	 */
	public static function registered_columns( string $table ): array {
		$registry = self::registry();

		return isset( $registry[ $table ] ) ? array_keys( $registry[ $table ] ) : array();
	}
}
