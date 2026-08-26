<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\ChannelContract\Outbound;

use UniversalSupportChat\Audit\AuditLogger;
use UniversalSupportChat\ChannelContract\Auth\OwnKeyManager;
use UniversalSupportChat\ChannelContract\Auth\PeerRecord;
use UniversalSupportChat\ChannelContract\Auth\PeerRepository;
use UniversalSupportChat\ChannelContract\Outbound\AdapterContractClient;
use UniversalSupportChat\ChannelContract\Outbound\ContractTransport;
use UniversalSupportChat\ChannelContract\Outbound\SignatureSigner;
use UniversalSupportChat\Persistence\MigrationFailureCode;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalSupportChat\Privacy\Redactor;
use PHPUnit\Framework\TestCase;

final class AdapterContractClientTest extends TestCase {

	/**
	 * A real AuditLogger whose schema is marked unavailable, so record()
	 * always short-circuits before touching $wpdb/current_time() — this
	 * keeps the client fully unit-testable without a WordPress runtime.
	 */
	private function unavailable_audit_logger(): AuditLogger {
		$schema_health = new SchemaHealth();
		$schema_health->mark_unavailable( MigrationFailureCode::LOCK_UNAVAILABLE );

		return new AuditLogger( $schema_health, new Redactor() );
	}

	private function usable_signer(): SignatureSigner {
		$pair = sodium_crypto_sign_keypair();

		$own_key = $this->createMock( OwnKeyManager::class );
		$own_key->method( 'public_key' )->willReturn(
			array(
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture encoding, not obfuscation.
				'public_key' => base64_encode( sodium_crypto_sign_publickey( $pair ) ),
				'key_id'     => 'universal-support-chat.0011223344556677',
			)
		);
		$own_key->method( 'secret_key_raw' )->willReturn( sodium_crypto_sign_secretkey( $pair ) );

		return new SignatureSigner( $own_key );
	}

	private function unsigned_signer(): SignatureSigner {
		$own_key = $this->createMock( OwnKeyManager::class );
		$own_key->method( 'public_key' )->willReturn( null );

		return new SignatureSigner( $own_key );
	}

	/**
	 * @param array<int, string> $allowed_operations
	 */
	private function peer(
		string $peer_id = 'universal-telegram',
		array $allowed_operations = array( 'ensure_channel_case', 'notify_operators', 'deliver_transcript_backfill', 'deliver_message' ),
		string $status = PeerRecord::STATUS_ACTIVE,
		?string $outbound_route_base = 'universal-telegram/v1/support-chat'
	): PeerRecord {
		return new PeerRecord(
			1,
			$peer_id,
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture encoding, not obfuscation.
			base64_encode( str_repeat( 'a', 32 ) ),
			'universal-telegram.aabbccddeeff0011',
			$allowed_operations,
			'universal_telegram_manage',
			$status,
			'2026-01-01 00:00:00',
			null,
			null,
			null,
			null,
			$outbound_route_base
		);
	}

	private function spy_transport( array $response ): ContractTransport {
		return new class( $response ) implements ContractTransport {
			/** @var array<int, array{method: string, route: string, headers: array<string, string>, raw_body: string}> */
			public array $calls = array();

			/** @var array{status: int, ok: bool, body: array<string, mixed>} */
			private array $response;

			public function __construct( array $response ) {
				$this->response = $response;
			}

			public function send( string $method, string $route, array $headers, string $raw_body ): array {
				$this->calls[] = array(
					'method'   => $method,
					'route'    => $route,
					'headers'  => $headers,
					'raw_body' => $raw_body,
				);

				return $this->response;
			}
		};
	}

