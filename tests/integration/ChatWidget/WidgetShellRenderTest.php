<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\ChatWidget;

use UniversalSupportChat\ChatWidget\WidgetAssets;
use UniversalSupportChat\Core\Configuration\Settings;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use WP_UnitTestCase;

/**
 * SC-M05 / ADR-0016: professional widget shell + localized payload.
 */
final class WidgetShellRenderTest extends WP_UnitTestCase {

	private Settings $settings;

	public function set_up(): void {
		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		$this->settings = new Settings();
		delete_option( Settings::OPTION_NAME );
	}

	public function tear_down(): void {
		delete_option( Settings::OPTION_NAME );
		parent::tear_down();
	}

	private function store( array $overrides ): void {
		update_option( Settings::OPTION_NAME, array_merge( $this->settings->defaults(), $overrides ) );
	}

	private function widget(): WidgetAssets {
		return new WidgetAssets( $this->settings, new SchemaHealth() );
	}

	private function shell(): string {
		ob_start();
		$this->widget()->render_shell();
		return (string) ob_get_clean();
	}

	public function test_shell_is_a_non_modal_dialog_with_launcher_icons_and_intro(): void {
		$html = $this->shell();

		$this->assertStringContainsString( 'usc-chat__launcher', $html );
		$this->assertStringContainsString( 'data-usc-icon="bubble"', $html );
		$this->assertStringContainsString( 'data-usc-icon="close"', $html );
		$this->assertSame( 3, substr_count( $html, 'aria-hidden="true"' ), 'both launcher icons + the close icon are decorative' );
		$this->assertStringContainsString( 'role="dialog"', $html );
		$this->assertStringNotContainsString( 'aria-modal', $html );
		$this->assertStringContainsString( 'aria-describedby="usc-chat-intro"', $html );
		$this->assertStringContainsString( 'id="usc-chat-intro"', $html );
		$this->assertStringContainsString( 'aria-haspopup="dialog"', $html );
	}

	public function test_title_is_escaped_and_never_raw_script(): void {
		$this->store( array( 'widget_title' => '<script>alert(1)</script>Team' ) );
		$html = $this->shell();

		$this->assertMatchesRegularExpression( '/<h2 id="usc-chat-title"[^>]*>[^<]*Team<\/h2>/', $html );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
	}

	public function test_empty_title_falls_back_to_support_chat(): void {
		$this->store( array( 'widget_title' => '' ) );
		$html = $this->shell();

		$this->assertMatchesRegularExpression( '/<h2 id="usc-chat-title"[^>]*>Support chat<\/h2>/', $html );
	}

	public function test_no_avatar_img_when_id_is_zero(): void {
		$this->store( array( 'widget_avatar_attachment_id' => 0 ) );
		$this->assertStringNotContainsString( 'usc-chat__avatar', $this->shell() );
	}

	public function test_avatar_img_rendered_for_a_real_image_attachment(): void {
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->store( array( 'widget_avatar_attachment_id' => $attachment_id ) );

		$html = $this->shell();

		$this->assertSame( 1, substr_count( $html, '<img class="usc-chat__avatar"' ) );
		$this->assertStringContainsString( 'alt=""', $html );
		$this->assertMatchesRegularExpression( '/<img class="usc-chat__avatar" alt="" src="https?:\/\/[^"]+"/', $html );
	}

	public function test_no_avatar_img_for_a_non_image_attachment(): void {
		$attachment_id = self::factory()->post->create(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'application/pdf',
			)
		);
		$this->store( array( 'widget_avatar_attachment_id' => $attachment_id ) );

		$this->assertStringNotContainsString( 'usc-chat__avatar', $this->shell() );
	}

	public function test_localized_payload_carries_greeting_but_not_title_or_avatar_url(): void {
		$this->store(
			array(
				'widget_title'                => 'Acme',
				'widget_greeting'             => 'Hello <b>there</b>',
				'widget_avatar_attachment_id' => 0,
			)
		);

		$this->widget()->enqueue();
		$blob = wp_scripts()->get_data( 'universal-support-chat-widget', 'data' );
		$this->assertIsString( $blob );

		$this->assertStringContainsString( '"greeting"', $blob );
		$this->assertStringContainsString( 'Hello there', $blob );
		$this->assertStringNotContainsString( '<b>there</b>', $blob );
		$this->assertStringNotContainsString( 'avatarUrl', $blob );
		$this->assertStringNotContainsString( '"Acme"', $blob );
	}

	public function test_widget_exposes_no_telegram_ui_identity_or_dependency(): void {
		$this->store(
			array(
				'widget_title'    => 'Acme Support',
				'widget_greeting' => 'Hi there',
			)
		);

		$html = $this->shell();
		$this->widget()->enqueue();
		$blob = (string) wp_scripts()->get_data( 'universal-support-chat-widget', 'data' );

		$this->assertStringNotContainsStringIgnoringCase( 'telegram', $html );
		$this->assertStringNotContainsStringIgnoringCase( 'telegram', $blob );
		// No availability / presence chrome (charter exclusion; SC-M06 owns it).
		foreach ( array( 'online', 'offline', 'typically replies', 'we are away', "we're away" ) as $forbidden ) {
			$this->assertStringNotContainsStringIgnoringCase( $forbidden, $html );
		}
	}

	public function test_widget_js_still_has_no_innerhtml(): void {
		$this->widget()->enqueue();
		// The shipped script file is the source of truth for the no-innerHTML
		// invariant; assert it here too against regressions in this suite.
		$js = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/js/chat-widget.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringNotContainsString( 'innerHTML', $js );
	}
}
