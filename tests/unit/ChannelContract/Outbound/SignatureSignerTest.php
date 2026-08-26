<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\ChannelContract\Outbound;

use UniversalSupportChat\ChannelContract\Auth\ContractIdentity;
use UniversalSupportChat\ChannelContract\Auth\OwnKeyManager;
use UniversalSupportChat\ChannelContract\ContractDiscovery;
use UniversalSupportChat\ChannelContract\Outbound\SignatureSigner;
use PHPUnit\Framework\TestCase;

final class SignatureSignerTest extends TestCase {

	public function test_returns_null_when_own_key_is_missing(): void {
		$own_key = $this->createMock( OwnKeyManager::class );
		$own_key->method( 'public_key' )->willReturn( null );

		$signer = new SignatureSigner( $own_key );

		$this->assertNull( $signer->sign( 'POST', '/universal-telegram/v1/support-chat/deliver_message', '{}', 'universal-telegram' ) );
	}

	public function test_returns_null_when_secret_key_is_unavailable(): void {
		$pair = sodium_crypto_sign_keypair();

		$own_key = $this->createMock( OwnKeyManager::class );
		$own_key->method( 'public_key' )->willReturn(
			array(
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture encoding, not obfuscation.
				'public_key' => base64_encode( sodium_crypto_sign_publickey( $pair ) ),
				'key_id'     => 'universal-support-chat.0011223344556677',
			)
		);
		$own_key->method( 'secret_key_raw' )->willReturn( null );

		$signer = new SignatureSigner( $own_key );

		$this->assertNull( $signer->sign( 'POST', '/universal-telegram/v1/support-chat/deliver_message', '{}', 'universal-telegram' ) );
	}

	public function test_signs_a_valid_ten_line_canonical_string(): void {
		$pair       = sodium_crypto_sign_keypair();
		$public_raw = sodium_crypto_sign_publickey( $pair );
		$secret_raw = sodium_crypto_sign_secretkey( $pair );
		$key_id     = 'universal-support-chat.0011223344556677';

		$own_key = $this->createMock( OwnKeyManager::class );
		$own_key->method( 'public_key' )->willReturn(
			array(
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture encoding, not obfuscation.
				'public_key' => base64_encode( $public_raw ),
				'key_id'     => $key_id,
			)
		);
		$own_key->method( 'secret_key_raw' )->willReturn( $secret_raw );

		$signer   = new SignatureSigner( $own_key );
		$method   = 'post';
		$path     = '/universal-telegram/v1/support-chat/deliver_message';
		$raw_body = '{"channel_case_ref":"abc"}';

		$headers = $signer->sign( $method, $path, $raw_body, 'universal-telegram' );

		$this->assertIsArray( $headers );
		$this->assertSame( ContractDiscovery::CONTRACT_VERSION_ID, $headers['X-SC-Contract-Version'] );
		$this->assertSame( ContractIdentity::AUTH_PROFILE_ID, $headers['X-SC-Auth-Profile'] );
		$this->assertSame( ContractIdentity::SELF_ID, $headers['X-SC-Sender'] );
		$this->assertSame( 'universal-telegram', $headers['X-SC-Audience'] );
		$this->assertSame( $key_id, $headers['X-SC-Key-Id'] );
		$this->assertMatchesRegularExpression( '/^\d+$/', $headers['X-SC-Timestamp'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{22}$/', $headers['X-SC-Nonce'] );
		$this->assertSame( hash( 'sha256', $raw_body ), $headers['X-SC-Body-Sha256'] );

		$canonical = implode(
			"\n",
			array(
				ContractIdentity::AUTH_PROFILE_ID,
				ContractDiscovery::CONTRACT_VERSION_ID,
				ContractIdentity::SELF_ID,
				'universal-telegram',
				$key_id,
				$headers['X-SC-Timestamp'],
				$headers['X-SC-Nonce'],
				'POST',
				$path,
				hash( 'sha256', $raw_body ),
			)
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- test fixture decoding, not obfuscation.
		$signature = base64_decode( $headers['X-SC-Signature'], true );
		$this->assertNotFalse( $signature );
		$this->assertSame( 64, strlen( $signature ) );
		$this->assertTrue( sodium_crypto_sign_verify_detached( $signature, $canonical, $public_raw ) );
	}

	public function test_signature_does_not_verify_against_a_tampered_route(): void {
		$pair       = sodium_crypto_sign_keypair();
		$public_raw = sodium_crypto_sign_publickey( $pair );
		$secret_raw = sodium_crypto_sign_secretkey( $pair );

		$own_key = $this->createMock( OwnKeyManager::class );
		$own_key->method( 'public_key' )->willReturn(
			array(
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture encoding, not obfuscation.
				'public_key' => base64_encode( $public_raw ),
				'key_id'     => 'universal-support-chat.0011223344556677',
			)
		);
		$own_key->method( 'secret_key_raw' )->willReturn( $secret_raw );

		$signer  = new SignatureSigner( $own_key );
		$headers = $signer->sign( 'POST', '/universal-telegram/v1/support-chat/deliver_message', '{}', 'universal-telegram' );
		$this->assertIsArray( $headers );

		$tampered_canonical = implode(
			"\n",
			array(
				ContractIdentity::AUTH_PROFILE_ID,
				ContractDiscovery::CONTRACT_VERSION_ID,
				ContractIdentity::SELF_ID,
				'universal-telegram',
				$headers['X-SC-Key-Id'],
				$headers['X-SC-Timestamp'],
				$headers['X-SC-Nonce'],
				'POST',
				'/universal-telegram/v1/support-chat/ensure_channel_case',
				$headers['X-SC-Body-Sha256'],
			)
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- test fixture decoding, not obfuscation.
		$signature = base64_decode( $headers['X-SC-Signature'], true );
		$this->assertFalse( sodium_crypto_sign_verify_detached( $signature, $tampered_canonical, $public_raw ) );
	}
}
