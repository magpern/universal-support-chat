<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Core;

use PHPUnit\Framework\TestCase;
use UniversalSupportChat\Core\Plugin;
use ReflectionClass;

final class PluginTest extends TestCase {

	public function test_instance_returns_singleton(): void {
		$a = Plugin::instance();
		$b = Plugin::instance();
		$this->assertSame( $a, $b );
	}

	public function test_constructor_is_private(): void {
		$reflection = new ReflectionClass( Plugin::class );
		$this->assertTrue( $reflection->getConstructor()->isPrivate() );
	}
}
