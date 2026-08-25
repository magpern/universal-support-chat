<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\ChannelContract\Auth;

use UniversalSupportChat\ChannelContract\Auth\ContractOperations;
use UniversalSupportChat\ChannelContract\Auth\KeyId;
use UniversalSupportChat\ChannelContract\Auth\PairingResult;
use UniversalSupportChat\ChannelContract\Auth\PairingService;
use UniversalSupportChat\ChannelContract\Auth\PeerRecord;
use UniversalSupportChat\ChannelContract\Auth\PeerRepository;
use UniversalSupportChat\Audit\AuditLogger;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalSupportChat\Privacy\Redactor;
use WP_UnitTestCase;

final class PairingServiceTest extends WP_UnitTestCase {

	private PairingService $pairing;
	private PeerRepository $peers;

	public function set_up(): void {
		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		$health        = new SchemaHealth();
		$this->peers   = new PeerRepository( $health );
		$this->pairing = new PairingService( $this->peers, new AuditLogger( $health, new Redactor() ) );
	}

	/**
	 * @return array{public_key: string, key_id: string}
	 */
	private function fresh_peer_key( string $peer_id ): array {
		$pair       = sodium_crypto_sign_keypair();
		$public_raw = sodium_crypto_sign_publickey( $pair );

		return array(
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture encoding.
			'public_key' => base64_encode( $public_raw ),
			'key_id'     => KeyId::compute( $peer_id, $public_raw ),
		);
	}

	public function test_first_pairing_creates_active_peer(): void {
		$key    = $this->fresh_peer_key( 'universal-telegram' );
		$result = $this->pairing->pair(
			'universal-telegram',
			$key['public_key'],
			$key['key_id'],
			ContractOperations::ADAPTER_TO_SUPPORT_CHAT,
			'universal_telegram_manage',
			false,
			1
		);

		$this->assertTrue( $result->ok() );
		$this->assertSame( PairingResult::REASON_CREATED, $result->reason() );

		$peer = $this->peers->find_by_peer_id( 'universal-telegram' );
		$this->assertNotNull( $peer );
		$this->assertSame( 'active', $peer->pairing_state() );
	}

	public function test_repairing_with_identical_key_is_idempotent(): void {
		$key = $this->fresh_peer_key( 'universal-telegram' );
		$this->pairing->pair( 'universal-telegram', $key['public_key'], $key['key_id'], ContractOperations::ADAPTER_TO_SUPPORT_CHAT, 'universal_telegram_manage', false, 1 );

		$result = $this->pairing->pair( 'universal-telegram', $key['public_key'], $key['key_id'], ContractOperations::ADAPTER_TO_SUPPORT_CHAT, 'universal_telegram_manage', false, 1 );

		$this->assertTrue( $result->ok() );
		$this->assertSame( PairingResult::REASON_UNCHANGED, $result->reason() );
	}

	public function test_replacing_an_active_key_requires_confirmation(): void {
		$key = $this->fresh_peer_key( 'universal-telegram' );
		$this->pairing->pair( 'universal-telegram', $key['public_key'], $key['key_id'], ContractOperations::ADAPTER_TO_SUPPORT_CHAT, 'universal_telegram_manage', false, 1 );

		$new_key = $this->fresh_peer_key( 'universal-telegram' );

		$without_confirm = $this->pairing->pair( 'universal-telegram', $new_key['public_key'], $new_key['key_id'], ContractOperations::ADAPTER_TO_SUPPORT_CHAT, 'universal_telegram_manage', false, 1 );
		$this->assertFalse( $without_confirm->ok() );
		$this->assertSame( PairingResult::REASON_CONFIRMATION_REQUIRED, $without_confirm->reason() );

		$peer_unchanged = $this->peers->find_by_peer_id( 'universal-telegram' );
		$this->assertSame( $key['key_id'], $peer_unchanged->key_id() );

		$with_confirm = $this->pairing->pair( 'universal-telegram', $new_key['public_key'], $new_key['key_id'], ContractOperations::ADAPTER_TO_SUPPORT_CHAT, 'universal_telegram_manage', true, 1 );
		$this->assertTrue( $with_confirm->ok() );
		$this->assertSame( PairingResult::REASON_REPLACED, $with_confirm->reason() );

		$peer_replaced = $this->peers->find_by_peer_id( 'universal-telegram' );
		$this->assertSame( $new_key['key_id'], $peer_replaced->key_id() );
	}

