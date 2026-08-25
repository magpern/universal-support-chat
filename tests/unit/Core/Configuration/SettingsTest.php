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
}
