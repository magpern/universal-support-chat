<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\ChannelContract\Auth;

use UniversalSupportChat\ChannelContract\Auth\ContractIdentity;
use UniversalSupportChat\ChannelContract\Auth\KeyId;
use UniversalSupportChat\ChannelContract\Auth\OwnKeyManager;
use UniversalSupportChat\Core\Security\CredentialVault;
use WP_UnitTestCase;

final class OwnKeyManagerTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'universal_support_chat_contract_own_key' );
		delete_option( 'universal_support_chat_contract_own_key_secret' );
	}

	public function test_ensure_key_pair_generates_and_is_idempotent(): void {
		$manager = new OwnKeyManager( new CredentialVault() );

		$first  = $manager->ensure_key_pair();
		$second = $manager->ensure_key_pair();

		$this->assertNotNull( $first );
		$this->assertSame( $first, $second );
		$this->assertSame( ContractIdentity::SELF_ID . '.', substr( $first['key_id'], 0, strlen( ContractIdentity::SELF_ID ) + 1 ) );
		$this->assertTrue( KeyId::is_valid_format( $first['key_id'] ) );
	}

	public function test_rotate_replaces_key_and_key_id(): void {
		$manager = new OwnKeyManager( new CredentialVault() );

		$first  = $manager->ensure_key_pair();
		$second = $manager->rotate();

		$this->assertNotNull( $first );
		$this->assertNotNull( $second );
		$this->assertNotSame( $first['public_key'], $second['public_key'] );
		$this->assertNotSame( $first['key_id'], $second['key_id'] );
	}

	public function test_secret_key_never_appears_in_the_public_key_option(): void {
		$manager = new OwnKeyManager( new CredentialVault() );
		$manager->ensure_key_pair();

		$secret = $manager->secret_key_raw();
		$this->assertNotNull( $secret );

		$public_option = get_option( 'universal_support_chat_contract_own_key' );
		$blob          = wp_json_encode( $public_option );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- comparing against stored encoding, not obfuscating.
		$this->assertStringNotContainsString( base64_encode( $secret ), (string) $blob );

		$secret_option = get_option( 'universal_support_chat_contract_own_key_secret' );
		$this->assertIsString( $secret_option );
		$this->assertStringStartsWith( 'usc1:', $secret_option );
		$this->assertStringNotContainsString( bin2hex( $secret ), $secret_option );
	}

	public function test_secret_key_matches_public_key_pair(): void {
		$manager = new OwnKeyManager( new CredentialVault() );
		$pair    = $manager->ensure_key_pair();
		$secret  = $manager->secret_key_raw();

		$this->assertNotNull( $pair );
		$this->assertNotNull( $secret );

		$derived_public = sodium_crypto_sign_publickey_from_secretkey( $secret );
		$this->assertSame( base64_decode( $pair['public_key'], true ), $derived_public ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- test-only decode.
	}
}
