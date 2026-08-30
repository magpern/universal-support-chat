<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\ChatWidget;

use PHPUnit\Framework\TestCase;

final class WidgetAssetsTest extends TestCase {

	private function js(): string {
		$root = dirname( __DIR__, 3 );
		return (string) file_get_contents( $root . '/assets/js/chat-widget.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	private function css(): string {
		$root = dirname( __DIR__, 3 );
		return (string) file_get_contents( $root . '/assets/css/chat-widget.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	public function test_widget_assets_exist_and_avoid_innerhtml(): void {
		$js  = $this->js();
		$css = $this->css();

		$this->assertNotSame( '', $js );
		$this->assertNotSame( '', $css );
		$this->assertStringNotContainsString( 'innerHTML', $js );
		$this->assertStringNotContainsString( 'eval(', $js );
		$this->assertStringNotContainsString( 'new Function', $js );
		$this->assertStringContainsString( 'textContent', $js );
		$this->assertStringContainsString( 'pagehide', $js );
		$this->assertStringContainsString( 'idempotency_key', $js );
		$this->assertStringContainsString( 'supportTeam', $js );
		$this->assertStringContainsString( 'author_label', $js );
	}

	public function test_js_greeting_intro_is_set_during_init(): void {
		$js = $this->js();

		$this->assertStringContainsString( 'cfg.greeting', $js );
		// The intro text content is assigned at module scope (init), not
		// inside openPanel(), so aria-describedby resolves before any open.
		$init_segment = substr( $js, 0, (int) strpos( $js, 'function openPanel' ) );
		$this->assertStringContainsString( 'introEl.textContent', $init_segment );
	}

	public function test_js_marks_has_messages_for_the_mobile_intro_hide(): void {
		$this->assertStringContainsString( 'data-has-messages', $this->js() );
	}

	public function test_js_moves_focus_into_panel_on_open_and_back_to_launcher_on_close(): void {
		$js = $this->js();

		$open  = substr( $js, (int) strpos( $js, 'function openPanel' ), (int) strpos( $js, 'function closePanel' ) - (int) strpos( $js, 'function openPanel' ) );
		$close = substr( $js, (int) strpos( $js, 'function closePanel' ), (int) strpos( $js, 'function togglePanel' ) - (int) strpos( $js, 'function closePanel' ) );

		$this->assertStringContainsString( 'closeBtn.focus()', $open );
		$this->assertStringContainsString( 'launcher.focus()', $close );
	}

	public function test_js_adds_no_tab_focus_trap(): void {
		$js = $this->js();

		$this->assertStringNotContainsString( 'trapFocus', $js );
		// No keydown handler comparing against the Tab key.
		$this->assertDoesNotMatchRegularExpression( "/key\\s*===\\s*'Tab'/", $js );
		$this->assertStringNotContainsString( 'keyCode === 9', $js );
	}

	public function test_no_external_asset_or_font_urls_in_js_or_css(): void {
		$this->assertStringNotContainsString( 'http://', $this->js() );
		$this->assertStringNotContainsString( 'https://', $this->js() );
		$this->assertStringNotContainsString( 'http://', $this->css() );
		$this->assertStringNotContainsString( 'https://', $this->css() );
	}

	public function test_css_launcher_is_circular_with_a_morph_transition(): void {
		$css = $this->css();

		$this->assertStringContainsString( 'border-radius: 50%', $css );
		$this->assertMatchesRegularExpression( '/\.usc-chat__launcher[^{]*\{[^}]*transition:/s', $css );
	}

	public function test_css_declares_tokens_with_hardcoded_fallbacks(): void {
		$css = $this->css();

		$this->assertMatchesRegularExpression( '/--usc-[a-z-]+:/', $css );
		$this->assertStringContainsString( 'var(--usc-accent, #0b57d0)', $css );
		$this->assertStringContainsString( '#0b57d0', $css );
	}

	public function test_css_disables_all_motion_under_reduced_motion(): void {
		$css = $this->css();

		$this->assertStringContainsString( '@media (prefers-reduced-motion: reduce)', $css );

		$pos = strpos( $css, '@media (prefers-reduced-motion: reduce)' );
		$this->assertNotFalse( $pos );
		$block = substr( $css, $pos );
		$this->assertStringContainsString( 'transition: none', $block );
	}

	public function test_css_has_mobile_fullscreen_panel_and_intro_hide(): void {
		$css = $this->css();

		$this->assertStringContainsString( '@media (max-width: 480px)', $css );
		$this->assertMatchesRegularExpression( '/\[data-has-messages\][^{]*\.usc-chat__intro\s*\{\s*display:\s*none/', $css );
	}

	public function test_css_has_intro_and_avatar_rules(): void {
		$css = $this->css();

		$this->assertStringContainsString( '.usc-chat__avatar', $css );
		$this->assertStringContainsString( '.usc-chat__intro', $css );
		$this->assertStringContainsString( 'white-space: pre-wrap', $css );
		$this->assertStringContainsString( '.usc-chat__intro:empty', $css );
	}

	public function test_css_has_rtl_mirror(): void {
		$this->assertMatchesRegularExpression( '/\[dir="rtl"\]\s*\.usc-chat\s*\{[^}]*left:\s*1rem/s', $this->css() );
	}
}
