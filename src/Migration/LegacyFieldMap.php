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
 * `conversation_messages`, and `conversation_notes` tables has an explicit
 * disposition here (sc-m03-wp3-wp4-legacy-migration-engine-plan-v1.md §4.1).
 * `Interop\SchemaInventoryTest` introspects Universal Telegram's real,
 * merged schema and fails if any column is missing from this registry —
 * this is the sole source of truth for "every source column has a
 * disposition," not a document that can silently go stale.
 */
final class LegacyFieldMap {

	public const DISPOSITION_COPY                  = 'copy';
	public const DISPOSITION_COPY_CONDITIONAL      = 'copy_conditional';
	public const DISPOSITION_REMAP                 = 'remap';
	public const DISPOSITION_TRANSFORM_TO_CONSTANT = 'transform_to_constant';
	public const DISPOSITION_EXCLUDE               = 'exclude';

	/**
	 * Every real column on Universal Telegram's three legacy tables, keyed
	 * by table name, then column name, mapped to its disposition. Not every
	 * column is present in Support Chat ADR-0008's export shape — only
	 * columns ADR-0008 §5 actually emits ever reach this engine at all; the
	 * remaining entries exist so `SchemaInventoryTest` can confirm the
	 * *entire* physical schema is accounted for, including the columns
	 * ADR-0008 already redacts at the Universal Telegram source.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function registry(): array {
		return array(
			'conversations'         => array(
				'id'                            => self::DISPOSITION_EXCLUDE,
				'conversation_uuid'             => self::DISPOSITION_EXCLUDE,
				'secret_hash'                   => self::DISPOSITION_EXCLUDE,
				'bot_id'                        => self::DISPOSITION_EXCLUDE,
				'destination_id'                => self::DISPOSITION_EXCLUDE,
				'chat_profile'                  => self::DISPOSITION_EXCLUDE,
				'status'                        => self::DISPOSITION_COPY,
				'assigned_operator_id'          => self::DISPOSITION_COPY,
				'topic_creation_state'          => self::DISPOSITION_EXCLUDE,
				'telegram_topic_id'             => self::DISPOSITION_EXCLUDE,
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
				'topic_lifecycle_state'         => self::DISPOSITION_EXCLUDE,
				'topic_lifecycle_code'          => self::DISPOSITION_EXCLUDE,
				'topic_delete_claim_expires_at' => self::DISPOSITION_EXCLUDE,
				'owner_active_slot'             => self::DISPOSITION_EXCLUDE,
			),
			'conversation_messages' => array(
				'id'                      => self::DISPOSITION_EXCLUDE,
				'conversation_id'         => self::DISPOSITION_EXCLUDE,
				'message_uuid'            => self::DISPOSITION_EXCLUDE,
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
				'id'               => self::DISPOSITION_EXCLUDE,
				'conversation_id'  => self::DISPOSITION_EXCLUDE,
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
