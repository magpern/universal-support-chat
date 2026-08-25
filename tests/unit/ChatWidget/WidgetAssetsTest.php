<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\ChatWidget;

use PHPUnit\Framework\TestCase;

final class WidgetAssetsTest extends TestCase {

	public function test_widget_assets_exist_and_avoid_innerhtml(): void {
		$root = dirname( __DIR__, 3 );
		$js   = (string) file_get_contents( $root . '/assets/js/chat-widget.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$css  = (string) file_get_contents( $root . '/assets/css/chat-widget.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertNotSame( '', $js );
		$this->assertNotSame( '', $css );
		$this->assertStringNotContainsString( 'innerHTML', $js );
		$this->assertStringContainsString( 'textContent', $js );
		$this->assertStringContainsString( 'pagehide', $js );
		$this->assertStringContainsString( 'idempotency_key', $js );
		$this->assertStringContainsString( 'supportTeam', $js );
		$this->assertStringContainsString( 'author_label', $js );
	}
}
