<?php
/**
 * Read-only validators for migrated data — never mutate any table.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Migration;

use UniversalSupportChat\Conversations\MessageRepository;
use UniversalSupportChat\Conversations\NoteRepository;

/**
 * Backs both the `legacy-migrate validate` CLI subcommand's read-only
 * report and Phase B's own gating checks. Every method here is a pure
 * query — none of them writes to any Support Chat table.
 */
final class LegacyMigrationValidator {

	/**
	 * The fixed, stable vocabulary every `error_reason` value must belong
	 * to — a structural guard against a future code change accidentally
	 * interpolating content (a plaintext excerpt, a raw exception message)
	 * into this durable, queryable audit column (plan §4.5).
	 */
	private const KNOWN_ERROR_REASONS = array(
		'export_decrypt_failed',
		'export_export_failed',
		'ownerless_conversation_unsupported',
		'note_operator_user_id_null_unsupported',
	);

	/**
	 * Constructor.
	 *
	 * @param MessageRepository                   $messages    Target message reads.
	 * @param NoteRepository                      $notes       Target note reads.
	 * @param LegacyMigrationMessageMapRepository  $message_map Message/note correspondence reads.
	 */
	public function __construct(
		private readonly MessageRepository $messages,
		private readonly NoteRepository $notes,
		private readonly LegacyMigrationMessageMapRepository $message_map
	) {}

	/**
	 * Whether `LegacyFieldMap::registry()` itself only uses recognized
	 * disposition constants — a structural self-consistency check, not a
	 * comparison against Universal Telegram's real schema (that is
	 * `Interop\SchemaInventoryTest`'s job, which needs both plugins loaded).
	 *
	 * @return array<int, string> Any malformed registry entries found, empty if none.
	 */
	public function validate_registry_self_consistency(): array {
		$known = array(
			LegacyFieldMap::DISPOSITION_COPY,
			LegacyFieldMap::DISPOSITION_COPY_CONDITIONAL,
			LegacyFieldMap::DISPOSITION_REMAP,
			LegacyFieldMap::DISPOSITION_TRANSFORM_TO_CONSTANT,
			LegacyFieldMap::DISPOSITION_PRESERVE_FOR_MAP,
			LegacyFieldMap::DISPOSITION_EXCLUDE,
		);

		$errors = array();

		foreach ( LegacyFieldMap::registry() as $table => $columns ) {
			foreach ( $columns as $column => $disposition ) {
				if ( ! in_array( $disposition, $known, true ) ) {
					$errors[] = $table . '.' . $column . ' has an unrecognized disposition: ' . $disposition;
				}
			}
		}

		return $errors;
	}

	/**
	 * Whether a map row's recorded source/target counts agree.
	 *
	 * @param LegacyMigrationMapEntry $entry Map row to check.
	 */
	public function validate_counts( LegacyMigrationMapEntry $entry ): bool {
		return $entry->message_count_source() === $entry->message_count_target()
			&& $entry->note_count_source() === $entry->note_count_target();
	}

	/**
	 * Whether every message/note this map row's correspondence table claims
	 * to have migrated actually resolves to a real, decryptable target row.
	 *
	 * @param LegacyMigrationMapEntry $entry Map row to check.
	 */
	public function validate_correspondence( LegacyMigrationMapEntry $entry ): bool {
		foreach ( $this->message_map->source_ids_for_conversation( $entry->id(), LegacyMigrationMessageMapRepository::KIND_MESSAGE ) as $source_id ) {
			$target_uuid = $this->message_map->target_uuid_for_source( $entry->id(), LegacyMigrationMessageMapRepository::KIND_MESSAGE, $source_id );
			if ( null === $target_uuid || null === $this->messages->find_by_uuid( $target_uuid ) ) {
				return false;
			}
		}

		foreach ( $this->message_map->source_ids_for_conversation( $entry->id(), LegacyMigrationMessageMapRepository::KIND_NOTE ) as $source_id ) {
			$target_uuid = $this->message_map->target_uuid_for_source( $entry->id(), LegacyMigrationMessageMapRepository::KIND_NOTE, $source_id );
			if ( null === $target_uuid || null === $this->notes->find_by_uuid( $target_uuid ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a map row's own `error_reason` (if any) belongs to the fixed,
	 * known vocabulary — the structural guard against plaintext or other
	 * content ever landing in this durable audit column.
	 *
	 * @param LegacyMigrationMapEntry $entry Map row to check.
	 */
	public function validate_error_reason_is_known( LegacyMigrationMapEntry $entry ): bool {
		$reason = $entry->error_reason();

		if ( null === $reason ) {
			return true;
		}

		return in_array( $reason, self::KNOWN_ERROR_REASONS, true );
	}

	/**
	 * Transient, in-memory content-integrity comparison between a target
	 * message's currently decrypted body and a freshly re-exported source
	 * plaintext — never persisted, never logged; only the boolean result
	 * is returned (plan §4.5).
	 *
	 * @param string      $target_uuid       Target message UUID.
	 * @param string|null $source_plaintext  Freshly re-fetched legacy plaintext, or null.
	 */
	public function content_matches_message( string $target_uuid, ?string $source_plaintext ): bool {
		$target = $this->messages->find_by_uuid( $target_uuid );

		if ( null === $target ) {
			return false;
		}

		return $target->plaintext_body() === $source_plaintext;
	}
}
