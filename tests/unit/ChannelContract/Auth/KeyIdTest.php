<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\ChannelContract\Auth;

use UniversalSupportChat\ChannelContract\Auth\KeyId;
use PHPUnit\Framework\TestCase;

final class KeyIdTest extends TestCase {

	public function test_compute_is_deterministic_and_slug_prefixed(): void {
		$key = str_repeat( "\x01", 32 );

		$a = KeyId::compute( 'universal-support-chat', $key );
		$b = KeyId::compute( 'universal-support-chat', $key );

		$this->assertSame( $a, $b );
		$this->assertStringStartsWith( 'universal-support-chat.', $a );
		$this->assertTrue( KeyId::is_valid_format( $a ) );
	}

	public function test_different_keys_produce_different_ids(): void {
		$a = KeyId::compute( 'universal-support-chat', str_repeat( "\x01", 32 ) );
		$b = KeyId::compute( 'universal-support-chat', str_repeat( "\x02", 32 ) );

		$this->assertNotSame( $a, $b );
	}

	public function test_is_valid_format_rejects_malformed_values(): void {
		$this->assertFalse( KeyId::is_valid_format( 'no-dot-suffix' ) );
		$this->assertFalse( KeyId::is_valid_format( 'slug.tooshort' ) );
		$this->assertFalse( KeyId::is_valid_format( 'slug.UPPERCASEHEXXX' ) );
	}
}
