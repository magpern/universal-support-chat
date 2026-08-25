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
	}
}
