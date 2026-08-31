<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * Boundaries not yet authorized by a frozen milestone plan must not exist
 * under src/.
 */
final class StructuralBoundariesTest extends TestCase {

	/**
	 * @return array<string, array{0: string}>
	 */
	public function unauthorized_boundaries_provider(): array {
		return array(
			'Telegram'     => array( 'Telegram' ),
			'Queue'        => array( 'Queue' ),
			'Events'       => array( 'Events' ),
			'Automations'  => array( 'Automations' ),
			'Integrations' => array( 'Integrations' ),
		);
	}

	/**
	 * @dataProvider unauthorized_boundaries_provider
	 */
	public function test_unauthorized_boundary_directory_does_not_exist( string $boundary ): void {
		$path = dirname( __DIR__, 3 ) . '/src/' . $boundary;
		$this->assertDirectoryDoesNotExist( $path );
	}

	public function test_authorized_sc_m02_boundaries_exist(): void {
		$root = dirname( __DIR__, 3 ) . '/src/';
		$this->assertDirectoryExists( $root . 'Conversations' );
		$this->assertDirectoryExists( $root . 'ChannelContract' );
		$this->assertDirectoryExists( $root . 'ChatWidget' );
		$this->assertDirectoryExists( $root . 'Administration/Hub' );
	}

	public function test_sc_m06_availability_boundary_is_authorized(): void {
		// ADR-0017 (SC-M06) authorizes the Availability boundary.
		$this->assertDirectoryExists( dirname( __DIR__, 3 ) . '/src/Availability' );
	}

	public function test_sc_m07_ai_boundary_is_authorized(): void {
		// ADR-0018 (SC-M07) authorizes the AI boundary.
		$this->assertDirectoryExists( dirname( __DIR__, 3 ) . '/src/AI' );
	}

	/**
	 * ADR-0018 §3 / R1: an `ai`-direction answer must never open a Telegram
	 * channel case. The dispatch mirror predicate matches only visitor /
	 * operator — SC-M07 does not extend it.
	 */
	public function test_ai_direction_is_never_mirrored_to_telegram(): void {
		$enqueuer = new \UniversalSupportChat\TelegramDispatch\DispatchEnqueuer(
			new \UniversalSupportChat\Core\Configuration\Settings(),
			new \UniversalSupportChat\TelegramDispatch\DispatchOutboxRepository(
				new \UniversalSupportChat\Persistence\SchemaHealth()
			)
		);

		$method = new \ReflectionMethod( $enqueuer, 'is_mirrored_direction' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $enqueuer, \UniversalSupportChat\Conversations\ConversationMessage::DIRECTION_VISITOR ) );
		$this->assertTrue( $method->invoke( $enqueuer, \UniversalSupportChat\Conversations\ConversationMessage::DIRECTION_OPERATOR ) );
		$this->assertFalse( $method->invoke( $enqueuer, \UniversalSupportChat\Conversations\ConversationMessage::DIRECTION_AI ) );
		$this->assertFalse( $method->invoke( $enqueuer, \UniversalSupportChat\Conversations\ConversationMessage::DIRECTION_SYSTEM ) );
	}

	/**
	 * ADR-0018 §7: the OpenAI adapter is the first outbound HTTP surface in
	 * this plugin. Every `wp_remote_*` / `wp_safe_remote_*` call in src/ must
	 * live under src/AI/Provider/ — reached only by the async worker, never
	 * by a visitor or Hub request.
	 */
	public function test_outbound_http_is_confined_to_ai_provider(): void {
		$root      = dirname( __DIR__, 3 ) . '/src';
		$offenders = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$relative = str_replace( $root . '/', '', $file->getPathname() );

			if ( str_starts_with( $relative, 'AI/Provider/' ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local source read.
			if ( $this->calls_remote_http( (string) file_get_contents( $file->getPathname() ) ) ) {
				$offenders[] = $relative;
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'Outbound HTTP must be confined to src/AI/Provider/: ' . implode( ', ', $offenders )
		);
	}

	/**
	 * Whether PHP source makes a real `wp_remote_*` / `wp_safe_remote_*`
	 * call. Comments and docblocks (which may name the functions for
	 * documentation) are ignored via tokenisation.
	 */
	private function calls_remote_http( string $source ): bool {
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token )
				&& T_STRING === $token[0]
				&& 1 === preg_match( '/^wp_(safe_)?remote_(get|post|head|request)$/', $token[1] )
			) {
				return true;
			}
		}

		return false;
	}
}
