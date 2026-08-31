<?php
/**
 * Deterministic test provider (ADR-0018 §7; SC-M07 plan v1 §8).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\AI\Provider;

/**
 * The only {@see AiProvider} used in CI. No network. Scripted results are
 * returned in FIFO order; once the script is exhausted the configured
 * default is returned. Every request is recorded for assertions.
 */
final class FakeProvider implements AiProvider {

	/**
	 * FIFO queue of scripted results.
	 *
	 * @var array<int, AiResult>
	 */
	private array $scripted = array();

	/**
	 * Result returned once the script is exhausted.
	 *
	 * @var AiResult
	 */
	private AiResult $fallback;

	/**
	 * Every request seen, in order.
	 *
	 * @var array<int, AiRequest>
	 */
	private array $requests = array();

	/**
	 * Constructor.
	 *
	 * @param AiResult|null $fallback Result returned once the script is exhausted.
	 */
	public function __construct( ?AiResult $fallback = null ) {
		$this->fallback = $fallback ?? AiResult::answer( 'This is a fake assistant answer.' );
	}

	/**
	 * Queues one scripted result.
	 *
	 * @param AiResult $result Result to return on the next call.
	 */
	public function push( AiResult $result ): self {
		$this->scripted[] = $result;

		return $this;
	}

	/**
	 * Records the request and returns the next scripted result, or the
	 * fallback once the script is exhausted.
	 *
	 * @param AiRequest $request The generation request.
	 */
	public function generate( AiRequest $request ): AiResult {
		$this->requests[] = $request;

		if ( array() !== $this->scripted ) {
			return array_shift( $this->scripted );
		}

		return $this->fallback;
	}

	/**
	 * Every request seen so far, in order.
	 *
	 * @return array<int, AiRequest>
	 */
	public function requests(): array {
		return $this->requests;
	}

	/**
	 * The most recent request, or null if none.
	 */
	public function last_request(): ?AiRequest {
		return array() === $this->requests ? null : $this->requests[ count( $this->requests ) - 1 ];
	}

	/**
	 * Number of generate() calls made.
	 */
	public function call_count(): int {
		return count( $this->requests );
	}
}
