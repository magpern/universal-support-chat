<?php
/**
 * Production QuiescenceStateProvider: delegates in-process to Universal
 * Telegram's quiescence_status() accessor.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Migration;

/**
 * The real, non-default-deny `QuiescenceStateProvider` implementation
 * (SC-M03 WP3-4 Phase B continuous quiescence re-check addendum,
 * `docs/closure/sc-m03-wp3-4-phase-b-continuous-quiescence-recheck-addendum.md`).
 * Delegates to Universal Telegram's `quiescence_status()` accessor on
 * `\UniversalTelegram\Core\Plugin` (ADR-0040 §8, frozen on the Universal
 * Telegram side), following the exact defensive-call shape
 * `InProcessLegacyExportClient` already uses for the legacy export boundary:
 * `class_exists()` guard, `method_exists()` guard, a null-check on the
 * accessor's return, and a `try`/`catch` around the delegated call. Every
 * failure path — Universal Telegram inactive, running an incompatible
 * version, throwing, or returning `null` — fails closed to this class's own
 * defaults (`is_quiescent(): false`, `since(): null`), the same fail-closed
 * discipline `DefaultDenyQuiescenceStateProvider` provides unconditionally
 * and `InProcessLegacyExportClient` applies on every one of its own error
 * paths.
 *
 * Like `InProcessLegacyExportClient`, this is the only other place in this
 * repository allowed to reference Universal Telegram's namespace
 * (`tests/unit/Core/NoTelegramCouplingTest.php`'s authorized-exception
 * list), and it does so only via fully-qualified class names behind
 * `class_exists()` — never a `use` import at file scope, which would fail
 * to autoload when Universal Telegram is inactive. This class never reads a
 * `universal_telegram_*` `$wpdb` table directly; it only ever calls the
 * frozen `quiescence_status()` accessor.
 */
final class UniversalTelegramQuiescenceStateProvider implements QuiescenceStateProvider {

	private const UT_PLUGIN_CLASS = '\UniversalTelegram\Core\Plugin';

	/**
	 * {@inheritDoc}
	 */
	public function is_quiescent(): bool {
		$status = $this->quiescence_status();

		if ( null === $status ) {
			return false;
		}

		return (bool) $status->is_quiescent;
	}

	/**
	 * {@inheritDoc}
	 */
	public function since(): ?\DateTimeImmutable {
		$status = $this->quiescence_status();

		if ( null === $status ) {
			return null;
		}

		return $status->since;
	}

	/**
	 * Fetches Universal Telegram's `?QuiescenceStatus` value object
	 * in-process, defensively. Returns `null` on every failure path:
	 * Universal Telegram inactive, an incompatible version missing the
	 * accessor, the accessor throwing, or the accessor itself returning
	 * `null`.
	 *
	 * @return object|null The `\UniversalTelegram\Migration\QuiescenceStatus`
	 *                      value object (readonly `bool $is_quiescent`,
	 *                      `?\DateTimeImmutable $since`), or `null`.
	 */
	private function quiescence_status(): ?object {
		if ( ! class_exists( self::UT_PLUGIN_CLASS ) ) {
			return null;
		}

		$ut_plugin = \UniversalTelegram\Core\Plugin::instance();

		if ( ! method_exists( $ut_plugin, 'quiescence_status' ) ) {
			return null;
		}

		try {
			$status = $ut_plugin->quiescence_status();
		} catch ( \Throwable $exception ) {
			return null;
		}

		if ( null === $status ) {
			return null;
		}

		return $status;
	}
}
