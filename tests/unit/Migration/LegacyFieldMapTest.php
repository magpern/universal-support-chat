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

	public function test_forbidden_fields_are_excluded(): void {
		$forbidden = array(
			'conversations'         => array( 'secret_hash', 'chat_profile', 'session_ref', 'consent_state', 'ai_participation_state', 'ai_ack_policy_version' ),
			'conversation_messages' => array( 'outbound_message_uuid', 'telegram_message_id', 'telegram_sender_user_id' ),
		);

		$registry = LegacyFieldMap::registry();

		foreach ( $forbidden as $table => $columns ) {
			foreach ( $columns as $column ) {
				$this->assertSame( LegacyFieldMap::DISPOSITION_EXCLUDE, $registry[ $table ][ $column ] );
			}
		}
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
}
