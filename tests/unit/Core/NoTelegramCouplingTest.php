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
}
