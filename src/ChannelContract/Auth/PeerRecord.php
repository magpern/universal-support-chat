<?php
/**
 * Paired adapter peer snapshot (ADR-0007 §2).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\ChannelContract\Auth;

/**
 * Immutable snapshot of one paired peer's public key and pairing metadata.
 * Never holds a private key or any secret material.
 */
final class PeerRecord {

	public const STATUS_ACTIVE   = 'active';
	public const STATUS_DISABLED = 'disabled';
	public const STATUS_REVOKED  = 'revoked';

	/**
	 * Primary key.
	 *
	 * @var int
	 */
	private int $id;

	/**
	 * Peer slug, e.g. "universal-telegram".
	 *
	 * @var string
	 */
	private string $peer_id;

	/**
	 * Peer's Ed25519 public key, base64-encoded (32 raw bytes).
	 *
	 * @var string
	 */
	private string $public_key_base64;

	/**
	 * Peer's current key ID (ADR-0007 §3 key-ID format).
	 *
	 * @var string
	 */
	private string $key_id;

	/**
	 * Permitted operation allow-list, drawn from ContractOperations::ADAPTER_TO_SUPPORT_CHAT.
	 *
	 * @var array<int, string>
	 */
	private array $allowed_operations;

	/**
	 * WordPress capability the pairing administrator was also required to
	 * hold, or null if none was recorded.
	 *
	 * @var string|null
	 */
	private ?string $required_peer_capability;

	/**
	 * The REST route base Support Chat targets when calling this peer for
	 * a Support-Chat-to-adapter Contract v1 operation (e.g.
	 * "universal-telegram/v1/support-chat"), or null if not configured —
	 * outbound calls to this peer fail closed until it is set.
	 *
	 * @var string|null
	 */
	private ?string $outbound_route_base;

	/**
	 * Stored status: active, disabled, or revoked.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Pairing creation time (UTC mysql).
	 *
	 * @var string
	 */
	private string $created_at;

	/**
	 * Last key-replace time, if any (UTC mysql).
	 *
	 * @var string|null
	 */
	private ?string $last_rotated_at;

	/**
	 * Last successful authenticated call time, if any (UTC mysql).
	 *
	 * @var string|null
	 */
	private ?string $last_used_at;

	/**
	 * Expiry time, if any (UTC mysql). Null means no expiry.
	 *
	 * @var string|null
	 */
	private ?string $expires_at;

	/**
	 * Revocation time, if any (UTC mysql).
	 *
	 * @var string|null
	 */
	private ?string $revoked_at;

	/**
	 * Constructor.
	 *
	 * @param int                 $id                        Primary key.
	 * @param string              $peer_id                   Peer slug.
	 * @param string              $public_key_base64         Base64 public key.
	 * @param string              $key_id                    Peer key ID.
	 * @param array<int, string>  $allowed_operations        Permitted operations.
	 * @param string|null         $required_peer_capability  Required peer capability.
	 * @param string              $status                    Stored status.
	 * @param string              $created_at                Created at.
	 * @param string|null         $last_rotated_at            Last rotated at.
	 * @param string|null         $last_used_at              Last used at.
	 * @param string|null         $expires_at                Expires at.
	 * @param string|null         $revoked_at                Revoked at.
	 * @param string|null         $outbound_route_base       Outbound REST route base, or null.
	 */
	public function __construct(
		int $id,
		string $peer_id,
		string $public_key_base64,
		string $key_id,
		array $allowed_operations,
		?string $required_peer_capability,
		string $status,
		string $created_at,
		?string $last_rotated_at,
		?string $last_used_at,
		?string $expires_at,
		?string $revoked_at,
		?string $outbound_route_base = null
	) {
		$this->id                       = $id;
		$this->peer_id                  = $peer_id;
		$this->public_key_base64        = $public_key_base64;
		$this->key_id                   = $key_id;
		$this->allowed_operations       = $allowed_operations;
		$this->required_peer_capability = $required_peer_capability;
		$this->status                   = $status;
		$this->created_at               = $created_at;
		$this->last_rotated_at          = $last_rotated_at;
		$this->last_used_at             = $last_used_at;
		$this->expires_at               = $expires_at;
		$this->revoked_at               = $revoked_at;
		$this->outbound_route_base      = $outbound_route_base;
	}

	/**
	 * Primary key.
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * Peer slug.
	 */
	public function peer_id(): string {
		return $this->peer_id;
	}

