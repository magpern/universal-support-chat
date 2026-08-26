<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Migration;

use PHPUnit\Framework\TestCase;
use UniversalSupportChat\Migration\LegacyFieldMap;

/**
 * Structural self-consistency of the registry itself. Whether the registry
 * actually matches Universal Telegram's *real, live* schema is
 * `Interop\SchemaInventoryTest`'s job (needs both plugins loaded); this
 * test only guards against a malformed or incomplete registry entry.
 *
 * @covers \UniversalSupportChat\Migration\LegacyFieldMap
 */
final class LegacyFieldMapTest extends TestCase {

	private const KNOWN_DISPOSITIONS = array(
		LegacyFieldMap::DISPOSITION_COPY,
		LegacyFieldMap::DISPOSITION_COPY_CONDITIONAL,
		LegacyFieldMap::DISPOSITION_REMAP,
		LegacyFieldMap::DISPOSITION_TRANSFORM_TO_CONSTANT,
		LegacyFieldMap::DISPOSITION_PRESERVE_FOR_MAP,
		LegacyFieldMap::DISPOSITION_EXCLUDE,
	);

	public function test_registry_covers_exactly_the_three_expected_tables(): void {
		$this->assertSame(
			array( 'conversations', 'conversation_messages', 'conversation_notes' ),
			array_keys( LegacyFieldMap::registry() )
		);
	}

	public function test_every_disposition_is_a_known_constant(): void {
		foreach ( LegacyFieldMap::registry() as $table => $columns ) {
			foreach ( $columns as $column => $disposition ) {
				$this->assertContains(
					$disposition,
					self::KNOWN_DISPOSITIONS,
					"{$table}.{$column} has an unrecognized disposition: {$disposition}"
				);
			}
		}
	}

	public function test_no_table_has_duplicate_or_empty_column_names(): void {
		foreach ( LegacyFieldMap::registry() as $table => $columns ) {
			$names = array_keys( $columns );

			$this->assertSame( array_unique( $names ), $names, "{$table} has a duplicate column entry." );
			$this->assertNotContains( '', $names, "{$table} has an empty column name." );
		}
	}

	/**
	 * Universal Telegram's real, physical column count: 27 on
	 * `conversations`, 11 on `conversation_messages`, 5 on
	 * `conversation_notes` — 43 total. `Interop\SchemaInventoryTest` is
	 * the live, authoritative check that the registry actually matches
	 * this count against the real schema; this is the fast, offline guard
	 * that the registry itself claims the right total.
	 */
	public function test_registry_covers_exactly_forty_three_real_columns(): void {
		$registry = LegacyFieldMap::registry();

		$this->assertCount( 27, $registry['conversations'] );
		$this->assertCount( 11, $registry['conversation_messages'] );
		$this->assertCount( 5, $registry['conversation_notes'] );
	}

	/**
	 * A snapshot of Universal Telegram's real schema as of ADR-0008's pin
	 * (`src/Persistence/Migrator.php`, Universal Telegram repository).
	 * `Interop\SchemaInventoryTest` is the live, authoritative check; this
	 * is a fast, offline guard against an obviously incomplete registry.
	 */
	public function test_registered_columns_include_every_known_conversations_column(): void {
		$expected = array(
			'id',
			'conversation_uuid',
			'secret_hash',
			'bot_id',
			'destination_id',
			'chat_profile',
			'status',
			'assigned_operator_id',
			'topic_creation_state',
			'telegram_topic_id',
			'ai_participation_state',
			'consent_state',
			'session_ref',
			'created_at',
			'updated_at',
			'resolved_at',
			'expires_at',
			'start_idempotency_key',
			'topic_claim_expires_at',
			'display_name_ciphertext',
			'owner_user_id',
			'assignee_last_seen_message_id',
			'ai_ack_policy_version',
			'topic_lifecycle_state',
			'topic_lifecycle_code',
			'topic_delete_claim_expires_at',
			'owner_active_slot',
		);

		$this->assertEmpty( array_diff( $expected, LegacyFieldMap::registered_columns( 'conversations' ) ) );
	}

	public function test_registered_columns_include_every_known_conversation_messages_column(): void {
		$expected = array(
			'id',
			'conversation_id',
			'message_uuid',
			'direction',
			'body_ciphertext',
			'outbound_message_uuid',
			'telegram_message_id',
			'delivery_state',
			'idempotency_key',
			'created_at',
			'telegram_sender_user_id',
		);

		$this->assertEmpty( array_diff( $expected, LegacyFieldMap::registered_columns( 'conversation_messages' ) ) );
	}

	public function test_registered_columns_include_every_known_conversation_notes_column(): void {
		$expected = array( 'id', 'conversation_id', 'operator_user_id', 'body_ciphertext', 'created_at' );

		$this->assertEmpty( array_diff( $expected, LegacyFieldMap::registered_columns( 'conversation_notes' ) ) );
	}

