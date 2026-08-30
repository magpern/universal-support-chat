<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\ChatWidget;

use PHPUnit\Framework\TestCase;
use UniversalSupportChat\ChatWidget\WidgetPresentation;

final class WidgetPresentationTest extends TestCase {

	private function make( array $overrides = array(), ?callable $resolver = null ): WidgetPresentation {
		$settings = array_merge(
			array(
				'widget_title'                => '',
				'widget_greeting'             => 'Hi — how can we help?',
				'widget_avatar_attachment_id' => 0,
			),
			$overrides
		);

		return new WidgetPresentation( $settings, $resolver );
	}

	public function test_title_falls_back_to_support_chat_when_empty(): void {
		$this->assertSame( 'Support chat', $this->make( array( 'widget_title' => '' ) )->title() );
		$this->assertSame( 'Support chat', $this->make( array( 'widget_title' => '   ' ) )->title() );
	}

	public function test_title_passthrough_when_set(): void {
		$this->assertSame( 'Acme Support', $this->make( array( 'widget_title' => 'Acme Support' ) )->title() );
	}

	public function test_title_is_capped_at_80_characters(): void {
		$long = str_repeat( 'a', 200 );
		$this->assertSame( 80, mb_strlen( $this->make( array( 'widget_title' => $long ) )->title() ) );
	}

	public function test_greeting_is_passed_through(): void {
		$this->assertSame(
			"Line one\nLine two",
			$this->make( array( 'widget_greeting' => "Line one\nLine two" ) )->greeting()
		);
	}

	public function test_greeting_is_capped_at_500_characters(): void {
		$long = str_repeat( 'x', 1000 );
		$this->assertSame( 500, mb_strlen( $this->make( array( 'widget_greeting' => $long ) )->greeting() ) );
	}

	public function test_avatar_url_is_empty_when_no_attachment(): void {
		$resolver = static function ( int $id ): string {
			return 'https://example.test/' . $id . '.png';
		};

		$this->assertSame( '', $this->make( array( 'widget_avatar_attachment_id' => 0 ), $resolver )->avatar_image_url() );
	}

	public function test_avatar_url_is_resolved_for_a_positive_attachment(): void {
		$seen     = null;
		$resolver = static function ( int $id ) use ( &$seen ): string {
			$seen = $id;
			return 'cid://media/' . $id;
		};

		$this->assertSame( 'cid://media/42', $this->make( array( 'widget_avatar_attachment_id' => 42 ), $resolver )->avatar_image_url() );
		$this->assertSame( 42, $seen );
	}

	public function test_avatar_url_empty_when_resolver_returns_empty(): void {
		$resolver = static function ( int $id ): string {
			unset( $id );
			return '';
		};

		$this->assertSame( '', $this->make( array( 'widget_avatar_attachment_id' => 7 ), $resolver )->avatar_image_url() );
	}
}
