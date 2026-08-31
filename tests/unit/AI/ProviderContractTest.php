<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Unit\AI;

use PHPUnit\Framework\TestCase;
use UniversalSupportChat\AI\Provider\AiErrorClass;
use UniversalSupportChat\AI\Provider\AiFinishReason;
use UniversalSupportChat\AI\Provider\AiRequest;
use UniversalSupportChat\AI\Provider\AiResult;
use UniversalSupportChat\AI\Provider\FakeProvider;
use UniversalSupportChat\AI\Provider\OpenAiChatProvider;

/**
 * SC-M07 WP2 — provider contract: value objects, FakeProvider scripting, and
 * the pure OpenAI response mapping (no network).
 */
final class ProviderContractTest extends TestCase {

	private function request(): AiRequest {
		return new AiRequest(
			'You are a support assistant. Only use the fenced data.',
			array(
				array(
					'role'    => 'user',
					'content' => 'Do you ship to Norway?',
				),
			),
			'gpt-4o-mini',
			400,
			20
		);
	}

	public function test_wire_messages_put_the_system_policy_first(): void {
		$wire = $this->request()->wire_messages();

		$this->assertSame( 'system', $wire[0]['role'] );
		$this->assertStringContainsString( 'support assistant', $wire[0]['content'] );
		$this->assertSame( 'user', $wire[1]['role'] );
	}

	public function test_fake_provider_returns_scripted_results_in_order_then_default(): void {
		$fake = new FakeProvider( AiResult::answer( 'default answer' ) );
		$fake->push( AiResult::answer( 'first' ) )->push( AiResult::refusal() );

		$this->assertSame( 'first', $fake->generate( $this->request() )->answer_text() );
		$this->assertSame( AiResult::OUTCOME_REFUSAL, $fake->generate( $this->request() )->outcome() );
		$this->assertSame( 'default answer', $fake->generate( $this->request() )->answer_text() );
		$this->assertSame( 3, $fake->call_count() );
		$this->assertInstanceOf( AiRequest::class, $fake->last_request() );
	}

	public function test_error_results_report_retryability_from_the_error_class(): void {
		$this->assertTrue( AiResult::error( AiErrorClass::TIMEOUT )->is_retryable() );
		$this->assertTrue( AiResult::error( AiErrorClass::SERVER )->is_retryable() );
		$this->assertTrue( AiResult::error( AiErrorClass::RATE_LIMITED )->is_retryable() );
		$this->assertFalse( AiResult::error( AiErrorClass::AUTH )->is_retryable() );
		$this->assertFalse( AiResult::error( AiErrorClass::CLIENT )->is_retryable() );
		$this->assertFalse( AiResult::error( AiErrorClass::MALFORMED )->is_retryable() );
		$this->assertFalse( AiResult::answer( 'x' )->is_retryable() );
	}

	public function test_openai_parse_maps_a_normal_answer(): void {
		$body   = wp_json_encode(
			array(
				'choices' => array(
					array(
						'message'       => array( 'content' => '  Yes, we ship worldwide.  ' ),
						'finish_reason' => 'stop',
					),
				),
				'usage'   => array(
					'prompt_tokens'     => 120,
					'completion_tokens' => 8,
				),
			)
		);
		$result = OpenAiChatProvider::parse_response( 200, (string) $body );

		$this->assertSame( AiResult::OUTCOME_ANSWER, $result->outcome() );
		$this->assertSame( 'Yes, we ship worldwide.', $result->answer_text() );
		$this->assertSame( AiFinishReason::STOP, $result->finish_reason() );
		$this->assertSame( 120, $result->prompt_tokens() );
		$this->assertSame( 8, $result->completion_tokens() );
	}

	public function test_openai_parse_treats_the_needs_human_sentinel_as_a_refusal(): void {
		$body   = wp_json_encode(
			array(
				'choices' => array(
					array(
						'message'       => array( 'content' => '[[NEEDS_HUMAN]] I cannot help with that.' ),
						'finish_reason' => 'stop',
					),
				),
			)
		);
		$result = OpenAiChatProvider::parse_response( 200, (string) $body );

		$this->assertSame( AiResult::OUTCOME_REFUSAL, $result->outcome() );
		$this->assertTrue( $result->needs_human() );
		$this->assertNull( $result->answer_text() );
	}

	public function test_openai_parse_maps_status_codes_to_error_classes(): void {
		$this->assertSame( AiErrorClass::RATE_LIMITED, OpenAiChatProvider::parse_response( 429, '' )->error_class() );
		$this->assertSame( AiErrorClass::SERVER, OpenAiChatProvider::parse_response( 503, '' )->error_class() );
		$this->assertSame( AiErrorClass::AUTH, OpenAiChatProvider::parse_response( 401, '' )->error_class() );
		$this->assertSame( AiErrorClass::CLIENT, OpenAiChatProvider::parse_response( 400, '' )->error_class() );
		$this->assertSame( AiErrorClass::MALFORMED, OpenAiChatProvider::parse_response( 200, 'not json' )->error_class() );
	}

	public function test_openai_parse_maps_content_filter_finish_reason_to_refusal(): void {
		$body   = wp_json_encode(
			array(
				'choices' => array(
					array(
						'message'       => array( 'content' => 'partial' ),
						'finish_reason' => 'content_filter',
					),
				),
			)
		);
		$result = OpenAiChatProvider::parse_response( 200, (string) $body );

		$this->assertSame( AiResult::OUTCOME_REFUSAL, $result->outcome() );
	}
}
