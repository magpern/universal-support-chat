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

	public function test_js_dedupes_rendered_messages_by_id(): void {
		$js = $this->js();

		// The periodic poll and the explicit post-send poll can both be in
		// flight with the same stale after_id, so appendMessage must drop a
		// message id it has already rendered rather than draw it twice.
		$this->assertStringContainsString( 'seenMessageIds', $js );
		$this->assertMatchesRegularExpression( '/if\s*\(\s*seenMessageIds\[\s*msg\.id\s*\]\s*\)\s*\{\s*return;/', $js );
		// The dedupe set is reset whenever the transcript is cleared.
		$clear_segment = substr( $js, (int) strpos( $js, 'function clearMessages' ), 200 );
		$this->assertStringContainsString( 'seenMessageIds = {}', $clear_segment );
	}

	public function test_js_availability_is_rendered_as_plain_text_and_pill_is_honest(): void {
		$js = $this->js();

		$this->assertStringContainsString( 'function applyAvailability', $js );
		// Offline copy and confirmation reach the DOM via .textContent only.
		$this->assertStringContainsString( 'offlineEl.textContent', $js );
		$this->assertStringContainsString( 'onlineEl.textContent', $js );
		$this->assertStringNotContainsString( 'innerHTML', $js );
		// The "online" pill is gated on a genuinely available state.
		$this->assertMatchesRegularExpression( '/showPill\\s*=\\s*!unavailable\\s*&&/', $js );
		// No response-time / ETA copy is baked into the script.
		$this->assertStringNotContainsStringIgnoringCase( 'typically repl', $js );
		$this->assertStringNotContainsStringIgnoringCase( 'response time', $js );
		$this->assertDoesNotMatchRegularExpression( '/\\breply (in|within)\\s+\\d/i', $js );
	}

	public function test_js_refreshes_availability_from_server_responses(): void {
		$js = $this->js();

		// Availability is re-applied from the poll response and from the
		// start / message POST responses (ADR-0017 §7).
		$this->assertGreaterThanOrEqual( 3, substr_count( $js, 'applyAvailability(res.data.availability)' ) );
	}

	public function test_js_defers_init_until_the_shell_dom_exists(): void {
		$js = $this->js();

		// The shell prints on wp_footer priority 30, after footer scripts —
		// init must wait for DOMContentLoaded when the document is still
		// parsing, otherwise getElementById('usc-chat-root') is null.
		$this->assertMatchesRegularExpression(
			"/document\\.readyState === 'loading'\\s*\\)\\s*\\{\\s*document\\.addEventListener\\('DOMContentLoaded', init\\);\\s*\\}\\s*else\\s*\\{\\s*init\\(\\);/s",
			$js
		);
	}

	public function test_js_moves_focus_into_panel_on_open_and_back_to_launcher_on_close(): void {
		$js = $this->js();

		$open  = substr( $js, (int) strpos( $js, 'function openPanel' ), (int) strpos( $js, 'function closePanel' ) - (int) strpos( $js, 'function openPanel' ) );
		$close = substr( $js, (int) strpos( $js, 'function closePanel' ), (int) strpos( $js, 'function togglePanel' ) - (int) strpos( $js, 'function closePanel' ) );

		$this->assertStringContainsString( 'closeBtn.focus()', $open );
		$this->assertStringContainsString( 'launcher.focus()', $close );
	}

	public function test_js_open_bootstrap_cannot_steal_focus_after_close(): void {
		$js = $this->js();

		// closePanel() invalidates any in-flight open bootstrap.
		$close = substr( $js, (int) strpos( $js, 'function closePanel' ), (int) strpos( $js, 'function togglePanel' ) - (int) strpos( $js, 'function closePanel' ) );
		$this->assertStringContainsString( 'openSession += 1', $close );

		// The post-bootstrap .then() that calls input.focus() must bail when
		// the session changed or the panel is no longer open.
		$open = substr( $js, (int) strpos( $js, 'function openPanel' ), (int) strpos( $js, 'function closePanel' ) - (int) strpos( $js, 'function openPanel' ) );
		$this->assertMatchesRegularExpression(
			'/if \(session !== openSession \|\| !open\) \{\s*return;\s*\}\s*sendBtn\.disabled = false;.*input\.focus\(\);/s',
			$open
		);
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
