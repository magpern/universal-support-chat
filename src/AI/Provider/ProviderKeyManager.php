<?php
/**
 * AI provider API-key storage (ADR-0018 §7).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\AI\Provider;

use UniversalSupportChat\Core\Security\CredentialState;
use UniversalSupportChat\Core\Security\CredentialUnavailableException;
use UniversalSupportChat\Core\Security\CredentialVault;

/**
 * The provider API token is encrypted with {@see CredentialVault} (AAD
 * context `ai.provider_api_key`) and stored as an opaque envelope in the
 * `autoload = false` option `universal_support_chat_ai_provider_secret`.
 *
 * It is never a key in `universal_support_chat_settings`, never rendered
 * back to any admin screen, and never audited. Written only through the
 * dedicated nonce + `MANAGE` `admin_post` action
 * ({@see \UniversalSupportChat\AI\Admin\ProviderKeyAction}). Modelled on
 * {@see \UniversalSupportChat\ChannelContract\Auth\OwnKeyManager}.
 */
final class ProviderKeyManager {

	public const OPTION_SECRET = 'universal_support_chat_ai_provider_secret';
	public const VAULT_CONTEXT = 'ai.provider_api_key';

	/**
	 * Credential vault.
	 *
	 * @var CredentialVault
	 */
	private CredentialVault $vault;

	/**
	 * Constructor.
	 *
	 * @param CredentialVault $vault Credential vault.
	 */
	public function __construct( CredentialVault $vault ) {
		$this->vault = $vault;
	}

	/**
	 * Stores (or replaces) the provider token. Returns false fail-closed if
	 * the vault key cannot be resolved.
	 *
	 * @param string $token Raw provider API token.
	 */
	public function set( string $token ): bool {
		$token = trim( $token );

		if ( '' === $token ) {
			return false;
		}

		try {
			$envelope = $this->vault->encrypt( $token, self::VAULT_CONTEXT );
		} catch ( CredentialUnavailableException $exception ) {
			return false;
		}

		update_option( self::OPTION_SECRET, $envelope, false );

		return true;
	}

	/**
	 * Replaces the stored token. Semantically identical to {@see set()};
	 * named separately so the admin action and audit event can distinguish
	 * a first configuration from a rotation.
	 *
	 * @param string $token Raw provider API token.
	 */
	public function rotate( string $token ): bool {
		return $this->set( $token );
	}

	/**
	 * Removes the stored token.
	 */
	public function clear(): void {
		delete_option( self::OPTION_SECRET );
	}

	/**
	 * Whether a token envelope is stored (does not prove it can be decrypted).
	 */
	public function is_configured(): bool {
		$stored = get_option( self::OPTION_SECRET, '' );

		return is_string( $stored ) && '' !== $stored;
	}

	/**
	 * The decrypted token, or null fail-closed (no envelope, malformed
	 * envelope, or the vault key is unavailable). Callers pass this straight
	 * to the provider adapter and never render or log it.
	 */
	public function token(): ?string {
		$stored = get_option( self::OPTION_SECRET, '' );

		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}

		$result = $this->vault->decrypt( $stored, self::VAULT_CONTEXT );

		if ( CredentialState::AVAILABLE !== $result->state() ) {
			return null;
		}

		return $result->plaintext();
	}

	/**
	 * Whether the stored token can currently be decrypted — the Diagnostics
	 * fail-closed probe. Never exposes the token itself.
	 */
	public function decrypts_ok(): bool {
		return null !== $this->token();
	}
}
