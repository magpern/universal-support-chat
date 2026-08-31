<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Unit\Core\Configuration;

use PHPUnit\Framework\TestCase;
use UniversalSupportChat\Core\Configuration\Settings;

/**
 * SC-M07 WP3 — additive AI settings keys: disabled by default, model
 * allow-list, numeric clamping (never rejected).
 */
final class AiSettingsTest extends TestCase {

	private Settings $settings;

	protected function setUp(): void {
		$this->settings = new Settings();
	}

	public function test_ai_is_disabled_by_default(): void {
		$defaults = $this->settings->defaults();

		$this->assertFalse( $defaults['ai_enabled'] );
		$this->assertSame( 'gpt-4o-mini', $defaults['ai_model'] );
		$this->assertContains( 'gpt-4o', Settings::AI_ALLOWED_MODELS );
		$this->assertNotSame( '', $defaults['ai_disclosure_text'] );
	}

	public function test_absent_ai_keys_resolve_to_defaults(): void {
		$result = $this->settings->sanitize( array() );

		$this->assertFalse( $result['ai_enabled'] );
		$this->assertSame( 'gpt-4o-mini', $result['ai_model'] );
		$this->assertSame( 3, $result['ai_max_retries'] );
	}

	public function test_unknown_model_falls_back_to_the_default(): void {
		$result = $this->settings->sanitize( array( 'ai_model' => 'gpt-5-ultra' ) );

		$this->assertSame( 'gpt-4o-mini', $result['ai_model'] );
	}

	public function test_allowed_model_is_kept(): void {
		$result = $this->settings->sanitize( array( 'ai_model' => 'gpt-4o' ) );

		$this->assertSame( 'gpt-4o', $result['ai_model'] );
	}

	public function test_numeric_limits_are_clamped_not_rejected(): void {
		$result = $this->settings->sanitize(
			array(
				'ai_max_output_tokens'         => 999999,
				'ai_request_timeout_seconds'   => 1,
				'ai_max_retries'               => 99,
				'ai_per_conversation_turn_cap' => 0,
				'ai_daily_request_cap'         => -5,
				'ai_max_context_chars'         => 'not a number',
			)
		);

		$this->assertSame( 2000, $result['ai_max_output_tokens'] );
		$this->assertSame( 5, $result['ai_request_timeout_seconds'] );
		$this->assertSame( 6, $result['ai_max_retries'] );
		$this->assertSame( 1, $result['ai_per_conversation_turn_cap'] );
		$this->assertSame( 1, $result['ai_daily_request_cap'] );
		$this->assertSame( 6000, $result['ai_max_context_chars'] );
	}

	public function test_blank_disclosure_falls_back_to_default(): void {
		$result = $this->settings->sanitize( array( 'ai_disclosure_text' => '   ' ) );

		$this->assertSame( Settings::DEFAULT_AI_DISCLOSURE, $result['ai_disclosure_text'] );
	}

	public function test_disclosure_is_plain_text_and_capped(): void {
		$result = $this->settings->sanitize( array( 'ai_disclosure_text' => '<b>Hi</b> ' . str_repeat( 'x', 900 ) ) );

		$this->assertStringNotContainsString( '<b>', $result['ai_disclosure_text'] );
		$this->assertLessThanOrEqual( 500, mb_strlen( $result['ai_disclosure_text'] ) );
	}

	public function test_ai_enabled_toggles_from_the_checkbox_companion(): void {
		$this->assertTrue( $this->settings->sanitize( array( 'ai_enabled' => '1' ) )['ai_enabled'] );
		$this->assertFalse( $this->settings->sanitize( array( 'ai_enabled' => '0' ) )['ai_enabled'] );
	}
}
