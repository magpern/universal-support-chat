<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Migration;

use PHPUnit\Framework\TestCase;
use UniversalSupportChat\Migration\IdempotencyKeyDeriver;

/**
 * @covers \UniversalSupportChat\Migration\IdempotencyKeyDeriver
 */
final class IdempotencyKeyDeriverTest extends TestCase {

	public function test_conversation_key_is_deterministic(): void {
		$a = IdempotencyKeyDeriver::for_conversation( 'source-key', 'uuid-1' );
		$b = IdempotencyKeyDeriver::for_conversation( 'source-key', 'uuid-1' );

		$this->assertSame( $a, $b );
	}

	public function test_conversation_key_is_uuid_shaped_and_fits_char_36(): void {
		$key = IdempotencyKeyDeriver::for_conversation( 'source-key', 'uuid-1' );

		$this->assertSame( 36, strlen( $key ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $key );
	}

	public function test_null_source_key_falls_back_to_conversation_uuid_without_collision(): void {
		$conversation_a = IdempotencyKeyDeriver::for_conversation( null, 'uuid-a' );
		$conversation_b = IdempotencyKeyDeriver::for_conversation( null, 'uuid-b' );

		$this->assertNotSame( $conversation_a, $conversation_b );
	}

	public function test_empty_string_source_key_is_treated_the_same_as_null(): void {
		$from_null  = IdempotencyKeyDeriver::for_conversation( null, 'uuid-same' );
		$from_empty = IdempotencyKeyDeriver::for_conversation( '', 'uuid-same' );

		$this->assertSame( $from_null, $from_empty );
	}

	public function test_a_real_source_key_produces_a_different_value_than_the_null_fallback(): void {
		$with_key    = IdempotencyKeyDeriver::for_conversation( 'real-key', 'uuid-same' );
		$without_key = IdempotencyKeyDeriver::for_conversation( null, 'uuid-same' );

		$this->assertNotSame( $with_key, $without_key );
	}

	public function test_many_null_key_fixtures_never_collide(): void {
		$seen = array();

		for ( $i = 0; $i < 500; $i++ ) {
			$key = IdempotencyKeyDeriver::for_conversation( null, 'uuid-' . $i );
			$this->assertArrayNotHasKey( $key, $seen );
			$seen[ $key ] = true;
		}
	}

	public function test_message_key_is_deterministic_and_uuid_shaped(): void {
		$a = IdempotencyKeyDeriver::for_message( 'message-uuid-1' );
		$b = IdempotencyKeyDeriver::for_message( 'message-uuid-1' );

		$this->assertSame( $a, $b );
		$this->assertSame( 36, strlen( $a ) );
	}

	public function test_message_key_differs_from_conversation_key_for_the_same_uuid(): void {
		$conversation_key = IdempotencyKeyDeriver::for_conversation( null, 'same-uuid' );
		$message_key      = IdempotencyKeyDeriver::for_message( 'same-uuid' );

		$this->assertNotSame( $conversation_key, $message_key );
	}

	public function test_export_error_placeholder_uuid_is_deterministic_and_uuid_shaped(): void {
		$a = IdempotencyKeyDeriver::export_error_placeholder_uuid( 42 );
		$b = IdempotencyKeyDeriver::export_error_placeholder_uuid( 42 );

		$this->assertSame( $a, $b );
		$this->assertSame( 36, strlen( $a ) );
		$this->assertNotSame( $a, IdempotencyKeyDeriver::export_error_placeholder_uuid( 43 ) );
	}

	public function test_note_placeholder_uuid_is_deterministic_and_scoped_by_conversation(): void {
		$a = IdempotencyKeyDeriver::note_placeholder_uuid( 1, 10 );
		$b = IdempotencyKeyDeriver::note_placeholder_uuid( 1, 10 );
		$c = IdempotencyKeyDeriver::note_placeholder_uuid( 2, 10 );

		$this->assertSame( $a, $b );
		$this->assertNotSame( $a, $c );
	}
}