	public function test_delivery_state_is_transform_to_constant_not_copy(): void {
		$this->assertSame(
			LegacyFieldMap::DISPOSITION_TRANSFORM_TO_CONSTANT,
			LegacyFieldMap::registry()['conversation_messages']['delivery_state']
		);
	}

	public function test_owner_user_id_and_operator_user_id_are_conditional_not_unconditional_copy(): void {
		$this->assertSame( LegacyFieldMap::DISPOSITION_COPY_CONDITIONAL, LegacyFieldMap::registry()['conversations']['owner_user_id'] );
		$this->assertSame( LegacyFieldMap::DISPOSITION_COPY_CONDITIONAL, LegacyFieldMap::registry()['conversation_notes']['operator_user_id'] );
	}

	public function test_assigned_operator_id_is_an_unconditional_copy(): void {
		$this->assertSame( LegacyFieldMap::DISPOSITION_COPY, LegacyFieldMap::registry()['conversations']['assigned_operator_id'] );
	}

	/**
	 * Regression: these fields are retained in `legacy_migration_map` (as
	 * `source_conversation_id`/`source_conversation_uuid`/`legacy_bot_id`/
	 * `legacy_destination_id`/`legacy_topic_creation_state`/
	 * `legacy_telegram_topic_id`/`legacy_topic_lifecycle_state`) — never
	 * truly discarded, so `exclude` was never a truthful disposition for
	 * them, even though none of them is copied into the target
	 * `conversations` row itself.
	 */
	public function test_conversation_fields_retained_in_the_migration_map_are_preserve_for_map_not_excluded(): void {
		$expected = array(
			'id',
			'conversation_uuid',
			'bot_id',
			'destination_id',
			'topic_creation_state',
			'telegram_topic_id',
			'topic_lifecycle_state',
		);

		$registry = LegacyFieldMap::registry()['conversations'];

		foreach ( $expected as $column ) {
			$this->assertSame(
				LegacyFieldMap::DISPOSITION_PRESERVE_FOR_MAP,
				$registry[ $column ],
				"conversations.{$column} should be preserve_for_map."
			);
		}
	}

	/**
	 * Regression: `id`/`message_uuid` (messages) and `id` (notes) are
	 * retained verbatim in `legacy_migration_message_map` as
	 * `source_id`/`source_uuid` — the authoritative per-row correspondence
	 * `assignee_last_seen_message_id` remapping and Phase B's drift
	 * detection both depend on. `conversation_id` on both tables is never
	 * copied or separately persisted (Universal Telegram's own ADR-0008
	 * export shape does not even emit a per-row `conversation_id` field),
	 * but the parent/child relationship it expresses is reconstructed via
	 * the conversation-level map, which is what `remap` means here — never
	 * `exclude`, and never `preserve_for_map` (no raw value survives to
	 * preserve).
	 */
	public function test_message_and_note_row_identity_fields_are_preserve_for_map_or_remap_not_excluded(): void {
		$messages = LegacyFieldMap::registry()['conversation_messages'];
		$notes    = LegacyFieldMap::registry()['conversation_notes'];

		$this->assertSame( LegacyFieldMap::DISPOSITION_PRESERVE_FOR_MAP, $messages['id'] );
		$this->assertSame( LegacyFieldMap::DISPOSITION_PRESERVE_FOR_MAP, $messages['message_uuid'] );
		$this->assertSame( LegacyFieldMap::DISPOSITION_REMAP, $messages['conversation_id'] );

		$this->assertSame( LegacyFieldMap::DISPOSITION_PRESERVE_FOR_MAP, $notes['id'] );
		$this->assertSame( LegacyFieldMap::DISPOSITION_REMAP, $notes['conversation_id'] );
	}

	/**
	 * Regression: a field is `exclude` only when this engine never reads
	 * it into memory at all and never writes it anywhere, including its
	 * own metadata tables — not merely "not copied into the target row."
	 * These fields genuinely meet that bar: none of them appears in
	 * `legacy_migration_map`, `legacy_migration_message_map`, or any
	 * target table.
	 */
	public function test_genuinely_unused_fields_remain_excluded(): void {
		$genuinely_excluded = array(
			'conversations'         => array( 'secret_hash', 'chat_profile', 'ai_participation_state', 'consent_state', 'session_ref', 'topic_claim_expires_at', 'display_name_ciphertext', 'ai_ack_policy_version', 'topic_lifecycle_code', 'topic_delete_claim_expires_at', 'owner_active_slot' ),
			'conversation_messages' => array( 'outbound_message_uuid', 'telegram_message_id', 'telegram_sender_user_id' ),
		);

		$registry = LegacyFieldMap::registry();

		foreach ( $genuinely_excluded as $table => $columns ) {
			foreach ( $columns as $column ) {
				$this->assertSame( LegacyFieldMap::DISPOSITION_EXCLUDE, $registry[ $table ][ $column ] );
			}
		}
	}
}
