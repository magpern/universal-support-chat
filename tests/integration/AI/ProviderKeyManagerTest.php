<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\AI;

use UniversalSupportChat\AI\Provider\ProviderKeyManager;
use UniversalSupportChat\Core\Security\CredentialVault;
use WP_UnitTestCase;

/**
 * SC-M07 WP2 — the provider token is vault-encrypted, stored in an
 * autoload=false option, round-trips, and is never stored in cleartext.
 */
final class ProviderKeyManagerTest extends WP_UnitTestCase {

	private ProviderKeyManager $keys;

	public function set_up(): void {
		parent::set_up();
		$this->keys = new ProviderKeyManager( new CredentialVault() );
		$this->keys->clear();
	}

	public function test_set_encrypts_at_rest_and_token_round_trips(): void {
		$this->assertFalse( $this->keys->is_configured() );

		$this->assertTrue( $this->keys->set( 'sk-test-secret-value-123' ) );
		$this->assertTrue( $this->keys->is_configured() );
		$this->assertSame( 'sk-test-secret-value-123', $this->keys->token() );
		$this->assertTrue( $this->keys->decrypts_ok() );

		$stored = get_option( ProviderKeyManager::OPTION_SECRET );
		$this->assertIsString( $stored );
		$this->assertStringNotContainsString( 'sk-test-secret-value-123', $stored );
		$this->assertStringStartsWith( 'usc1:', $stored );
	}

	public function test_secret_option_is_not_autoloaded(): void {
		$this->keys->set( 'sk-abc' );

		global $wpdb;
		$autoload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ProviderKeyManager::OPTION_SECRET
			)
		);

		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	public function test_rotate_replaces_and_clear_removes(): void {
		$this->keys->set( 'sk-first' );
		$this->assertTrue( $this->keys->rotate( 'sk-second' ) );
		$this->assertSame( 'sk-second', $this->keys->token() );

		$this->keys->clear();
		$this->assertFalse( $this->keys->is_configured() );
		$this->assertNull( $this->keys->token() );
	}

	public function test_empty_token_is_rejected(): void {
		$this->assertFalse( $this->keys->set( '   ' ) );
		$this->assertFalse( $this->keys->is_configured() );
	}
}
