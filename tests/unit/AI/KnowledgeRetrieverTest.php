<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Unit\AI;

use PHPUnit\Framework\TestCase;
use UniversalSupportChat\AI\Knowledge\KnowledgeRetriever;
use UniversalSupportChat\AI\Knowledge\SnapshotSource;

/**
 * SC-M07 WP5 — bounded keyword-overlap retrieval (not "RAG"): ranking,
 * character budget, and the tokeniser. No database, no vault.
 */
final class KnowledgeRetrieverTest extends TestCase {

	private function source( array $snapshots ): SnapshotSource {
		return new class( $snapshots ) implements SnapshotSource {

			/** @var array<int, array<string, mixed>> */
			private array $snapshots;

			public function __construct( array $snapshots ) {
				$this->snapshots = $snapshots;
			}

			public function approved_snapshots( int $limit = 200 ): array {
				return array_slice( $this->snapshots, 0, $limit );
			}
		};
	}

	private function snapshot( int $id, string $label, string $text ): array {
		return array(
			'id'               => $id,
			'source_uuid'      => "uuid-{$id}",
			'label'            => $label,
			'text'             => $text,
			'content_checksum' => str_repeat( (string) $id, 64 ),
		);
	}

	public function test_ranks_by_keyword_overlap(): void {
		$retriever = new KnowledgeRetriever(
			$this->source(
				array(
					$this->snapshot( 1, 'Shipping', 'We ship parcels worldwide including Norway and Sweden.' ),
					$this->snapshot( 2, 'Returns', 'Returns are accepted within thirty days of delivery.' ),
					$this->snapshot( 3, 'Careers', 'We are hiring engineers in Berlin.' ),
				)
			)
		);

		$hits = $retriever->retrieve( 'Do you ship to Norway?', 5000 );

		$this->assertNotEmpty( $hits );
		$this->assertSame( 1, $hits[0]['id'] );
		$this->assertSame( 'Shipping', $hits[0]['label'] );
		$this->assertNotContains( 3, array_column( $hits, 'id' ) );
	}

	public function test_no_query_tokens_returns_nothing(): void {
		$retriever = new KnowledgeRetriever( $this->source( array( $this->snapshot( 1, 'X', 'content here' ) ) ) );

		$this->assertSame( array(), $retriever->retrieve( 'the and it is', 5000 ) );
	}

	public function test_respects_the_character_budget(): void {
		$long      = str_repeat( 'shipping ', 400 );
		$retriever = new KnowledgeRetriever(
			$this->source(
				array(
					$this->snapshot( 1, 'A', $long ),
					$this->snapshot( 2, 'B', $long ),
				)
			)
		);

		$hits  = $retriever->retrieve( 'shipping question', 100 );
		$total = array_sum( array_map( static fn ( $h ) => mb_strlen( $h['text'] ), $hits ) );

		$this->assertLessThanOrEqual( 100, $total );
	}

	public function test_caps_the_number_of_sources(): void {
		$snapshots = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$snapshots[] = $this->snapshot( $i, "S{$i}", 'shipping parcels worldwide' );
		}

		$hits = ( new KnowledgeRetriever( $this->source( $snapshots ) ) )->retrieve( 'shipping', 100000, 4 );

		$this->assertCount( 4, $hits );
	}

	public function test_result_carries_a_short_checksum_prefix_not_the_full_checksum(): void {
		$retriever = new KnowledgeRetriever( $this->source( array( $this->snapshot( 7, 'X', 'shipping parcels' ) ) ) );

		$hits = $retriever->retrieve( 'shipping', 5000 );

		$this->assertSame( 12, strlen( $hits[0]['checksum_prefix'] ) );
	}

	public function test_tokeniser_drops_short_and_stop_words_and_dedupes(): void {
		$this->assertSame(
			array( 'ship', 'norway', 'orders' ),
			KnowledgeRetriever::tokenise( 'Do you SHIP to Norway for orders, ship orders?' )
		);
	}
}
