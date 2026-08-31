<?php
/**
 * OpenAI chat-completions adapter (ADR-0018 §7).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\AI\Provider;

/**
 * The one concrete {@see AiProvider} that reaches the network. This is the
 * first — and, in SC-M07, only — outbound HTTP surface in `src/`; a
 * structural test confines every `wp_remote_*` call to this directory. It
 * is invoked exclusively by the async turn worker.
 *
 * `generate()` never throws for a provider-side failure: a WP HTTP error,
 * timeout, non-2xx status, or unparseable body is returned as an
 * {@see AiResult} in the ERROR outcome with a fixed {@see AiErrorClass}.
 *
 * The visitor's "please connect me to a human" intent is detected
 * server-side before this adapter is called (keyword pre-check) and also
 * via a `[[NEEDS_HUMAN]]` sentinel the system policy instructs the model to
 * emit when it cannot help; {@see parse_response()} strips that sentinel and
 * reports {@see AiResult::refusal()}.
 */
final class OpenAiChatProvider implements AiProvider {

	private const ENDPOINT        = 'https://api.openai.com/v1/chat/completions';
	private const NEEDS_HUMAN_TAG = '[[NEEDS_HUMAN]]';

	/**
	 * Provider key manager.
	 *
	 * @var ProviderKeyManager
	 */
	private ProviderKeyManager $keys;

	/**
	 * Constructor.
	 *
	 * @param ProviderKeyManager $keys Provider key manager.
	 */
	public function __construct( ProviderKeyManager $keys ) {
		$this->keys = $keys;
	}

	/**
	 * Generates one response by calling the OpenAI chat-completions API.
	 * Never throws for a provider-side failure — see the class docblock.
	 *
	 * @param AiRequest $request The generation request.
	 */
	public function generate( AiRequest $request ): AiResult {
		$token = $this->keys->token();

		if ( null === $token ) {
			return AiResult::error( AiErrorClass::AUTH );
		}

		$body = wp_json_encode(
			array(
				'model'       => $request->model(),
				'messages'    => $request->wire_messages(),
				'max_tokens'  => $request->max_output_tokens(),
				'temperature' => $request->temperature(),
			)
		);

		$response = wp_safe_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => $request->timeout_seconds(),
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => is_string( $body ) ? $body : '{}',
			)
		);

		if ( is_wp_error( $response ) ) {
			$code = (string) $response->get_error_code();

			return AiResult::error(
				str_contains( $code, 'timeout' ) ? AiErrorClass::TIMEOUT : AiErrorClass::TRANSPORT
			);
		}

		return self::parse_response(
			(int) wp_remote_retrieve_response_code( $response ),
			(string) wp_remote_retrieve_body( $response )
		);
	}

	/**
	 * Pure response mapping — no I/O, unit-tested directly.
	 *
	 * @param int    $status HTTP status code.
	 * @param string $body   Raw response body.
	 */
	public static function parse_response( int $status, string $body ): AiResult {
		if ( 429 === $status ) {
			return AiResult::error( AiErrorClass::RATE_LIMITED );
		}

		if ( $status >= 500 ) {
			return AiResult::error( AiErrorClass::SERVER );
		}

		if ( 401 === $status || 403 === $status ) {
			return AiResult::error( AiErrorClass::AUTH );
		}

		if ( $status >= 400 ) {
			return AiResult::error( AiErrorClass::CLIENT );
		}

		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['choices'][0]['message']['content'] ) ) {
			return AiResult::error( AiErrorClass::MALFORMED );
		}

		$content       = trim( (string) $decoded['choices'][0]['message']['content'] );
		$finish_raw    = isset( $decoded['choices'][0]['finish_reason'] ) ? (string) $decoded['choices'][0]['finish_reason'] : null;
		$prompt_tokens = isset( $decoded['usage']['prompt_tokens'] ) ? (int) $decoded['usage']['prompt_tokens'] : 0;
		$completion    = isset( $decoded['usage']['completion_tokens'] ) ? (int) $decoded['usage']['completion_tokens'] : 0;

		if ( '' === $content || str_contains( $content, self::NEEDS_HUMAN_TAG ) ) {
			return AiResult::refusal( true, $prompt_tokens, $completion );
		}

		if ( 'content_filter' === $finish_raw ) {
			return AiResult::refusal( true, $prompt_tokens, $completion );
		}

		return AiResult::answer(
			$content,
			AiFinishReason::from_raw( $finish_raw ),
			$prompt_tokens,
			$completion
		);
	}
}
