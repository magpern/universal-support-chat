<?php
/**
 * Deterministic, NULL-safe derivation of target idempotency keys for
 * migrated rows.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Migration;

/**
 * `conversations.start_idempotency_key` and `conversation_messages.idempotency_key`
 * are both `CHAR(36)` — the same shape as a UUID, not an arbitrary-length
 * hash column. Deriving a value for a migrated row must produce something
 * that actually fits that column: a SHA-256 hex digest (64 characters)
 * would silently exceed it. This class derives a deterministic value that
 * is exactly 36 characters, UUID-shaped, and collision-resistant (uses the
 * first 128 bits of a SHA-256 digest — the same entropy a real UUIDv4
 * carries), while remaining a pure function of stable source identifiers,
 * never of plaintext content (sc-m03-wp3-wp4-legacy-migration-engine-plan-v1.md §4.1).
 */
final class IdempotencyKeyDeriver {

	/**
	 * Derives a target `start_idempotency_key` for one migrated conversation.
	 * Falls back to the always-unique source `conversation_uuid` when the
	 * source key is `NULL` or empty — a verified, real legacy state
	 * (Universal Telegram's `ConversationRepository::create()` accepts a
	 * `null` start idempotency key by design).
	 *
	 * @param string|null $source_start_idempotency_key The legacy conversation's own start idempotency key, if any.
	 * @param string      $source_conversation_uuid      The legacy conversation's always-unique UUID.
	 */
	public static function for_conversation( ?string $source_start_idempotency_key, string $source_conversation_uuid ): string {
		$basis = ( null !== $source_start_idempotency_key && '' !== $source_start_idempotency_key )
			? $source_start_idempotency_key
			: 'conv:' . $source_conversation_uuid;

		return self::derive( 'legacy-migration:start:' . $basis );
	}

	/**
	 * Derives a target `idempotency_key` for one migrated message, from its
	 * always-unique source `message_uuid`.
	 *
	 * @param string $source_message_uuid The legacy message's own UUID.
	 */
	public static function for_message( string $source_message_uuid ): string {
		return self::derive( 'legacy-migration:message:' . $source_message_uuid );
	}

	/**
	 * A deterministic, UUID-shaped placeholder for a legacy conversation id
	 * whose real UUID is unknown — used only when Universal Telegram's
	 * export boundary itself returns a typed per-conversation error entry
	 * (`{"id": ..., "error": ...}`, no UUID included). Never treated as the
	 * legacy conversation's real `conversation_uuid`; exists solely so the
	 * migration map's `source_conversation_uuid` uniqueness constraint can
	 * still record a durable, idempotent audit row for a source id this
	 * engine was never able to read.
	 *
	 * @param int $source_conversation_id The legacy numeric conversation id that failed to export.
	 */
	public static function export_error_placeholder_uuid( int $source_conversation_id ): string {
		return self::derive( 'legacy-migration:export-error:' . $source_conversation_id );
	}

	/**
	 * A deterministic, UUID-shaped placeholder for a legacy internal note.
	 * Unlike conversations and messages, ADR-0008's export shape carries no
	 * UUID for notes (`{"id", "operator_user_id", "body", "created_at"}`
	 * only) — the message-map table's `source_uuid` column is still
	 * populated with this stable, namespaced placeholder so the schema's
	 * shape stays uniform, but it is never treated as a real legacy
	 * identifier, only as a deterministic, collision-resistant key.
	 *
	 * @param int $conversation_map_id The owning conversation's migration map row id.
	 * @param int $source_note_id      The legacy numeric note id.
	 */
	public static function note_placeholder_uuid( int $conversation_map_id, int $source_note_id ): string {
		return self::derive( 'legacy-migration:note:' . $conversation_map_id . ':' . $source_note_id );
	}

	/**
	 * Derives a UUID-shaped (36-character), deterministic value from an
	 * arbitrary basis string.
	 *
	 * @param string $basis The pre-namespaced input to hash.
	 */
	private static function derive( string $basis ): string {
		$hex32 = substr( hash( 'sha256', $basis ), 0, 32 );

		return sprintf(
			'%s-%s-%s-%s-%s',
			substr( $hex32, 0, 8 ),
			substr( $hex32, 8, 4 ),
			substr( $hex32, 12, 4 ),
			substr( $hex32, 16, 4 ),
			substr( $hex32, 20, 12 )
		);
	}
}