	public function test_rotation_is_replacement_and_old_key_stops_verifying(): void {
		$key = $this->fresh_peer_key( 'universal-telegram' );
		$this->pairing->pair( 'universal-telegram', $key['public_key'], $key['key_id'], ContractOperations::ADAPTER_TO_SUPPORT_CHAT, 'universal_telegram_manage', false, 1 );

		$rotated_key = $this->fresh_peer_key( 'universal-telegram' );
		$this->pairing->pair( 'universal-telegram', $rotated_key['public_key'], $rotated_key['key_id'], ContractOperations::ADAPTER_TO_SUPPORT_CHAT, 'universal_telegram_manage', true, 1 );

		$peer = $this->peers->find_by_peer_id( 'universal-telegram' );
		$this->assertNotSame( $key['key_id'], $peer->key_id() );
		$this->assertSame( $rotated_key['key_id'], $peer->key_id() );
	}

	public function test_revocation_makes_peer_unusable(): void {
		$key = $this->fresh_peer_key( 'universal-telegram' );
		$this->pairing->pair( 'universal-telegram', $key['public_key'], $key['key_id'], ContractOperations::ADAPTER_TO_SUPPORT_CHAT, 'universal_telegram_manage', false, 1 );

		$this->assertTrue( $this->pairing->revoke( 'universal-telegram', 1 ) );

		$peer = $this->peers->find_by_peer_id( 'universal-telegram' );
		$this->assertSame( 'revoked', $peer->pairing_state() );
		$this->assertFalse( $peer->is_usable() );
	}

	public function test_disable_and_enable_round_trip(): void {
		$key = $this->fresh_peer_key( 'universal-telegram' );
		$this->pairing->pair( 'universal-telegram', $key['public_key'], $key['key_id'], ContractOperations::ADAPTER_TO_SUPPORT_CHAT, 'universal_telegram_manage', false, 1 );

		$this->pairing->disable( 'universal-telegram', 1 );
		$disabled = $this->peers->find_by_peer_id( 'universal-telegram' );
		$this->assertSame( 'paired_disabled', $disabled->pairing_state() );
		$this->assertFalse( $disabled->is_usable() );

		$this->pairing->enable( 'universal-telegram', 1 );
		$enabled = $this->peers->find_by_peer_id( 'universal-telegram' );
		$this->assertSame( 'active', $enabled->pairing_state() );
		$this->assertTrue( $enabled->is_usable() );
	}

	public function test_expired_peer_is_unusable_and_reports_expired_state(): void {
		$key = $this->fresh_peer_key( 'universal-telegram' );
		$this->peers->create(
			'universal-telegram',
			$key['public_key'],
			$key['key_id'],
			ContractOperations::ADAPTER_TO_SUPPORT_CHAT,
			'universal_telegram_manage',
			gmdate( 'Y-m-d H:i:s', time() - 3600 )
		);

		$peer = $this->peers->find_by_peer_id( 'universal-telegram' );
		$this->assertTrue( $peer->is_expired() );
		$this->assertFalse( $peer->is_usable() );
		$this->assertSame( 'expired', $peer->pairing_state() );
	}

	public function test_pairing_rejects_operation_not_on_the_contract_v1_allow_list(): void {
		$key    = $this->fresh_peer_key( 'universal-telegram' );
		$result = $this->pairing->pair(
			'universal-telegram',
			$key['public_key'],
			$key['key_id'],
			array( 'ensure_channel_case' ),
			'universal_telegram_manage',
			false,
			1
		);

		$this->assertFalse( $result->ok() );
		$this->assertSame( PairingResult::REASON_INVALID_INPUT, $result->reason() );
	}

	public function test_pairing_rejects_key_id_that_does_not_match_the_public_key(): void {
		$key    = $this->fresh_peer_key( 'universal-telegram' );
		$result = $this->pairing->pair(
			'universal-telegram',
			$key['public_key'],
			'universal-telegram.0000000000000000',
			ContractOperations::ADAPTER_TO_SUPPORT_CHAT,
			'universal_telegram_manage',
			false,
			1
		);

		$this->assertFalse( $result->ok() );
		$this->assertSame( PairingResult::REASON_INVALID_INPUT, $result->reason() );
	}

	public function test_audit_trail_never_contains_key_material(): void {
		$key = $this->fresh_peer_key( 'universal-telegram' );
		$this->pairing->pair( 'universal-telegram', $key['public_key'], $key['key_id'], ContractOperations::ADAPTER_TO_SUPPORT_CHAT, 'universal_telegram_manage', false, 1 );

		global $wpdb;
		$table = $wpdb->prefix . Migrator::AUDIT_LOG_TABLE;
		$rows  = $wpdb->get_col( "SELECT context FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$blob = implode( "\n", $rows );
		$this->assertStringNotContainsString( $key['public_key'], $blob );
	}
}
