<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Migration;

use PHPUnit\Framework\TestCase;
use UniversalSupportChat\Migration\UniversalTelegramQuiescenceStateProvider;

/**
 * @covers \UniversalSupportChat\Migration\UniversalTelegramQuiescenceStateProvider
 *
 * Scope note: this repository's own unit test process never loads Universal
 * Telegram, so `class_exists('\UniversalTelegram\Core\Plugin')` is always
 * `false` here — there is no way, at this level, to exercise any of the
 * "Universal Telegram active" branches (no `quiescence_status()` method, the
 * accessor throwing, the accessor returning `null`, or the accessor
 * returning a `QuiescenceStatus` with `is_quiescent = true`). Faking that by
 * declaring a stub `\UniversalTelegram\Core\Plugin` class at runtime was
 * considered and rejected: this repository has no existing precedent for
 * stubbing an absent cross-plugin class in a unit test (checked
 * `InProcessLegacyExportClient`, the direct precedent this provider mirrors
 * — it has no unit test of its own for exactly this reason, only
 * `tests/integration/Interop/LegacyExportClientIntegrationTest.php`, which
 * runs against the real Universal Telegram plugin), and PHPUnit here runs
 * every test in one shared PHP process (`phpunit.xml.dist` has no process
 * isolation), so a stub class declared for this file would leak into every
 * other test that runs afterwards. All five "Universal Telegram active"
 * scenarios are therefore deferred to the dual-plugin interop suite (not
 * built in this task — see the addendum's own "What this addendum does not
 * authorize" section); only the fail-closed "class absent" path is unit
 * tested here.
 */
final class UniversalTelegramQuiescenceStateProviderTest extends TestCase {

	public function test_is_quiescent_is_false_when_universal_telegram_is_not_active(): void {
		$provider = new UniversalTelegramQuiescenceStateProvider();

		$this->assertFalse( $provider->is_quiescent() );
	}

	public function test_since_is_null_when_universal_telegram_is_not_active(): void {
		$provider = new UniversalTelegramQuiescenceStateProvider();

		$this->assertNull( $provider->since() );
	}
}
