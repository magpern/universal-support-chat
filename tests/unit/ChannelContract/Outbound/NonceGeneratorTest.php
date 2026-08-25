<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\ChannelContract\Outbound;

use UniversalSupportChat\ChannelContract\Outbound\NonceGenerator;
use PHPUnit\Framework\TestCase;

final class NonceGeneratorTest extends TestCase {

	public function test_generates_22_character_unpadded_base64url(): void {
		$nonce = NonceGenerator::generate();

		$this->assertSame( 22, strlen( $nonce ) );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{22}$/', $nonce );
	}

	public function test_generates_distinct_values(): void {
		$values = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$values[ NonceGenerator::generate() ] = true;
		}

		$this->assertCount( 50, $values );
	}
}
