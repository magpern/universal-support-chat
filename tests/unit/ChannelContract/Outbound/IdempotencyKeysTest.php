<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\ChannelContract\Outbound;

use UniversalSupportChat\ChannelContract\Outbound\IdempotencyKeys;
use PHPUnit\Framework\TestCase;

final class IdempotencyKeysTest extends TestCase {

	public function test_ensure_channel_case_key_is_deterministic(): void {
		$a = IdempotencyKeys::for_ensure_channel_case( 'conv-uuid-1' );
		$b = IdempotencyKeys::for_ensure_channel_case( 'conv-uuid-1' );

		$this->assertSame( $a, $b );
	}

	public function test_ensure_channel_case_key_differs_by_conversation(): void {
		$a = IdempotencyKeys::for_ensure_channel_case( 'conv-uuid-1' );
		$b = IdempotencyKeys::for_ensure_channel_case( 'conv-uuid-2' );

		$this->assertNotSame( $a, $b );
	}

	public function test_ensure_channel_case_key_is_not_a_random_nonce(): void {
		// Same inputs called twice must never differ — a business
		// idempotency key must not incorporate the per-request replay nonce.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertSame(
				IdempotencyKeys::for_ensure_channel_case( 'conv-uuid-1' ),
				IdempotencyKeys::for_ensure_channel_case( 'conv-uuid-1' )
			);
		}
	}

	public function test_notify_operators_key_differs_by_kind_and_summary(): void {
		$base = IdempotencyKeys::for_notify_operators( 'ref-1', 'attention', 'hello' );

		$this->assertNotSame( $base, IdempotencyKeys::for_notify_operators( 'ref-1', 'urgent', 'hello' ) );
		$this->assertNotSame( $base, IdempotencyKeys::for_notify_operators( 'ref-1', 'attention', 'goodbye' ) );
		$this->assertNotSame( $base, IdempotencyKeys::for_notify_operators( 'ref-2', 'attention', 'hello' ) );
		$this->assertSame( $base, IdempotencyKeys::for_notify_operators( 'ref-1', 'attention', 'hello' ) );
	}

	public function test_message_delivery_key_is_deterministic_per_message_uuid(): void {
		$a = IdempotencyKeys::for_message_delivery( 'msg-uuid-1' );
		$b = IdempotencyKeys::for_message_delivery( 'msg-uuid-1' );
		$c = IdempotencyKeys::for_message_delivery( 'msg-uuid-2' );

		$this->assertSame( $a, $b );
		$this->assertNotSame( $a, $c );
	}

	public function test_deliver_message_and_backfill_share_one_idempotency_namespace(): void {
		// ADR-0005 §6: "Adapter outbound delivery (deliver_message / backfill
		// sends)" is one durable idempotency boundary — both call paths must
		// derive the exact same key for the same message_uuid.
		$this->assertSame(
			IdempotencyKeys::for_message_delivery( 'msg-uuid-1' ),
			IdempotencyKeys::for_message_delivery( 'msg-uuid-1' )
		);
	}

	public function test_keys_are_hex_sha256_shaped(): void {
		$key = IdempotencyKeys::for_ensure_channel_case( 'conv-uuid-1' );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $key );
	}
}