	/**
	 * Base64-encoded public key.
	 */
	public function public_key_base64(): string {
		return $this->public_key_base64;
	}

	/**
	 * Raw 32-byte public key, or null if the stored value cannot decode.
	 */
	public function public_key_raw(): ?string {
		$decoded = base64_decode( $this->public_key_base64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- transport decoding, not obfuscation.

		if ( false === $decoded || 32 !== strlen( $decoded ) ) {
			return null;
		}

		return $decoded;
	}

	/**
	 * Peer's current key ID.
	 */
	public function key_id(): string {
		return $this->key_id;
	}

	/**
	 * Permitted operation allow-list.
	 *
	 * @return array<int, string>
	 */
	public function allowed_operations(): array {
		return $this->allowed_operations;
	}

	/**
	 * Whether the given operation is on this peer's allow-list.
	 *
	 * @param string $operation Operation name.
	 */
	public function allows( string $operation ): bool {
		return in_array( $operation, $this->allowed_operations, true );
	}

	/**
	 * Required peer capability, if recorded.
	 */
	public function required_peer_capability(): ?string {
		return $this->required_peer_capability;
	}

	/**
	 * The REST route base Support Chat targets when calling this peer for a
	 * Support-Chat-to-adapter Contract v1 operation, or null if not yet
	 * configured (outbound calls to this peer fail closed until it is).
	 */
	public function outbound_route_base(): ?string {
		return $this->outbound_route_base;
	}

	/**
	 * Stored status.
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Pairing creation time.
	 */
	public function created_at(): string {
		return $this->created_at;
	}

	/**
	 * Last key-replace time, if any.
	 */
	public function last_rotated_at(): ?string {
		return $this->last_rotated_at;
	}

	/**
	 * Last successful authenticated call time, if any.
	 */
	public function last_used_at(): ?string {
		return $this->last_used_at;
	}

	/**
	 * Expiry time, if any.
	 */
	public function expires_at(): ?string {
		return $this->expires_at;
	}

	/**
	 * Revocation time, if any.
	 */
	public function revoked_at(): ?string {
		return $this->revoked_at;
	}

	/**
	 * Whether this peer's expiry policy has passed, as of now (UTC).
	 */
	public function is_expired(): bool {
		if ( null === $this->expires_at ) {
			return false;
		}

		return strtotime( $this->expires_at . ' UTC' ) < time();
	}

	/**
	 * Whether this peer's key currently verifies calls: active status,
	 * unrevoked, and unexpired.
	 */
	public function is_usable(): bool {
		return self::STATUS_ACTIVE === $this->status && ! $this->is_expired();
	}

	/**
	 * The operator-facing pairing state (ADR-0007 §2). "degraded" and
	 * "incompatible" require live discovery/callback signals this
	 * authentication server does not yet compute and are never returned
	 * here — see the SC-M03 work package 0 closure record.
	 */
	public function pairing_state(): string {
		if ( self::STATUS_REVOKED === $this->status ) {
			return 'revoked';
		}

		if ( $this->is_expired() ) {
			return 'expired';
		}

		if ( self::STATUS_DISABLED === $this->status ) {
			return 'paired_disabled';
		}

		return 'active';
	}

	/**
	 * Hydrates from a database row.
	 *
	 * @param array<string, mixed> $row Database row.
	 */
	public static function from_row( array $row ): self {
		$decoded = json_decode( (string) $row['allowed_operations'], true );
		$ops     = is_array( $decoded ) ? array_values( array_filter( $decoded, 'is_string' ) ) : array();

		return new self(
			(int) $row['id'],
			(string) $row['peer_id'],
			(string) $row['public_key'],
			(string) $row['key_id'],
			$ops,
			self::nullable_string( $row['required_peer_capability'] ?? null ),
			(string) $row['status'],
			(string) $row['created_at'],
			self::nullable_string( $row['last_rotated_at'] ?? null ),
			self::nullable_string( $row['last_used_at'] ?? null ),
			self::nullable_string( $row['expires_at'] ?? null ),
			self::nullable_string( $row['revoked_at'] ?? null ),
			self::nullable_string( $row['outbound_route_base'] ?? null )
		);
	}

	/**
	 * Coerces a nullable string column.
	 *
	 * @param mixed $value Raw column value.
	 */
	private static function nullable_string( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return (string) $value;
	}
}
