<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Migration\Support;

use UniversalSupportChat\Migration\LegacyBindingImportClient;
use UniversalSupportChat\Migration\LegacyBindingImportUnavailableException;

/**
 * The test seam Support Chat ADR-0009 authorizes for exercising
 * `LegacyBindingImportService` without Universal Telegram loaded — never
 * registered for production use anywhere in `Core\Plugin`.
 */
final class FakeLegacyBindingImportClient implements LegacyBindingImportClient {

	/**
	 * Queued outcomes, keyed by `source_conversation_id`.
	 *
	 * @var array<int, string>
	 */
	private array $outcomes = array();

	/**
	 * @var array<int, array<int, array{source_conversation_id:int, bot_id:int, destination_id:int, telegram_topic_id:int, support_conversation_uuid:string}>>
	 */
	public array $received_batches = array();

	private bool $unavailable = false;

	/**
	 * Queues a specific outcome for one candidate.
	 */
	public function queue_outcome( int $source_conversation_id, string $outcome ): self {
		$this->outcomes[ $source_conversation_id ] = $outcome;

		return $this;
	}

	/**
	 * Makes every subsequent call throw, simulating Universal Telegram
	 * inactive/unavailable.
	 */
	public function make_unavailable(): self {
		$this->unavailable = true;

		return $this;
	}

	/**
	 * {@inheritDoc}
	 */
	public function import_batch( array $candidates, bool $dry_run ): array {
		$this->received_batches[] = $candidates;

		if ( $this->unavailable ) {
			throw new LegacyBindingImportUnavailableException( 'fake: unavailable' );
		}

		$results = array();
		foreach ( $candidates as $candidate ) {
			$source_id = (int) $candidate['source_conversation_id'];
			$outcome   = $this->outcomes[ $source_id ] ?? 'created';

			$results[] = array(
				'source_conversation_id' => $source_id,
				'outcome'                => $outcome,
				'binding_uuid'           => ( 'created' === $outcome && ! $dry_run ) ? 'fake-binding-uuid-' . $source_id : null,
			);
		}

		return $results;
	}
}
