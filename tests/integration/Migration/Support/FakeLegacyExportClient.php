<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Migration\Support;

use UniversalSupportChat\Migration\LegacyExportClient;
use UniversalSupportChat\Migration\LegacyExportUnavailableException;

/**
 * A controlled stand-in for Universal Telegram's real export boundary,
 * seeded with canned ADR-0008 §5-shaped conversation entries. Lets
 * `PhaseABackfillService`/`PhaseBReconciliationService` be tested against
 * exact, controlled scenarios (typed errors, ownerless conversations, a
 * retention-nulled message body) without Universal Telegram loaded — the
 * real cross-plugin path is proven separately by
 * `Interop\LegacyExportClientIntegrationTest`, which loads Universal
 * Telegram's actual, merged `LegacyExportServiceV1`.
 */
final class FakeLegacyExportClient implements LegacyExportClient {

	/**
	 * @var array<int, array<string, mixed>>
	 */
	private array $conversations = array();

	/**
	 * @var array<int, array{after: int, limit: int}>
	 */
	private array $calls = array();

	private ?string $refuse_with = null;

	/**
	 * Seeds one conversation entry, in ADR-0008 §5's exact shape.
	 *
	 * @param array<string, mixed> $entry One conversation entry.
	 */
	public function seed( array $entry ): self {
		$this->conversations[] = $entry;

		usort( $this->conversations, static fn( array $a, array $b ): int => $a['id'] <=> $b['id'] );

		return $this;
	}

	/**
	 * Makes every subsequent call throw, simulating Universal Telegram
	 * being inactive/unavailable.
	 */
	public function refuse( string $reason ): self {
		$this->refuse_with = $reason;

		return $this;
	}

	/**
	 * {@inheritDoc}
	 */
	public function export_batch( int $after_source_id, int $limit ): array {
		$this->calls[] = array(
			'after' => $after_source_id,
			'limit' => $limit,
		);

		if ( null !== $this->refuse_with ) {
			throw new LegacyExportUnavailableException( $this->refuse_with );
		}

		$matching = array_values(
			array_filter( $this->conversations, static fn( array $entry ): bool => $entry['id'] > $after_source_id )
		);

		return array(
			'export_schema_version' => 1,
			'conversations'         => array_slice( $matching, 0, $limit ),
		);
	}

	/**
	 * Every call this fake has recorded, in order — for assertions about
	 * cursor pass-through and repeatability.
	 *
	 * @return array<int, array{after: int, limit: int}>
	 */
	public function calls(): array {
		return $this->calls;
	}
}
