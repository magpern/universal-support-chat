<?php
/**
 * Bounded keyword-overlap knowledge retrieval (ADR-0018 §9, SC-M07).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\AI\Knowledge;

/**
 * An in-PHP keyword-overlap ranker over the administrator-approved
 * allow-list. **Not** embeddings, a vector store, semantic search, or
 * chunking — genuine vector retrieval stays deferred to SC-AI3. The query
 * is the conversation's last visitor message; the result is a bounded set
 * within a fixed character budget.
 *
 * The retrieved text is handed to the prompt assembler as fenced data,
 * never as instructions (ADR-0018 §11).
 */
final class KnowledgeRetriever {

	/**
	 * Very small English stop-word list — enough to stop the ranker keying
	 * on "the" / "and". Deliberately not a linguistic resource.
	 */
	private const STOP_WORDS = array(
		'the',
		'and',
		'for',
		'are',
		'you',
		'your',
		'our',
		'with',
		'can',
		'do',
		'does',
		'is',
		'it',
		'to',
		'of',
		'a',
		'an',
		'in',
		'on',
		'i',
		'we',
		'my',
		'me',
		'how',
		'what',
		'when',
		'where',
		'why',
		'this',
		'that',
		'have',
		'has',
		'was',
		'be',
	);

	/**
	 * Approved-snapshot read seam.
	 *
	 * @var SnapshotSource
	 */
	private SnapshotSource $repo;

	/**
	 * Constructor.
	 *
	 * @param SnapshotSource $repo Approved-snapshot read seam.
	 */
	public function __construct( SnapshotSource $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Retrieves the best-matching approved snippets for a query, within a
	 * character budget.
	 *
	 * @param string $query       The conversation's last visitor message.
	 * @param int    $char_budget Total character budget across all snippets.
	 * @param int    $max_sources Hard cap on the number of snippets returned.
	 *
	 * @return array<int, array{id: int, label: string, text: string, checksum_prefix: string}>
	 */
	public function retrieve( string $query, int $char_budget, int $max_sources = 4 ): array {
		$tokens = self::tokenise( $query );

		if ( array() === $tokens ) {
			return array();
		}

		$scored = array();

		foreach ( $this->repo->approved_snapshots() as $snapshot ) {
			$haystack = self::tokenise( $snapshot['text'] );

			if ( array() === $haystack ) {
				continue;
			}

			$hits  = array_intersect( $tokens, $haystack );
			$score = count( $hits );

			if ( 0 === $score ) {
				continue;
			}

			$scored[] = array(
				'score'    => $score / max( 1, count( $tokens ) ),
				'id'       => $snapshot['id'],
				'label'    => $snapshot['label'],
				'text'     => $snapshot['text'],
				'checksum' => $snapshot['content_checksum'],
			);
		}

		usort(
			$scored,
			static function ( array $a, array $b ): int {
				$by_score = $b['score'] <=> $a['score'];

				return 0 !== $by_score ? $by_score : ( $a['id'] <=> $b['id'] );
			}
		);

		$out       = array();
		$remaining = max( 0, $char_budget );

		foreach ( $scored as $item ) {
			if ( count( $out ) >= $max_sources || $remaining <= 0 ) {
				break;
			}

			$text = $item['text'];

			if ( mb_strlen( $text ) > $remaining ) {
				$text = mb_substr( $text, 0, $remaining );
			}

			$out[]      = array(
				'id'              => $item['id'],
				'label'           => $item['label'],
				'text'            => $text,
				'checksum_prefix' => substr( $item['checksum'], 0, 12 ),
			);
			$remaining -= mb_strlen( $text );
		}

		return $out;
	}

	/**
	 * Lowercases, splits on non-word characters, and drops short / stop
	 * words. Returns a distinct token list.
	 *
	 * @param string $text Input text.
	 *
	 * @return array<int, string>
	 */
	public static function tokenise( string $text ): array {
		$parts = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $text ), -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $parts ) ) {
			return array();
		}

		$tokens = array();

		foreach ( $parts as $part ) {
			if ( mb_strlen( $part ) < 3 || in_array( $part, self::STOP_WORDS, true ) ) {
				continue;
			}

			$tokens[ $part ] = true;
		}

		return array_keys( $tokens );
	}
}
