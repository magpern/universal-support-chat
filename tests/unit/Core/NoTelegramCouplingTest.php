<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Core;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use SplFileInfo;

/**
 * Structural proof SC-M01 runtime has no Universal Telegram / Telegram coupling.
 */
final class NoTelegramCouplingTest extends TestCase {

	/**
	 * The sole, narrow, ADR-0008/ADR-0009-authorized exceptions to this
	 * repository's "no Universal Telegram coupling" rule: the in-process
	 * legacy export boundary consumer (`LegacyExportClient`, the
	 * interface, and `InProcessLegacyExportClient`, its only
	 * implementation), the in-process quiescence-signal consumer
	 * (`UniversalTelegramQuiescenceStateProvider`, ADR-0008 §6 /
	 * `docs/closure/sc-m03-wp3-4-phase-b-continuous-quiescence-recheck-addendum.md`),
	 * and the in-process legacy binding-preparation boundary consumer
	 * (`LegacyBindingImportClient`, the interface, and
	 * `InProcessLegacyBindingImportClient`, its only implementation —
	 * ADR-0009 §2). These five files are the entire cross-plugin reference
	 * surface — every other file under `src/Migration/`, and everywhere
	 * else in `src/`, remains fully decoupled and is still checked below.
	 *
	 * @var array<int, string>
	 */
	private const AUTHORIZED_EXCEPTIONS = array(
		'/src/Migration/LegacyExportClient.php',
		'/src/Migration/InProcessLegacyExportClient.php',
		'/src/Migration/UniversalTelegramQuiescenceStateProvider.php',
		'/src/Migration/LegacyBindingImportClient.php',
		'/src/Migration/InProcessLegacyBindingImportClient.php',
	);

	public function test_src_has_no_telegram_or_ut_coupling(): void {
		$root     = dirname( __DIR__, 3 ) . '/src';
		$patterns = array(
			'/\\\\?UniversalTelegram\\\\/',
			'/universal_telegram_/',
			'/TopicCreation/',
			'/TopicDeletion/',
			'/ActionScheduler/',
			'/as_schedule_/',
			'/as_enqueue_/',
			'/WooCommerce/',
		);

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS )
		);
		$files    = new RegexIterator( $iterator, '/\\.php$/' );

		$hits = array();
		foreach ( $files as $file ) {
			/** @var SplFileInfo $file */
			$path = $file->getPathname();

			foreach ( self::AUTHORIZED_EXCEPTIONS as $exception ) {
				if ( str_ends_with( $path, $exception ) ) {
					continue 2;
				}
			}

			$code = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local filesystem scan.
			foreach ( $patterns as $pattern ) {
				if ( 1 === preg_match( $pattern, $code ) ) {
					$hits[] = $path . ' matched ' . $pattern;
				}
			}

			// CREATE TABLE bodies must never define Telegram-native columns.
			if ( preg_match( '/CREATE TABLE.*?\\)/s', $code, $match ) ) {
				$ddl = $match[0];
				foreach ( array( 'telegram_topic_id', 'telegram_message_id', 'bot_id', 'destination_id' ) as $col ) {
					if ( false !== strpos( $ddl, $col ) ) {
						$hits[] = $path . ' CREATE TABLE contains ' . $col;
					}
				}
			}
		}

		$this->assertSame( array(), $hits, implode( "\n", $hits ) );
	}

	public function test_schema_constants_are_support_chat_owned(): void {
		$migrator = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Persistence/Migrator.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local filesystem read.
		$this->assertStringContainsString( 'universal_support_chat_conversations', $migrator );
		$this->assertStringContainsString( 'universal_support_chat_conversation_messages', $migrator );
		$this->assertStringNotContainsString( 'universal_telegram_', $migrator );
	}

	/**
	 * The three files above are deliberately exempted from the broad
	 * Telegram-coupling scan because ADR-0008 §2/§6 authorizes them to
	 * reference Universal Telegram's namespace in-process. That
	 * authorization is narrow: it never extends to reading a
	 * `universal_telegram_*`-prefixed `$wpdb` table directly (ADR-0008 §5,
	 * "No permanent cross-plugin SQL access" / ADR-0002's plugin-ownership
	 * boundary). This test enforces that narrower guarantee specifically
	 * for the files the broad scan above cannot see — it looks for the
	 * table prefix inside a PHP string literal (an actual query/identifier
	 * use), not inside a docblock's backtick-quoted prose disclaiming it
	 * (every one of these three files' docblocks mentions
	 * `universal_telegram_*` in exactly that disclaiming way).
	 */
	public function test_authorized_exceptions_never_touch_a_universal_telegram_wpdb_table(): void {
		$project_root = dirname( __DIR__, 3 );
		$hits         = array();

		foreach ( self::AUTHORIZED_EXCEPTIONS as $exception ) {
			$path = $project_root . $exception;
			$code = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local filesystem scan.

			if ( 1 === preg_match( '/[\'"]universal_telegram_/', $code ) ) {
				$hits[] = $path;
			}
		}

		$this->assertSame( array(), $hits, implode( "\n", $hits ) );
	}
}
