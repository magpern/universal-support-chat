<?php
/**
 * Support Chat's own Ed25519 key pair (ADR-0007 §1-§2).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\ChannelContract\Auth;

use UniversalSupportChat\Core\Security\CredentialState;
use UniversalSupportChat\Core\Security\CredentialUnavailableException;
use UniversalSupportChat\Core\Security\CredentialVault;

/**
 * Generates and retains this plugin's own Ed25519 key pair. The private key
 * is encrypted in this plugin's own CredentialVault and never leaves this
 * class; only the public key and key ID are ever exposed to callers.
 */
class OwnKeyManager {

	private const OPTION_PUBLIC = 'universal_support_chat_contract_own_key';

	private const OPTION_SECRET = 'universal_support_chat_contract_own_key_secret';

	private const VAULT_CONTEXT = 'contract.own_signing_key';

	/**
	 * Support Chat vault.
	 *
	 * @var CredentialVault
	 */
	private CredentialVault $vault;

	/**
	 * Constructor.
	 *
	 * @param CredentialVault $vault Support Chat vault.
	 */
	public function __construct( CredentialVault $vault ) {
		$this->vault = $vault;
	}

	/**
	 * Generates a key pair if one does not already exist. Idempotent.
	 *
	 * @return array{public_key: string, key_id: string}|null Public key
	 *                (base64) and key ID, or null if a key could not be
	 *                generated or stored.
	 */
	public function ensure_key_pair(): ?array {
		$existing = $this->public_key();
		if ( null !== $existing ) {
			return $existing;
		}

		return $this->generate();
	}

	/**
	 * The current public key and key ID, if a key pair exists.
	 *
	 * @return array{public_key: string, key_id: string}|null
	 */
	public function public_key(): ?array {
		$stored = get_option( self::OPTION_PUBLIC, null );

		if ( ! is_array( $stored ) || ! isset( $stored['public_key'], $stored['key_id'] ) ) {
			return null;
		}

		return array(
			'public_key' => (string) $stored['public_key'],
			'key_id'     => (string) $stored['key_id'],
		);
	}

	/**
	 * Rotates to a brand-new key pair. The prior public key remains
	 * recorded nowhere once overwritten — only the new key is current.
	 *
	 * @return array{public_key: string, key_id: string}|null
	 */
	public function rotate(): ?array {
		return $this->generate();
	}

	/**
	 * The raw 64-byte secret key, for signing outbound calls. Not used by
	 * SC-M03 work package 0 (this server only verifies inbound calls), but
	 * kept for the future Support Chat → adapter signing path.
	 */
	public function secret_key_raw(): ?string {
		$stored = get_option( self::OPTION_SECRET, null );

		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}

		try {
			$result = $this->vault->decrypt( $stored, self::VAULT_CONTEXT );
		} catch ( CredentialUnavailableException $exception ) {
			return null;
		}

		if ( CredentialState::AVAILABLE !== $result->state() ) {
			return null;
		}

		return $result->plaintext();
	}

	/**
	 * Generates, stores, and returns a fresh key pair.
	 *
	 * @return array{public_key: string, key_id: string}|null
	 */
	private function generate(): ?array {
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			return null;
		}

		$pair       = sodium_crypto_sign_keypair();
		$public_raw = sodium_crypto_sign_publickey( $pair );
		$secret_raw = sodium_crypto_sign_secretkey( $pair );

		try {
			$envelope = $this->vault->encrypt( $secret_raw, self::VAULT_CONTEXT );
		} catch ( CredentialUnavailableException $exception ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding, not obfuscation.
		$public_key_base64 = base64_encode( $public_raw );
		$key_id            = KeyId::compute( ContractIdentity::SELF_ID, $public_raw );

		update_option( self::OPTION_SECRET, $envelope, false );
		update_option(
			self::OPTION_PUBLIC,
			array(
				'public_key' => $public_key_base64,
				'key_id'     => $key_id,
				'created_at' => current_time( 'mysql', true ),
			),
			false
		);

		return array(
			'public_key' => $public_key_base64,
			'key_id'     => $key_id,
		);
	}

	/**
	 * Deletes the stored key pair (uninstall only).
	 */
	public function delete(): void {
		delete_option( self::OPTION_PUBLIC );
		delete_option( self::OPTION_SECRET );
	}
}
