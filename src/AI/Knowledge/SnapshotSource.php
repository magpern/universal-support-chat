<?php
/**
 * Read seam for approved knowledge snapshots (ADR-0018 §9, SC-M07).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\AI\Knowledge;

/**
 * The narrow read contract the {@see KnowledgeRetriever} depends on: the
 * bounded set of `approved` rows, decrypted into memory. Implemented by
 * {@see KnowledgeSourceRepository}; a fake implementation is used in unit
 * tests so the ranker can be exercised without a database or the vault.
 */
interface SnapshotSource {

	/**
	 * The approved rows, decrypted into memory for ranking.
	 *
	 * @param int $limit Hard cap on rows scanned.
	 *
	 * @return array<int, array{id: int, source_uuid: string, label: string, text: string, content_checksum: string}>
	 */
	public function approved_snapshots( int $limit = 200 ): array;
}