	public function test_ensure_channel_case_fails_closed_when_peer_not_paired(): void {
		$peers = $this->createMock( PeerRepository::class );
		$peers->method( 'find_by_peer_id' )->willReturn( null );

		$transport = $this->spy_transport(
			array(
				'status' => 200,
				'ok'     => true,
				'body'   => array( 'ok' => true ),
			)
		);

		$client = new AdapterContractClient( $peers, $this->usable_signer(), $transport, $this->unavailable_audit_logger() );

		$result = $client->ensure_channel_case( 'universal-telegram', 'conv-uuid-1', 'escalated' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( AdapterContractClient::REASON_NOT_PAIRED, $result['reason'] );
		$this->assertSame( '', $result['channel_case_ref'] );
		$this->assertCount( 0, $transport->calls );
	}

	public function test_call_fails_closed_when_peer_is_disabled(): void {
		$peers = $this->createMock( PeerRepository::class );
		$peers->method( 'find_by_peer_id' )->willReturn( $this->peer( 'universal-telegram', array( 'deliver_message' ), PeerRecord::STATUS_DISABLED ) );

		$transport = $this->spy_transport(
			array(
				'status' => 200,
				'ok'     => true,
				'body'   => array( 'ok' => true ),
			)
		);

		$client = new AdapterContractClient( $peers, $this->usable_signer(), $transport, $this->unavailable_audit_logger() );

		$result = $client->deliver_message( 'universal-telegram', 'case-ref-1', 'msg-uuid-1', 'hello' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( AdapterContractClient::REASON_NOT_PAIRED, $result['reason'] );
		$this->assertCount( 0, $transport->calls );
	}

	public function test_call_does_not_locally_gate_on_peer_allowed_operations(): void {
		// PeerRecord::allowed_operations() on this side is validated only
		// against ContractOperations::ADAPTER_TO_SUPPORT_CHAT (the disjoint,
		// opposite-direction list) and can never legitimately contain a
		// Support-Chat-to-adapter operation name. Authorizing an outbound
		// operation is the receiver's job (ADR-0007 §4); this client must
		// still attempt (sign and send) the call even when the local peer
		// record's own allow-list happens to be unrelated/empty of it.
		$peers = $this->createMock( PeerRepository::class );
		$peers->method( 'find_by_peer_id' )->willReturn( $this->peer( 'universal-telegram', array( 'ingest_operator_reply' ) ) );

		$transport = $this->spy_transport(
			array(
				'status' => 200,
				'ok'     => true,
				'body'   => array(
					'ok'     => true,
					'reused' => false,
				),
			)
		);

		$client = new AdapterContractClient( $peers, $this->usable_signer(), $transport, $this->unavailable_audit_logger() );

		$result = $client->deliver_message( 'universal-telegram', 'case-ref-1', 'msg-uuid-1', 'hello' );

		$this->assertTrue( $result['ok'] );
		$this->assertCount( 1, $transport->calls );
	}

	public function test_call_fails_closed_when_peer_route_is_not_configured(): void {
		$peers = $this->createMock( PeerRepository::class );
		$peers->method( 'find_by_peer_id' )->willReturn( $this->peer( 'universal-telegram', array( 'deliver_message' ), PeerRecord::STATUS_ACTIVE, null ) );

		$transport = $this->spy_transport(
			array(
				'status' => 200,
				'ok'     => true,
				'body'   => array( 'ok' => true ),
			)
		);

		$client = new AdapterContractClient( $peers, $this->usable_signer(), $transport, $this->unavailable_audit_logger() );

		$result = $client->deliver_message( 'universal-telegram', 'case-ref-1', 'msg-uuid-1', 'hello' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( AdapterContractClient::REASON_ROUTE_UNCONFIGURED, $result['reason'] );
		$this->assertCount( 0, $transport->calls );
	}

	public function test_call_fails_closed_when_signing_key_unavailable(): void {
		$peers = $this->createMock( PeerRepository::class );
		$peers->method( 'find_by_peer_id' )->willReturn( $this->peer( 'universal-telegram', array( 'deliver_message' ) ) );

		$transport = $this->spy_transport(
			array(
				'status' => 200,
				'ok'     => true,
				'body'   => array( 'ok' => true ),
			)
		);

		$client = new AdapterContractClient( $peers, $this->unsigned_signer(), $transport, $this->unavailable_audit_logger() );

		$result = $client->deliver_message( 'universal-telegram', 'case-ref-1', 'msg-uuid-1', 'hello' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( AdapterContractClient::REASON_SIGNING_UNAVAILABLE, $result['reason'] );
		$this->assertCount( 0, $transport->calls );
	}

	public function test_invalid_input_never_reaches_peer_lookup(): void {
		$peers = $this->createMock( PeerRepository::class );
		$peers->expects( $this->never() )->method( 'find_by_peer_id' );

		$transport = $this->spy_transport(
			array(
				'status' => 200,
				'ok'     => true,
				'body'   => array( 'ok' => true ),
			)
		);

		$client = new AdapterContractClient( $peers, $this->usable_signer(), $transport, $this->unavailable_audit_logger() );

		$result = $client->ensure_channel_case( 'universal-telegram', '', 'escalated' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( AdapterContractClient::REASON_INVALID_INPUT, $result['reason'] );
		$this->assertCount( 0, $transport->calls );
	}

	public function test_ensure_channel_case_success_builds_expected_route_and_body(): void {
		$peers = $this->createMock( PeerRepository::class );
		$peers->method( 'find_by_peer_id' )->willReturn( $this->peer() );
		$peers->expects( $this->once() )->method( 'touch_last_used' )->with( 'universal-telegram' );

		$transport = $this->spy_transport(
			array(
				'status' => 200,
				'ok'     => true,
				'body'   => array(
					'ok'               => true,
					'channel_case_ref' => 'case-ref-xyz',
					'status'           => 'created',
				),
			)
		);

		$client = new AdapterContractClient( $peers, $this->usable_signer(), $transport, $this->unavailable_audit_logger() );

		$result = $client->ensure_channel_case( 'universal-telegram', 'conv-uuid-1', 'escalated', array( 'title' => 'Help' ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'case-ref-xyz', $result['channel_case_ref'] );
		$this->assertSame( 'created', $result['case_status'] );

		$this->assertCount( 1, $transport->calls );
		$call = $transport->calls[0];
		$this->assertSame( 'POST', $call['method'] );
		$this->assertSame( '/universal-telegram/v1/support-chat/ensure_channel_case', $call['route'] );
		$this->assertSame( 'universal-telegram', $call['headers']['X-SC-Audience'] );

		$body = json_decode( $call['raw_body'], true );
		$this->assertSame( 'conv-uuid-1', $body['conversation_uuid'] );
		$this->assertSame( 'escalated', $body['reason_code'] );
		$this->assertSame( array( 'title' => 'Help' ), $body['summary'] );
		// Idempotent on conversation identity: a second call must derive
		// the exact same idempotency key (ADR-0005 §6).
		$this->assertSame(
			\UniversalSupportChat\ChannelContract\Outbound\IdempotencyKeys::for_ensure_channel_case( 'conv-uuid-1' ),
			$body['idempotency_key']
		);
	}

	public function test_deliver_message_derives_idempotency_key_from_message_uuid(): void {
		$peers = $this->createMock( PeerRepository::class );
		$peers->method( 'find_by_peer_id' )->willReturn( $this->peer() );

		$transport = $this->spy_transport(
			array(
				'status' => 200,
				'ok'     => true,
				'body'   => array(
					'ok'     => true,
					'reused' => false,
				),
			)
		);

		$client = new AdapterContractClient( $peers, $this->usable_signer(), $transport, $this->unavailable_audit_logger() );

		$result = $client->deliver_message( 'universal-telegram', 'case-ref-1', 'msg-uuid-42', 'Hello visitor', 'Hub' );

		$this->assertTrue( $result['ok'] );
		$this->assertFalse( $result['reused'] );

		$body = json_decode( $transport->calls[0]['raw_body'], true );
		$this->assertSame(
			\UniversalSupportChat\ChannelContract\Outbound\IdempotencyKeys::for_message_delivery( 'msg-uuid-42' ),
			$body['idempotency_key']
		);
		$this->assertSame( 'Hello visitor', $body['body'] );
		$this->assertSame( 'Hub', $body['attribution'] );
	}

	public function test_backfill_derives_per_message_idempotency_keys_and_skips_invalid_entries(): void {
		$peers = $this->createMock( PeerRepository::class );
		$peers->method( 'find_by_peer_id' )->willReturn( $this->peer() );

		$transport = $this->spy_transport(
			array(
				'status' => 200,
				'ok'     => true,
				'body'   => array(
					'ok'       => true,
					'accepted' => 2,
					'failed'   => 0,
				),
			)
		);

		$client = new AdapterContractClient( $peers, $this->usable_signer(), $transport, $this->unavailable_audit_logger() );

		$result = $client->deliver_transcript_backfill(
			'universal-telegram',
			'case-ref-1',
			array(
				array(
					'message_uuid' => 'm1',
					'body'         => 'first',
				),
				array(
					'message_uuid' => 'm2',
					'body'         => 'second',
					'attribution'  => 'Visitor',
				),
				array(
					'message_uuid' => '',
					'body'         => 'skip me: no uuid',
				),
				array(
					'message_uuid' => 'm3',
					'body'         => '',
				),
			)
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 2, $result['accepted'] );
		$this->assertSame( 0, $result['failed'] );

		$body = json_decode( $transport->calls[0]['raw_body'], true );
		$this->assertCount( 2, $body['messages'] );
		$this->assertSame(
			\UniversalSupportChat\ChannelContract\Outbound\IdempotencyKeys::for_message_delivery( 'm1' ),
			$body['messages'][0]['idempotency_key']
		);
		$this->assertSame(
			\UniversalSupportChat\ChannelContract\Outbound\IdempotencyKeys::for_message_delivery( 'm2' ),
			$body['messages'][1]['idempotency_key']
		);
	}

	public function test_transport_failure_reason_is_propagated_and_last_used_is_not_touched(): void {
		$peers = $this->createMock( PeerRepository::class );
		$peers->method( 'find_by_peer_id' )->willReturn( $this->peer() );
		$peers->expects( $this->never() )->method( 'touch_last_used' );

		$transport = $this->spy_transport(
			array(
				'status' => 503,
				'ok'     => false,
				'body'   => array(
					'ok'     => false,
					'reason' => 'binding_unavailable',
				),
			)
		);

		$client = new AdapterContractClient( $peers, $this->usable_signer(), $transport, $this->unavailable_audit_logger() );

		$result = $client->deliver_message( 'universal-telegram', 'case-ref-1', 'msg-uuid-1', 'hello' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'binding_unavailable', $result['reason'] );
		$this->assertSame( 503, $result['status'] );
	}

	public function test_notify_operators_reports_reused_flag(): void {
		$peers = $this->createMock( PeerRepository::class );
		$peers->method( 'find_by_peer_id' )->willReturn( $this->peer() );

		$transport = $this->spy_transport(
			array(
				'status' => 200,
				'ok'     => true,
				'body'   => array(
					'ok'     => true,
					'reused' => true,
				),
			)
		);

		$client = new AdapterContractClient( $peers, $this->usable_signer(), $transport, $this->unavailable_audit_logger() );

		$result = $client->notify_operators( 'universal-telegram', 'case-ref-1', 'attention', 'New escalation' );

		$this->assertTrue( $result['ok'] );
		$this->assertTrue( $result['reused'] );
	}
}
