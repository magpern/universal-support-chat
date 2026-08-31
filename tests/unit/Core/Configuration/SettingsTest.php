<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Core\Configuration;

use PHPUnit\Framework\TestCase;
use UniversalSupportChat\Core\Configuration\Settings;

final class SettingsTest extends TestCase {

	public function test_defaults_disable_data_removal(): void {
		$settings = new Settings();
		$defaults = $settings->defaults();

		$this->assertFalse( $defaults['remove_data_on_uninstall'] );
		$this->assertSame( 30, $defaults['conversation_inactive_days'] );
		$this->assertSame( 30, $defaults['conversation_archived_body_days'] );
		$this->assertSame( 90, $defaults['conversation_purge_days'] );
		$this->assertTrue( $defaults['widget_enabled'] );
		$this->assertFalse( $defaults['telegram_dispatch_enabled'] );
	}

	public function test_defaults_include_the_three_widget_presentation_keys(): void {
		$defaults = ( new Settings() )->defaults();

		$this->assertCount( 22, $defaults );
		$this->assertSame( '', $defaults['widget_title'] );
		$this->assertSame( 'Hi — how can we help?', $defaults['widget_greeting'] );
		$this->assertSame( 0, $defaults['widget_avatar_attachment_id'] );
	}

	public function test_sanitize_is_fixed_shape_and_drops_unknown(): void {
		$result = ( new Settings() )->sanitize(
			array(
				'nope'         => 'x',
				'widget_title' => 'Hi',
			)
		);

		$this->assertCount( 22, $result );
		$this->assertArrayNotHasKey( 'nope', $result );
		$this->assertSame(
			array(
				'remove_data_on_uninstall',
				'conversation_inactive_days',
				'conversation_archived_body_days',
				'conversation_purge_days',
				'widget_enabled',
				'telegram_dispatch_enabled',
				'widget_title',
				'widget_greeting',
				'widget_avatar_attachment_id',
				'availability_schedule',
				'availability_exceptions',
				'availability_offline_message',
				'availability_online_indicator',
				'ai_enabled',
				'ai_model',
				'ai_max_output_tokens',
				'ai_request_timeout_seconds',
				'ai_max_context_chars',
				'ai_max_retries',
				'ai_daily_request_cap',
				'ai_per_conversation_turn_cap',
				'ai_disclosure_text',
			),
			array_keys( $result )
		);
	}

	public function test_sanitize_supplies_availability_defaults(): void {
		$result = ( new Settings() )->sanitize( array() );

		$this->assertSame(
			array(
				array(
					'start' => '12:00',
					'end'   => '15:00',
				),
			),
			$result['availability_schedule']['mon']
		);
		$this->assertSame( array(), $result['availability_schedule']['sat'] );
		$this->assertSame( array(), $result['availability_exceptions'] );
		$this->assertTrue( $result['availability_online_indicator'] );
		$this->assertStringContainsString( 'offline', $result['availability_offline_message'] );
	}

	public function test_sanitize_empty_array_yields_all_defaults(): void {
		$result   = ( new Settings() )->sanitize( array() );
		$defaults = ( new Settings() )->defaults();

		$this->assertSame( $defaults, $result );
	}

	public function test_widget_title_is_tag_stripped_and_capped_at_80(): void {
		$settings = new Settings();

		$result = $settings->sanitize( array( 'widget_title' => '<b>Team</b>' ) );
		$this->assertSame( 'Team', $result['widget_title'] );

		$long = str_repeat( 'a', 200 );
		$this->assertSame( 80, mb_strlen( $settings->sanitize( array( 'widget_title' => $long ) )['widget_title'] ) );
	}

	public function test_widget_greeting_is_tag_stripped_capped_at_500_and_keeps_newlines(): void {
		$settings = new Settings();

		$result = $settings->sanitize( array( 'widget_greeting' => "Hello <script>alert(1)</script>\nWorld" ) );
		$this->assertStringNotContainsString( '<script>', $result['widget_greeting'] );
		$this->assertStringContainsString( "\n", $result['widget_greeting'] );

		$long = str_repeat( 'x', 1000 );
		$this->assertSame( 500, mb_strlen( $settings->sanitize( array( 'widget_greeting' => $long ) )['widget_greeting'] ) );
	}

