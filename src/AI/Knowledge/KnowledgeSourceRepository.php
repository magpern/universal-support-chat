<?php
/**
 * Approved knowledge source repository (ADR-0018 §9, SC-M07).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\AI\Knowledge;

use UniversalSupportChat\Core\Security\CredentialState;
use UniversalSupportChat\Core\Security\CredentialUnavailableException;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Persistence\Migrator;

/**
 * Owns `universal_support_chat_knowledge_sources`.
 *
 * The approved plain-text snapshot is stored **only** as a
 * {@see CredentialVault} envelope in `indexed_text_ciphertext`, AAD context
 * `knowledge_source:<source_uuid>`. There is no plaintext content column and
 * no visitor / PII column (ADR-0018 schema verification boundary).
 *
 * Indexing (canonical-text extraction, WordPress hooks, reindex) lives in
 * {@see KnowledgeIndexer} (SC-M07 WP5); ranking lives in
 * {@see KnowledgeRetriever} (SC-M07 WP5). This repository is the persistence
 * seam both use.
 */
final class KnowledgeSourceRepository implements SnapshotSource {

	public const TYPE_POST    = 'post';
	public const TYPE_SNIPPET = 'snippet';

	public const STATUS_APPROVED = 'approved';
	public const STATUS_STALE    = 'stale';
	public const STATUS_REVOKED  = 'revoked';

	/**
	 * Credential vault used to encrypt/decrypt the indexed snapshot.
	 *
	 * @var CredentialVault
	 */
	private CredentialVault $vault;

	/**
	 * Constructor.
	 *
	 * @param CredentialVault $vault Credential vault.
	 */
	public function __construct( CredentialVault $vault ) {
		$this->vault = $vault;
	}

	/**
	 * Fully-qualified table name.
	 */
	private function table(): string {
		global $wpdb;

		return $wpdb->prefix . Migrator::AI_KNOWLEDGE_SOURCES_TABLE;
	}

	/**
	 * The AAD context binding a ciphertext to one source.
	 *
	 * @param string $source_uuid Source UUID.
	 */
	public static function vault_context( string $source_uuid ): string {
		return 'knowledge_source:' . $source_uuid;
	}

