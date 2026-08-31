<?php
/**
 * Immutable AI generation result (ADR-0018 §7).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\AI\Provider;

/**
 * The outcome of an {@see AiProvider::generate()} call. A provider-side
 * failure is a result in the ERROR outcome, never a thrown exception.
 */
final class AiResult {

	public const OUTCOME_ANSWER  = 'answer';
	public const OUTCOME_REFUSAL = 'refusal';
	public const OUTCOME_ERROR   = 'error';

	/**
	 * One of the OUTCOME_* constants.
	 *
	 * @var string
	 */
	private string $outcome;

	/**
	 * The answer text (ANSWER outcome only).
	 *
	 * @var string|null
	 */
	private ?string $answer_text;

	/**
	 * Whether the model explicitly asked to hand off to a human.
	 *
	 * @var bool
	 */
	private bool $needs_human;

	/**
	 * Normalised finish reason.
	 *
	 * @var string
	 */
	private string $finish_reason;

	/**
	 * Provider-error class (ERROR outcome only).
	 *
	 * @var string|null
	 */
	private ?string $error_class;

	/**
	 * Prompt token count.
	 *
	 * @var int
	 */
	private int $prompt_tokens;

	/**
	 * Completion token count.
	 *
	 * @var int
	 */
	private int $completion_tokens;

	/**
	 * Constructor. Use the named factories rather than calling directly.
	 *
	 * @param string      $outcome           One of the OUTCOME_* constants.
	 * @param string|null $answer_text       The answer text (ANSWER only).
	 * @param bool        $needs_human       Whether the model asked to hand off.
	 * @param string      $finish_reason     Normalised {@see AiFinishReason}.
	 * @param string|null $error_class       {@see AiErrorClass} (ERROR only).
	 * @param int         $prompt_tokens     Prompt token count.
	 * @param int         $completion_tokens Completion token count.
	 */
	private function __construct(
		string $outcome,
		?string $answer_text,
		bool $needs_human,
		string $finish_reason,
		?string $error_class,
		int $prompt_tokens,
		int $completion_tokens
	) {
		$this->outcome           = $outcome;
		$this->answer_text       = $answer_text;
		$this->needs_human       = $needs_human;
		$this->finish_reason     = $finish_reason;
		$this->error_class       = $error_class;
		$this->prompt_tokens     = $prompt_tokens;
		$this->completion_tokens = $completion_tokens;
	}

	/**
	 * A usable answer.
	 *
	 * @param string $answer_text       The answer text.
	 * @param string $finish_reason     Normalised finish reason.
	 * @param int    $prompt_tokens     Prompt token count.
	 * @param int    $completion_tokens Completion token count.
	 */
	public static function answer( string $answer_text, string $finish_reason = AiFinishReason::STOP, int $prompt_tokens = 0, int $completion_tokens = 0 ): self {
		return new self( self::OUTCOME_ANSWER, $answer_text, false, $finish_reason, null, $prompt_tokens, $completion_tokens );
	}

	/**
	 * The model declined or asked for a human.
	 *
	 * @param bool $needs_human       Whether the model explicitly asked to hand off.
	 * @param int  $prompt_tokens     Prompt token count.
	 * @param int  $completion_tokens Completion token count.
	 */
	public static function refusal( bool $needs_human = true, int $prompt_tokens = 0, int $completion_tokens = 0 ): self {
		return new self( self::OUTCOME_REFUSAL, null, $needs_human, AiFinishReason::NEEDS_HUMAN, null, $prompt_tokens, $completion_tokens );
	}

	/**
	 * A provider-side failure.
	 *
	 * @param string $error_class One of the {@see AiErrorClass} constants.
	 */
	public static function error( string $error_class ): self {
		return new self( self::OUTCOME_ERROR, null, false, AiFinishReason::ERROR, $error_class, 0, 0 );
	}

	/**
	 * Outcome.
	 */
	public function outcome(): string {
		return $this->outcome;
	}

	/**
	 * Answer text, present only for the ANSWER outcome.
	 */
	public function answer_text(): ?string {
		return $this->answer_text;
	}

	/**
	 * Whether the model explicitly asked to hand off to a human.
	 */
	public function needs_human(): bool {
		return $this->needs_human;
	}

	/**
	 * Normalised finish reason.
	 */
	public function finish_reason(): string {
		return $this->finish_reason;
	}

	/**
	 * Provider-error class, present only for the ERROR outcome.
	 */
	public function error_class(): ?string {
		return $this->error_class;
	}

	/**
	 * Whether this error is a transient one worth retrying.
	 */
	public function is_retryable(): bool {
		return self::OUTCOME_ERROR === $this->outcome
			&& null !== $this->error_class
			&& AiErrorClass::is_retryable( $this->error_class );
	}

	/**
	 * Prompt token count.
	 */
	public function prompt_tokens(): int {
		return $this->prompt_tokens;
	}

	/**
	 * Completion token count.
	 */
	public function completion_tokens(): int {
		return $this->completion_tokens;
	}
}