	public function test_widget_avatar_id_rejects_non_numeric_and_negative(): void {
		$settings = new Settings();

		$this->assertSame( 0, $settings->sanitize( array( 'widget_avatar_attachment_id' => 'nope' ) )['widget_avatar_attachment_id'] );
		$this->assertSame( 0, $settings->sanitize( array( 'widget_avatar_attachment_id' => -5 ) )['widget_avatar_attachment_id'] );
	}

	public function test_upgrade_path_supplies_the_three_new_keys_at_defaults(): void {
		$settings = new Settings();

		$legacy_six = array(
			'remove_data_on_uninstall'        => '0',
			'conversation_inactive_days'      => 30,
			'conversation_archived_body_days' => 30,
			'conversation_purge_days'         => 90,
			'widget_enabled'                  => '1',
			'telegram_dispatch_enabled'       => '0',
		);

		$result = $settings->sanitize( $legacy_six );

		$this->assertSame( '', $result['widget_title'] );
		$this->assertSame( 'Hi — how can we help?', $result['widget_greeting'] );
		$this->assertSame( 0, $result['widget_avatar_attachment_id'] );
	}

	public function test_telegram_dispatch_flag_is_opt_in_and_coerced(): void {
		$settings = new Settings();

		$this->assertFalse( $settings->sanitize( array() )['telegram_dispatch_enabled'] );
		$this->assertTrue( $settings->sanitize( array( 'telegram_dispatch_enabled' => '1' ) )['telegram_dispatch_enabled'] );
		$this->assertFalse( $settings->sanitize( array( 'telegram_dispatch_enabled' => '0' ) )['telegram_dispatch_enabled'] );
	}

	public function test_sanitize_coerces_truthy_flag(): void {
		$settings = new Settings();
		$result   = $settings->sanitize( array( 'remove_data_on_uninstall' => '1' ) );

		$this->assertTrue( $result['remove_data_on_uninstall'] );
	}

	public function test_sanitize_rejects_non_array(): void {
		$settings = new Settings();
		$result   = $settings->sanitize( 'nope' );

		$this->assertFalse( $result['remove_data_on_uninstall'] );
		$this->assertSame( 30, $result['conversation_inactive_days'] );
	}

	public function test_sanitize_rejects_non_positive_retention_days(): void {
		$settings = new Settings();
		$result   = $settings->sanitize(
			array(
				'conversation_inactive_days'      => 0,
				'conversation_archived_body_days' => -5,
				'conversation_purge_days'         => 'nope',
			)
		);

		$this->assertSame( 30, $result['conversation_inactive_days'] );
		$this->assertSame( 30, $result['conversation_archived_body_days'] );
		$this->assertSame( 90, $result['conversation_purge_days'] );
	}

	/**
	 * ADR-0015 form contract: every checkbox ships a hidden `0` companion, so
	 * an unchecked box submits `'0'` (not an absent key). `'0'` must sanitise
	 * to false; an absent key falls back to the default.
	 */
	public function test_widget_enabled_hidden_zero_companion_yields_false(): void {
		$settings = new Settings();

		$this->assertFalse( $settings->sanitize( array( 'widget_enabled' => '0' ) )['widget_enabled'] );
		$this->assertTrue( $settings->sanitize( array( 'widget_enabled' => '1' ) )['widget_enabled'] );
		// Key entirely absent (no hidden companion) would fall back to the default.
		$this->assertTrue( $settings->sanitize( array() )['widget_enabled'] );
	}

	public function test_remove_data_on_uninstall_is_explicit_and_coerced(): void {
		$settings = new Settings();

		$this->assertFalse( $settings->sanitize( array() )['remove_data_on_uninstall'] );
		$this->assertFalse( $settings->sanitize( array( 'remove_data_on_uninstall' => '0' ) )['remove_data_on_uninstall'] );
		$this->assertTrue( $settings->sanitize( array( 'remove_data_on_uninstall' => '1' ) )['remove_data_on_uninstall'] );
	}
}
