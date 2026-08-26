<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Migration;

use PHPUnit\Framework\TestCase;
use UniversalSupportChat\Migration\DefaultDenyQuiescenceStateProvider;

/**
 * @covers \UniversalSupportChat\Migration\DefaultDenyQuiescenceStateProvider
 */
final class DefaultDenyQuiescenceStateProviderTest extends TestCase {

	public function test_is_quiescent_is_always_false(): void {
		$provider = new DefaultDenyQuiescenceStateProvider();

		$this->assertFalse( $provider->is_quiescent() );
	}

	public function test_since_is_always_null(): void {
		$provider = new DefaultDenyQuiescenceStateProvider();

		$this->assertNull( $provider->since() );
	}
}
