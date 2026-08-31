<?php
/**
 * Immutable AI generation request (ADR-0018 §7).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\AI\Provider;

/**
 * A fully-assembled request handed to an {@see AiProvider}. Carries the
 * server-owned system policy plus the ordered conversation messages
 * (each `['role' => 'system'|'user'|'assistant', 'content' => string]`),
 * with retrieved knowledge and visitor text already fenced as data by the
 * prompt assembler — never as instructions (ADR-0018 §11).
 */
final class AiRequest {

	/**
	 * Server-owned system/developer instructions.
	 *
	 * @var string
	 */
	private string $system_policy;

	/**
	 * Ordered conversation messages.
	 *
	 * @var array<int, array{role: string, content: string}>
	 */
	private array $messages;

	/**
	 * Model id.
	 *
	 * @var string
	 */
	private string $model;

	/**
	 * Hard output-token cap.
	 *
	 * @var int
	 */
	private int $max_output_tokens;

	/**
	 * Per-request timeout in seconds.
	 *
	 * @var int
	 */
	private int $timeout_seconds;

	/**
	 * Sampling temperature — fixed low value in the adapter, never an
	 * operator setting (ADR-0018 §8).
	 *
	 * @var float
	 */
	private float $temperature;

	/**
	 * Constructor.
	 *
	 * @param string                                            $system_policy     Server-owned system/developer instructions.
	 * @param array<int, array{role: string, content: string}>  $messages          Ordered conversation messages.
	 * @param string                                            $model             Model id (validated against the allow-list by callers).
	 * @param int                                               $max_output_tokens Hard output-token cap.
	 * @param int                                               $timeout_seconds   Per-request timeout.
	 * @param float                                             $temperature       Sampling temperature (fixed by the adapter).
	 */
	public function __construct(
		string $system_policy,
		array $messages,
		string $model,
		int $max_output_tokens,
		int $timeout_seconds,
		float $temperature = 0.2
	) {
		$this->system_policy     = $system_policy;
		$this->messages          = array_values( $messages );
		$this->model             = $model;
		$this->max_output_tokens = $max_output_tokens;
		$this->timeout_seconds   = $timeout_seconds;
		$this->temperature       = $temperature;
	}

	/**
	 * Server-owned system policy text.
	 */
	public function system_policy(): string {
		return $this->system_policy;
	}

	/**
	 * Ordered conversation messages.
	 *
	 * @return array<int, array{role: string, content: string}>
	 */
	public function messages(): array {
		return $this->messages;
	}

	/**
	 * Model id.
	 */
	public function model(): string {
		return $this->model;
	}

	/**
	 * Hard output-token cap.
	 */
	public function max_output_tokens(): int {
		return $this->max_output_tokens;
	}

	/**
	 * Per-request timeout in seconds.
	 */
	public function timeout_seconds(): int {
		return $this->timeout_seconds;
	}

	/**
	 * Sampling temperature.
	 */
	public function temperature(): float {
		return $this->temperature;
	}

	/**
	 * The full message list as the provider wire format expects it: the
	 * system policy first, then the conversation messages.
	 *
	 * @return array<int, array{role: string, content: string}>
	 */
	public function wire_messages(): array {
		return array_merge(
			array(
				array(
					'role'    => 'system',
					'content' => $this->system_policy,
				),
			),
			$this->messages
		);
	}
}
