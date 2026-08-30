<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Core\Configuration;

use UniversalSupportChat\Core\Configuration\Settings;
use WP_UnitTestCase;

/**
 * ADR-0016: `widget_avatar_attachment_id` is server-validated as a Media
 * Library image attachment regardless of how the value was submitted.
 */
final class SettingsAvatarValidationTest extends WP_UnitTestCase {

	private Settings $settings;

	public function set_up(): void {
		parent::set_up();
		$this->settings = new Settings();
	}

	public function test_a_positive_id_that_is_not_an_image_attachment_becomes_zero(): void {
		$post_id = self::factory()->post->create();

		$result = $this->settings->sanitize( array( 'widget_avatar_attachment_id' => $post_id ) );

		$this->assertSame( 0, $result['widget_avatar_attachment_id'] );
	}

	public function test_a_pdf_attachment_becomes_zero(): void {
		$attachment_id = self::factory()->post->create(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'application/pdf',
			)
		);

		$result = $this->settings->sanitize( array( 'widget_avatar_attachment_id' => $attachment_id ) );

		$this->assertSame( 0, $result['widget_avatar_attachment_id'] );
	}

	public function test_a_real_image_attachment_id_is_preserved(): void {
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		$result = $this->settings->sanitize( array( 'widget_avatar_attachment_id' => (string) $attachment_id ) );

		$this->assertSame( $attachment_id, $result['widget_avatar_attachment_id'] );
	}

	/**
	 * Regression: a negative id whose absolute value IS a real image
	 * attachment must still become 0 — never the absint()-flipped positive.
	 */
	public function test_negative_of_a_real_image_attachment_id_becomes_zero(): void {
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertTrue( wp_attachment_is_image( $attachment_id ) );

		$result = $this->settings->sanitize( array( 'widget_avatar_attachment_id' => -$attachment_id ) );

		$this->assertSame( 0, $result['widget_avatar_attachment_id'] );

		$result_string = $this->settings->sanitize( array( 'widget_avatar_attachment_id' => (string) ( -$attachment_id ) ) );
		$this->assertSame( 0, $result_string['widget_avatar_attachment_id'] );
	}
}
