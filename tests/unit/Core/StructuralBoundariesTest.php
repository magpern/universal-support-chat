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
			'Conversations' => array( 'Conversations' ),
			'ChatWidget'    => array( 'ChatWidget' ),
			'AI'            => array( 'AI' ),
			'Telegram'      => array( 'Telegram' ),
			'Queue'         => array( 'Queue' ),
			'Events'        => array( 'Events' ),
			'Automations'   => array( 'Automations' ),
			'Integrations'  => array( 'Integrations' ),
		);
	}

	/**
	 * @dataProvider unauthorized_boundaries_provider
	 */
	public function test_unauthorized_boundary_directory_does_not_exist( string $boundary ): void {
		$path = dirname( __DIR__, 3 ) . '/src/' . $boundary;
		$this->assertDirectoryDoesNotExist( $path );
	}
}