	/**
	 * Inserts a new approved source with its first indexed snapshot.
	 *
	 * @param string      $source_type One of TYPE_*.
	 * @param int|null    $post_id     Post id for TYPE_POST.
	 * @param string      $label       Plain-text display label.
	 * @param string      $canonical_text Canonical extracted plain text.
	 * @param int         $approved_by WordPress user id.
	 *
	 * @return string The new source UUID.
	 *
	 * @throws CredentialUnavailableException If the vault key cannot be resolved.
	 */
	public function create_approved( string $source_type, ?int $post_id, string $label, string $canonical_text, int $approved_by ): string {
		global $wpdb;

		$source_uuid = wp_generate_uuid4();
		$now         = gmdate( 'Y-m-d H:i:s' );
		$ciphertext  = $this->vault->encrypt( $canonical_text, self::vault_context( $source_uuid ) );

		$wpdb->insert(
			$this->table(),
			array(
				'source_uuid'             => $source_uuid,
				'source_type'             => $source_type,
				'post_id'                 => $post_id,
				'label'                   => $this->trim_label( $label ),
				'indexed_text_ciphertext' => $ciphertext,
				'content_checksum'        => self::checksum( $canonical_text ),
				'status'                  => self::STATUS_APPROVED,
				'approved_by'             => $approved_by,
				'approved_at'             => $now,
				'last_indexed_at'         => $now,
				'created_at'              => $now,
				'updated_at'              => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		return $source_uuid;
	}

	/**
	 * Re-indexes a source: new ciphertext, new checksum, status back to
	 * approved. Keeps the same id and source_uuid (ADR-0018 §9 provenance).
	 *
	 * @param string $source_uuid    Source UUID.
	 * @param string $label          Refreshed label.
	 * @param string $canonical_text Refreshed canonical text.
	 *
	 * @throws CredentialUnavailableException If the vault key cannot be resolved.
	 */
	public function reindex( string $source_uuid, string $label, string $canonical_text ): void {
		global $wpdb;

		$wpdb->update(
			$this->table(),
			array(
				'label'                   => $this->trim_label( $label ),
				'indexed_text_ciphertext' => $this->vault->encrypt( $canonical_text, self::vault_context( $source_uuid ) ),
				'content_checksum'        => self::checksum( $canonical_text ),
				'status'                  => self::STATUS_APPROVED,
				'last_indexed_at'         => gmdate( 'Y-m-d H:i:s' ),
				'updated_at'              => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'source_uuid' => $source_uuid ),
			array( '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Marks a source stale (content changed; excluded until re-approval).
	 *
	 * @param int $id Row id.
	 */
	public function mark_stale( int $id ): void {
		global $wpdb;

		$wpdb->update(
			$this->table(),
			array(
				'status'     => self::STATUS_STALE,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Revokes a source: status revoked and the derived ciphertext purged
	 * immediately. The row survives as a labelled tombstone so historical
	 * provenance ids still resolve to a name (ADR-0018 §9).
	 *
	 * @param int $id Row id.
	 */
	public function revoke( int $id ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table()} SET status = %s, indexed_text_ciphertext = NULL, updated_at = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_REVOKED,
				gmdate( 'Y-m-d H:i:s' ),
				$id
			)
		);
	}

	/**
	 * Hard-deletes a source row (explicit operator removal). The id survives
	 * only as an opaque integer inside historical `ai_turns.source_ids`.
	 *
	 * @param int $id Row id.
	 */
	public function delete( int $id ): void {
		global $wpdb;

		$wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Finds the row for a WordPress post, if any.
	 *
	 * @param int $post_id Post id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_by_post( int $post_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE source_type = 'post' AND post_id = %d", $post_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Resolves a set of row ids to their current labels (Hub provenance).
	 *
	 * @param array<int, int> $ids Row ids.
	 *
	 * @return array<int, string> id => label
	 */
	public function labels_for_ids( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( array() === $ids ) {
			return array();
		}

		// $ids is already cast to a list of positive integers above, so the
		// IN() list is safe to interpolate directly.
		$in_list = implode( ',', $ids );
		$rows    = $wpdb->get_results(
			"SELECT id, label, status, content_checksum FROM {$this->table()} WHERE id IN ({$in_list})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['id'] ] = (string) $row['label'];
		}

		return $out;
	}

	/**
	 * The approved rows, decrypted into memory for ranking. Rows whose
	 * ciphertext cannot be decrypted are skipped (fail-closed).
	 *
	 * @param int $limit Hard cap on rows scanned.
	 *
	 * @return array<int, array{id: int, source_uuid: string, label: string, text: string, content_checksum: string}>
	 */
	public function approved_snapshots( int $limit = 200 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, source_uuid, label, indexed_text_ciphertext, content_checksum FROM {$this->table()} WHERE status = %s AND indexed_text_ciphertext IS NOT NULL ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_APPROVED,
				$limit
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$result = $this->vault->decrypt(
				(string) $row['indexed_text_ciphertext'],
				self::vault_context( (string) $row['source_uuid'] )
			);

			if ( CredentialState::AVAILABLE !== $result->state() ) {
				continue;
			}

			$plaintext = $result->plaintext();

			if ( null === $plaintext ) {
				continue;
			}

			$out[] = array(
				'id'               => (int) $row['id'],
				'source_uuid'      => (string) $row['source_uuid'],
				'label'            => (string) $row['label'],
				'text'             => $plaintext,
				'content_checksum' => (string) $row['content_checksum'],
			);
		}

		return $out;
	}

	/**
	 * A single row by id (metadata only — no decrypt).
	 *
	 * @param int $id Row id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * All rows for the admin list, newest first. Metadata only — the
	 * ciphertext is never selected here.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all_for_admin(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT id, source_uuid, source_type, post_id, label, content_checksum, status, approved_at, last_indexed_at FROM {$this->table()} ORDER BY id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Approved rows for a WordPress post-id list (checksum recheck sweep).
	 *
	 * @return array<int, array{id: int, post_id: int, content_checksum: string}>
	 */
	public function approved_post_rows(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT id, post_id, content_checksum FROM {$this->table()} WHERE source_type = 'post' AND status = 'approved' AND post_id IS NOT NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array_map(
			static function ( $row ): array {
				return array(
					'id'               => (int) $row['id'],
					'post_id'          => (int) $row['post_id'],
					'content_checksum' => (string) $row['content_checksum'],
				);
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Row counts grouped by status (safe aggregate for Diagnostics / Hub).
	 *
	 * @return array<string, int>
	 */
	public function count_by_status(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT status, COUNT(*) AS n FROM {$this->table()} GROUP BY status", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$out = array(
			self::STATUS_APPROVED => 0,
			self::STATUS_STALE    => 0,
			self::STATUS_REVOKED  => 0,
		);

		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['n'];
		}

		return $out;
	}

	/**
	 * SHA-256 of the canonical plaintext, computed before encryption.
	 *
	 * @param string $canonical_text Canonical extracted plain text.
	 */
	public static function checksum( string $canonical_text ): string {
		return hash( 'sha256', $canonical_text );
	}

	/**
	 * Trims a label to the column width.
	 *
	 * @param string $label Raw label.
	 */
	private function trim_label( string $label ): string {
		$label = trim( wp_strip_all_tags( $label ) );

		return mb_substr( $label, 0, 191 );
	}
}
