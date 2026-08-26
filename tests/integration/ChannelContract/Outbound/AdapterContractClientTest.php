<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\ChannelContract\Outbound;

use UniversalSupportChat\Audit\AuditLogger;
use UniversalSupportChat\ChannelContract\Auth\ContractIdentity;
use UniversalSupportChat\ChannelContract\Auth\ContractOperations;
use UniversalSupportChat\ChannelContract\Auth\KeyId;
use UniversalSupportChat\ChannelContract\Auth\OwnKeyManager;
use UniversalSupportChat\ChannelContract\Auth\PairingService;
use UniversalSupportChat\ChannelContract\Auth\PeerRepository;
use UniversalSupportChat\ChannelContract\ContractDiscovery;
use UniversalSupportChat\ChannelContract\Outbound\AdapterContractClient;
use UniversalSupportChat\ChannelContract\Outbound\InProcessContractTransport;
use UniversalSupportChat\ChannelContract\Outbound\SignatureSigner;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalSupportChat\Privacy\Redactor;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * End-to-end proof that the outbound client produces a request a
 * from-scratch, independent verifier (built directly against ADR-0007 §3
 * here, not by reusing SC's own inbound SignatureVerifier — which is
 * hardcoded to the opposite direction's identity/allow-list) accepts, and
 * that every fail-closed gate genuinely prevents a request from ever being
 * built or sent.
 */
final class AdapterContractClientTest extends WP_UnitTestCase {

	private const ADAPTER_ROUTE_BASE = 'usc-outbound-test-adapter/v1/support-chat';

	private const FAKE_ADAPTER_PEER_ID = 'usc-outbound-test-adapter';

	/**
	 * The key ID matching the fixed test public key
	 * (base64_encode(str_repeat('b', 32))), computed the same way
	 * PairingService itself requires (KeyId::compute()).
	 */
	private static function fake_adapter_key_id(): string {
		return KeyId::compute( self::FAKE_ADAPTER_PEER_ID, str_repeat( 'b', 32 ) );
	}

	private OwnKeyManager $own_keys;
	private PeerRepository $peers;
	private PairingService $pairing;

	/** @var array<int, WP_REST_Request> */
	private array $received_requests = array();

	public function set_up(): void {
		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		$health         = new SchemaHealth();
		$vault          = new CredentialVault();
		$this->own_keys = new OwnKeyManager( $vault );
		$this->peers    = new PeerRepository( $health );
		$audit          = new AuditLogger( $health, new Redactor() );
		$this->pairing  = new PairingService( $this->peers, $audit );

		$this->own_keys->ensure_key_pair();
		$this->received_requests = array();

		$this->register_fake_adapter_route();
	}

	private function client(): AdapterContractClient {
		$health = new SchemaHealth();
		$audit  = new AuditLogger( $health, new Redactor() );

		return new AdapterContractClient(
			$this->peers,
			new SignatureSigner( $this->own_keys ),
			new InProcessContractTransport(),
			$audit
		);
	}

	/**
	 * Registers a minimal in-process acceptor mimicking an adapter's own
	 * Contract v1 receiver, verifying the ADR-0007 §3 signature from
	 * scratch against Support Chat's own currently published public key.
	 */
	private function register_fake_adapter_route(): void {
		// Force WordPress core's own rest_api_init dispatch (rest_get_server())
		// to run again on the next rest_do_request() call in this test, so
		// the route registered below is picked up without this test file
		// invoking that core hook itself.
		global $wp_rest_server;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- resetting WordPress core's own REST server cache for test isolation, not a plugin global.
		$wp_rest_server = null;

		add_action(
			'rest_api_init',
			function (): void {
				register_rest_route(
					'usc-outbound-test-adapter/v1',
					'/support-chat/(?P<operation>[a-z_]+)',
					array(
						'methods'             => 'POST',
						'permission_callback' => '__return_true',
						'callback'            => function ( WP_REST_Request $request ) {
							$this->received_requests[] = $request;

							if ( ! $this->verify_signed_request( $request ) ) {
								return new WP_REST_Response(
									array(
										'ok'     => false,
										'reason' => 'contract_auth_failed',
									),
									401
								);
							}

							$operation = (string) $request->get_param( 'operation' );
							$body      = $request->get_json_params();
							$body      = is_array( $body ) ? $body : array();

							switch ( $operation ) {
								case 'ensure_channel_case':
									return new WP_REST_Response(
										array(
											'ok'     => true,
											'channel_case_ref' => 'fake-case-ref-1',
											'status' => 'created',
										),
										200
									);
								case 'notify_operators':
									return new WP_REST_Response(
										array(
											'ok'     => true,
											'reused' => false,
										),
										200
									);
								case 'deliver_transcript_backfill':
									return new WP_REST_Response(
										array(
											'ok'       => true,
											'accepted' => count( $body['messages'] ?? array() ),
											'failed'   => 0,
										),
										200
									);
								case 'deliver_message':
									return new WP_REST_Response(
										array(
											'ok'     => true,
											'reused' => false,
										),
										200
									);
								default:
									return new WP_REST_Response(
										array(
											'ok'     => false,
											'reason' => 'unsupported_operation',
										),
										400
									);
							}
						},
					)
				);
			}
		);
	}

	/**
	 * A from-scratch ADR-0007 §3 verification, independent of SC's own
	 * SignatureVerifier implementation (which only ever checks the
	 * opposite, adapter -> Support Chat, direction).
	 */
	private function verify_signed_request( WP_REST_Request $request ): bool {
		$own = $this->own_keys->public_key();
		if ( null === $own ) {
			return false;
		}

		$raw_body      = (string) $request->get_body();
		$body_hash     = hash( 'sha256', $raw_body );
		$sender        = (string) $request->get_header( 'X-SC-Sender' );
		$audience      = (string) $request->get_header( 'X-SC-Audience' );
		$key_id        = (string) $request->get_header( 'X-SC-Key-Id' );
		$timestamp     = (string) $request->get_header( 'X-SC-Timestamp' );
		$nonce         = (string) $request->get_header( 'X-SC-Nonce' );
		$header_hash   = (string) $request->get_header( 'X-SC-Body-Sha256' );
		$signature_b64 = (string) $request->get_header( 'X-SC-Signature' );

		if ( ContractIdentity::SELF_ID !== $sender || 'usc-outbound-test-adapter' !== $audience ) {
			return false;
		}

		if ( $key_id !== $own['key_id'] || $header_hash !== $body_hash ) {
			return false;
		}

		$canonical = implode(
			"\n",
			array(
				ContractIdentity::AUTH_PROFILE_ID,
				ContractDiscovery::CONTRACT_VERSION_ID,
				$sender,
				$audience,
				$key_id,
				$timestamp,
				$nonce,
				'POST',
				$request->get_route(),
				$body_hash,
			)
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- test fixture decoding, not obfuscation.
		$signature = base64_decode( $signature_b64, true );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- test fixture decoding, not obfuscation.
		$public_raw = base64_decode( $own['public_key'], true );

		if ( false === $signature || false === $public_raw ) {
			return false;
		}

		return sodium_crypto_sign_verify_detached( $signature, $canonical, $public_raw );
	}

	public function test_ensure_channel_case_round_trips_through_a_real_signed_request(): void {
		$this->pair();

		$result = $this->client()->ensure_channel_case( 'usc-outbound-test-adapter', 'conv-uuid-1', 'escalated' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'fake-case-ref-1', $result['channel_case_ref'] );
		$this->assertSame( 'created', $result['case_status'] );
		$this->assertCount( 1, $this->received_requests );

		$peer = $this->peers->find_by_peer_id( 'usc-outbound-test-adapter' );
		$this->assertNotNull( $peer );
		$this->assertNotNull( $peer->last_used_at() );
	}

	public function test_deliver_message_and_backfill_round_trip(): void {
		$this->pair();

		$deliver = $this->client()->deliver_message( 'usc-outbound-test-adapter', 'case-ref-1', 'msg-uuid-1', 'Hello', 'Hub' );
		$this->assertTrue( $deliver['ok'] );
		$this->assertFalse( $deliver['reused'] );

		$backfill = $this->client()->deliver_transcript_backfill(
			'usc-outbound-test-adapter',
			'case-ref-1',
			array(
				array(
					'message_uuid' => 'm1',
					'body'         => 'first',
				),
				array(
					'message_uuid' => 'm2',
					'body'         => 'second',
				),
			)
		);
		$this->assertTrue( $backfill['ok'] );
		$this->assertSame( 2, $backfill['accepted'] );
		$this->assertSame( 0, $backfill['failed'] );
	}

	public function test_does_not_locally_gate_on_peer_allowed_operations(): void {
		// PeerRecord::allowed_operations() on this side only ever stores the
		// disjoint, opposite-direction (adapter -> Support Chat) list — it
		// must never gate whether SC itself may call an outbound operation;
		// that authorization decision belongs to the receiving adapter.
		$result = $this->pairing->pair(
			'usc-outbound-test-adapter',
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture encoding.
			base64_encode( str_repeat( 'b', 32 ) ),
			self::fake_adapter_key_id(),
			array( 'ingest_operator_reply' ),
			'usc_outbound_test_adapter_manage',
			false,
			1,
			null,
			self::ADAPTER_ROUTE_BASE
		);
		$this->assertTrue( $result->ok() );

		$outcome = $this->client()->deliver_message( 'usc-outbound-test-adapter', 'case-ref-1', 'msg-uuid-1', 'Hello' );

		$this->assertTrue( $outcome['ok'] );
		$this->assertCount( 1, $this->received_requests );
	}

	public function test_fails_closed_when_peer_never_paired(): void {
		$result = $this->client()->ensure_channel_case( 'usc-outbound-test-adapter', 'conv-uuid-1', 'escalated' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( AdapterContractClient::REASON_NOT_PAIRED, $result['reason'] );
		$this->assertCount( 0, $this->received_requests );
	}

	public function test_fails_closed_when_route_base_not_configured(): void {
		$result = $this->pairing->pair(
			'usc-outbound-test-adapter',
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture encoding.
			base64_encode( str_repeat( 'b', 32 ) ),
			self::fake_adapter_key_id(),
			ContractOperations::ADAPTER_TO_SUPPORT_CHAT,
			'usc_outbound_test_adapter_manage',
			false,
			1,
			null,
			null
		);
		$this->assertTrue( $result->ok() );

		$outcome = $this->client()->ensure_channel_case( 'usc-outbound-test-adapter', 'conv-uuid-1', 'escalated' );

		$this->assertFalse( $outcome['ok'] );
		$this->assertSame( AdapterContractClient::REASON_ROUTE_UNCONFIGURED, $outcome['reason'] );
		$this->assertCount( 0, $this->received_requests );
	}

	public function test_ensure_channel_case_is_idempotent_on_conversation_identity(): void {
		$this->pair();

		$first  = $this->client()->ensure_channel_case( 'usc-outbound-test-adapter', 'conv-uuid-1', 'escalated' );
		$second = $this->client()->ensure_channel_case( 'usc-outbound-test-adapter', 'conv-uuid-1', 'escalated' );

		$this->assertTrue( $first['ok'] );
		$this->assertTrue( $second['ok'] );
		$this->assertCount( 2, $this->received_requests );

		$first_key  = json_decode( (string) $this->received_requests[0]->get_body(), true )['idempotency_key'];
		$second_key = json_decode( (string) $this->received_requests[1]->get_body(), true )['idempotency_key'];
		$this->assertSame( $first_key, $second_key );
	}

	/**
	 * Pairs the fake adapter peer with a configured outbound route base.
	 * The allow-list passed here is Support Chat's record of what the
	 * adapter may call *inbound* (ContractOperations::
	 * ADAPTER_TO_SUPPORT_CHAT) — unrelated to, and never gating, which
	 * Support-Chat-to-adapter operations this client may attempt.
	 */
	private function pair(): void {
		$result = $this->pairing->pair(
			'usc-outbound-test-adapter',
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture encoding.
			base64_encode( str_repeat( 'b', 32 ) ),
			self::fake_adapter_key_id(),
			ContractOperations::ADAPTER_TO_SUPPORT_CHAT,
			'usc_outbound_test_adapter_manage',
			false,
			1,
			null,
			self::ADAPTER_ROUTE_BASE
		);
		$this->assertTrue( $result->ok() );
	}
}
