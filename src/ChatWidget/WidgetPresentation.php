<?php
/**
 * Widget presentation value object (ADR-0016 / SC-M05).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\ChatWidget;

/**
 * Resolves the operator-configured widget title, greeting, and optional
 * avatar image URL from a sanitized `Settings` array, keeping `WidgetAssets`
 * thin and the resolution logic pure and unit-testable.
 *
 * All values are plain text (ADR-0016): the title and greeting are never
 * HTML, and the avatar is referenced only by a server-validated Media
 * Library attachment id.
 */
final class WidgetPresentation {

	private const TITLE_MAX    = 80;
	private const GREETING_MAX = 500;

	/**
	 * Stored title ('' means "use the translated fallback").
	 *
	 * @var string
	 */
	private string $title;

	/**
	 * Stored greeting.
	 *
	 * @var string
	 */
	private string $greeting;

	/**
	 * Stored avatar attachment id (0 means "no avatar").
	 *
	 * @var int
	 */
	private int $avatar_attachment_id;

	/**
	 * Resolver mapping an attachment id to an image URL (or '' when none).
	 *
	 * @var callable(int):string
	 */
	private $avatar_url_resolver;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>        $settings            Sanitized settings array.
	 * @param callable(int):string|null  $avatar_url_resolver Optional URL resolver (testing seam).
	 */
	public function __construct( array $settings, ?callable $avatar_url_resolver = null ) {
		$this->title    = isset( $settings['widget_title'] ) && is_scalar( $settings['widget_title'] )
			? mb_substr( (string) $settings['widget_title'], 0, self::TITLE_MAX )
			: '';
		$this->greeting = isset( $settings['widget_greeting'] ) && is_scalar( $settings['widget_greeting'] )
			? mb_substr( (string) $settings['widget_greeting'], 0, self::GREETING_MAX )
			: '';

		$this->avatar_attachment_id = isset( $settings['widget_avatar_attachment_id'] ) && is_numeric( $settings['widget_avatar_attachment_id'] )
			? max( 0, (int) $settings['widget_avatar_attachment_id'] )
			: 0;

		$this->avatar_url_resolver = $avatar_url_resolver ?? static function ( int $id ): string {
			$url = wp_get_attachment_image_url( $id, 'thumbnail' );

			return is_string( $url ) ? $url : '';
		};
	}

	/**
	 * The resolved panel title: the stored value, or the translated
	 * "Support chat" fallback when the operator has not customised it.
	 */
	public function title(): string {
		$title = trim( $this->title );

		if ( '' !== $title ) {
			return $title;
		}

		return __( 'Support chat', 'universal-support-chat' );
	}

	/**
	 * The raw greeting string (delivered to the widget script and rendered
	 * with `.textContent`).
	 */
	public function greeting(): string {
		return $this->greeting;
	}

	/**
	 * The avatar image URL, or '' when there is no avatar or the stored id
	 * no longer resolves to an image. `render_shell()` emits an `<img>` only
	 * when this is non-empty — never a broken image.
	 */
	public function avatar_image_url(): string {
		if ( $this->avatar_attachment_id <= 0 ) {
			return '';
		}

		return ( $this->avatar_url_resolver )( $this->avatar_attachment_id );
	}
}
