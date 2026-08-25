<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\ChannelContract\Rest;

use UniversalSupportChat\Audit\AuditLogger;
use UniversalSupportChat\ChannelContract\Auth\ContractIdentity;
use UniversalSupportChat\ChannelContract\Auth\ContractOperations;
use UniversalSupportChat\ChannelContract\Auth\KeyId;
use UniversalSupportChat\ChannelContract\Auth\NonceReplayRepository;
use UniversalSupportChat\ChannelContract\Auth\PairingService;
use UniversalSupportChat\ChannelContract\Auth\PeerRepository;
use UniversalSupportChat\ChannelContract\Auth\SignatureVerifier;
use UniversalSupportChat\ChannelContract\ChannelStatusRepository;
use UniversalSupportChat\ChannelContract\ContractDiscovery;
use UniversalSupportChat\ChannelContract\Rest\ContractOperationDispatcher;
use UniversalSupportChat\ChannelContract\Rest\ContractOperationsController;
use UniversalSupportChat\Conversations\ConversationRepository;
use UniversalSupportChat\Conversations\ConversationStatus;
use UniversalSupportChat\Conversations\MessageRepository;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalSupportChat\Privacy\Redactor;
use WP_REST_Request;
use WP_UnitTestCase;

final class ContractOperationsControllerTest extends WP_UnitTestCase {

	private const ROUTE = '/universal-support-chat/v1/contract/';

	private ContractOperationsController $controller;
	private ConversationRepository $conversations;
	private MessageRepository $messages;
	private string $peer_id = 'universal-telegram';
	private string $peer_secret;
	private string $peer_key_id;

	public function set_up(): void {
		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		$health              = new SchemaHealth();
		$vault               = new CredentialVault();
		$this->conversations = new ConversationRepository( $health );
		$this->messages      = new MessageRepository( $health, $vault );
		$channel_status      = new ChannelStatusRepository( $health );
		$audit               = new AuditLogger( $health, new Redactor() );

		$peers  = new PeerRepository( $health );
		$nonces = new NonceReplayRepository( $health );

		$pair              = sodium_crypto_sign_keypair();
		$public_raw        = sodium_crypto_sign_publickey( $pair );
		$this->peer_secret = sodium_crypto_sign_secretkey( $pair );
		$this->peer_key_id = KeyId::compute( $this->peer_id, $public_raw );

		$pairing = new PairingService( $peers, $audit );
		$result  = $pairing->pair(
			$this->peer_id,
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture encoding.
			base64_encode( $public_raw ),
			$this->peer_key_id,
			ContractOperations::ADAPTER_TO_SUPPORT_CHAT,
			'universal_telegram_manage',
			false,
			1
		);
		$this->assertTrue( $result->ok() );

		$verifier         = new SignatureVerifier( $peers, $nonces );
		$dispatcher       = new ContractOperationDispatcher( $this->conversations, $this->messages, $channel_status, $audit );
		$this->controller = new ContractOperationsController( $verifier, $dispatcher );
	}

	/**
	 * Builds a fully signed WP_REST_Request per ADR-0007 §3.
	 *
	 * @param string                $operation Operation name.
	 * @param array<string, mixed>  $body      Body payload.
	 * @param array<string, string> $overrides Header overrides for negative tests.
	 * @param bool                  $tamper_body_after_hash Append a byte to the body after hashing/signing.
	 */
	private function build_signed_request( string $operation, array $body, array $overrides = array(), bool $tamper_body_after_hash = false ): WP_REST_Request {
		$path      = self::ROUTE . $operation;
		$raw_body  = (string) wp_json_encode( $body );
		$body_hash = hash( 'sha256', $raw_body );
		$timestamp = (string) time();
		$nonce     = $this->random_nonce();

		$headers = array(
			'contract_version' => ContractDiscovery::CONTRACT_VERSION_ID,
			'auth_profile'     => ContractIdentity::AUTH_PROFILE_ID,
			'sender'           => $this->peer_id,
			'audience'         => ContractIdentity::SELF_ID,
			'key_id'           => $this->peer_key_id,
			'timestamp'        => $timestamp,
			'nonce'            => $nonce,
			'body_sha256'      => $body_hash,
		);
		$headers = array_merge( $headers, $overrides );

		$canonical = implode(
			"\n",
			array(
				$headers['auth_profile'],
				$headers['contract_version'],
				$headers['sender'],
				$headers['audience'],
				$headers['key_id'],
				$headers['timestamp'],
				$headers['nonce'],
				'POST',
				$path,
				$headers['body_sha256'],
			)
		);

		$signature = sodium_crypto_sign_detached( $canonical, $this->peer_secret );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding.
		$signature_b64 = base64_encode( $signature );
		if ( isset( $overrides['signature'] ) ) {
			$signature_b64 = $overrides['signature'];
		}

		$request = new WP_REST_Request( 'POST', $path );
		$request->set_url_params( array( 'operation' => $operation ) );
		$request->set_header( 'X-SC-Contract-Version', $headers['contract_version'] );
		$request->set_header( 'X-SC-Auth-Profile', $headers['auth_profile'] );
		$request->set_header( 'X-SC-Sender', $headers['sender'] );
		$request->set_header( 'X-SC-Audience', $headers['audience'] );
		$request->set_header( 'X-SC-Key-Id', $headers['key_id'] );
		$request->set_header( 'X-SC-Timestamp', $headers['timestamp'] );
		$request->set_header( 'X-SC-Nonce', $headers['nonce'] );
		$request->set_header( 'X-SC-Body-Sha256', $headers['body_sha256'] );
		$request->set_header( 'X-SC-Signature', $signature_b64 );

		if ( $tamper_body_after_hash ) {
			$raw_body .= ' ';
		}

		$request->set_body( $raw_body );

		return $request;
	}

	private function random_nonce(): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding: 16 raw bytes -> 22-char unpadded base64url.
		return rtrim( strtr( base64_encode( random_bytes( 16 ) ), '+/', '-_' ), '=' );
	}

	public function test_valid_signed_claim_is_accepted_and_reaches_domain_service(): void {
		$visitor      = self::factory()->user->create();
		$conversation = $this->conversations->create( $visitor );
		$operator     = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$request  = $this->build_signed_request(
			'claim',
			array(
				'channel_case_ref' => $conversation->uuid(),
				'operator_user_id' => $operator,
				'idempotency_key'  => wp_generate_uuid4(),
			)
		);
		$response = $this->controller->handle( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['ok'] );

		$fresh = $this->conversations->find_by_uuid( $conversation->uuid() );
		$this->assertSame( $operator, $fresh->assigned_operator_id() );
	}

	public function test_ingest_operator_reply_appears_in_transcript(): void {
		$visitor      = self::factory()->user->create();
		$conversation = $this->conversations->create( $visitor );

		$request  = $this->build_signed_request(
			'ingest_operator_reply',
			array(
				'channel_case_ref' => $conversation->uuid(),
				'body'             => 'Hello from Telegram',
				'idempotency_key'  => wp_generate_uuid4(),
				'operator_user_id' => 1,
			)
		);
		$response = $this->controller->handle( $request );

		$this->assertSame( 200, $response->get_status() );

		$messages = $this->messages->list_for_conversation( $conversation->id() );
		$this->assertCount( 1, $messages );
		$this->assertSame( 'Hello from Telegram', $messages[0]->plaintext_body() );
		$this->assertSame( 'operator', $messages[0]->direction() );
	}

	public function test_duplicate_ingest_with_same_idempotency_key_does_not_duplicate_message(): void {
		$visitor      = self::factory()->user->create();
		$conversation = $this->conversations->create( $visitor );
		$key          = wp_generate_uuid4();

		$body = array(
			'channel_case_ref' => $conversation->uuid(),
			'body'             => 'Hello twice',
			'idempotency_key'  => $key,
			'operator_user_id' => 1,
		);

		$first  = $this->controller->handle( $this->build_signed_request( 'ingest_operator_reply', $body ) );
		$second = $this->controller->handle( $this->build_signed_request( 'ingest_operator_reply', $body ) );

		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 200, $second->get_status() );
		$this->assertCount( 1, $this->messages->list_for_conversation( $conversation->id() ) );
	}

	public function test_resolve_then_reopen_round_trip(): void {
		$visitor      = self::factory()->user->create();
		$conversation = $this->conversations->create( $visitor );
		$this->conversations->transition( $conversation, ConversationStatus::OPEN );

		$resolve = $this->controller->handle( $this->build_signed_request( 'resolve', array( 'channel_case_ref' => $conversation->uuid() ) ) );
		$this->assertSame( 200, $resolve->get_status() );
		$this->assertSame( ConversationStatus::RESOLVED, $this->conversations->find_by_uuid( $conversation->uuid() )->status() );

		$reopen = $this->controller->handle( $this->build_signed_request( 'reopen', array( 'channel_case_ref' => $conversation->uuid() ) ) );
		$this->assertSame( 200, $reopen->get_status() );
		$this->assertSame( ConversationStatus::OPEN, $this->conversations->find_by_uuid( $conversation->uuid() )->status() );
	}

	public function test_duplicate_resolve_is_a_safe_no_op(): void {
		$visitor      = self::factory()->user->create();
		$conversation = $this->conversations->create( $visitor );
		$this->conversations->transition( $conversation, ConversationStatus::OPEN );

		$this->controller->handle( $this->build_signed_request( 'resolve', array( 'channel_case_ref' => $conversation->uuid() ) ) );
		$second = $this->controller->handle( $this->build_signed_request( 'resolve', array( 'channel_case_ref' => $conversation->uuid() ) ) );

		$this->assertSame( 200, $second->get_status() );
		$this->assertSame( ConversationStatus::RESOLVED, $this->conversations->find_by_uuid( $conversation->uuid() )->status() );
	}

	public function test_report_channel_unavailable_marks_channel_status(): void {
		$visitor        = self::factory()->user->create();
		$conversation   = $this->conversations->create( $visitor );
		$channel_status = new ChannelStatusRepository( new SchemaHealth() );

		$response = $this->controller->handle(
			$this->build_signed_request(
				'report_channel_unavailable',
				array(
					'channel_case_ref' => $conversation->uuid(),
					'reason_code'      => 'topic_deleted',
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$status = $channel_status->status_for( $conversation->id() );
		$this->assertSame( 'degraded', $status['status'] );
		$this->assertSame( 'topic_deleted', $status['reason_code'] );
	}

	public function test_report_delivery_failure_marks_channel_status_degraded(): void {
		$visitor        = self::factory()->user->create();
		$conversation   = $this->conversations->create( $visitor );
		$channel_status = new ChannelStatusRepository( new SchemaHealth() );

		$response = $this->controller->handle(
			$this->build_signed_request(
				'report_delivery_failure',
				array(
					'channel_case_ref' => $conversation->uuid(),
					'idempotency_key'  => wp_generate_uuid4(),
					'reason_code'      => 'send_timeout',
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$status = $channel_status->status_for( $conversation->id() );
		$this->assertSame( 'degraded', $status['status'] );
		$this->assertSame( 'send_timeout', $status['reason_code'] );
	}

	public function test_report_delivery_failure_is_safe_to_repeat(): void {
		global $wpdb;

		$visitor      = self::factory()->user->create();
		$conversation = $this->conversations->create( $visitor );

		$body = array(
			'channel_case_ref' => $conversation->uuid(),
			'reason_code'      => 'send_timeout',
		);

		$first  = $this->controller->handle( $this->build_signed_request( 'report_delivery_failure', $body ) );
		$second = $this->controller->handle( $this->build_signed_request( 'report_delivery_failure', $body ) );

		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 200, $second->get_status() );

		$table = $wpdb->prefix . Migrator::CHANNEL_STATUS_TABLE;
		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE conversation_id = %d", $conversation->id() ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$this->assertSame( 1, $count );
	}

	public function test_update_operator_presence_is_denied_as_unsupported(): void {
		$conversation = $this->conversations->create( self::factory()->user->create() );
		$request      = $this->build_signed_request(
			'update_operator_presence',
			array(
				'channel_case_ref' => $conversation->uuid(),
				'operator_user_id' => 1,
				'presence_state'   => 'online',
			)
		);

		$response = $this->controller->handle( $request );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'contract_auth_failed',
			),
			$response->get_data()
		);
	}

	public function test_hub_and_widget_conversation_lifecycle_unaffected_by_no_paired_peer(): void {
		$visitor      = self::factory()->user->create();
		$conversation = $this->conversations->create( $visitor );
		$opened       = $this->conversations->transition( $conversation, ConversationStatus::OPEN );

		$this->assertNotNull( $opened );
		$this->assertSame( ConversationStatus::OPEN, $opened->status() );
	}

	// ---- Uniform denial matrix: every case below must produce the same
	// ---- 401 { ok: false, reason: contract_auth_failed } and no mutation.

	private function assert_uniform_denial_no_mutation( WP_REST_Request $request, string $conversation_uuid ): void {
		$before   = $this->conversations->find_by_uuid( $conversation_uuid );
		$response = $this->controller->handle( $request );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'contract_auth_failed',
			),
			$response->get_data()
		);

		$after = $this->conversations->find_by_uuid( $conversation_uuid );
		$this->assertNull( $before->assigned_operator_id() );
		$this->assertNull( $after->assigned_operator_id() );
	}

	private function claim_body_for( string $uuid ): array {
		return array(
			'channel_case_ref' => $uuid,
			'operator_user_id' => 1,
			'idempotency_key'  => wp_generate_uuid4(),
		);
	}

	public function test_missing_signature_header_is_denied(): void {
		$conversation = $this->conversations->create( self::factory()->user->create() );
		$request      = $this->build_signed_request( 'claim', $this->claim_body_for( $conversation->uuid() ), array( 'signature' => '' ) );
		$this->assert_uniform_denial_no_mutation( $request, $conversation->uuid() );
	}

	public function test_wrong_sender_is_denied(): void {
		$conversation = $this->conversations->create( self::factory()->user->create() );
		$request      = $this->build_signed_request( 'claim', $this->claim_body_for( $conversation->uuid() ), array( 'sender' => 'unknown-adapter' ) );
		$this->assert_uniform_denial_no_mutation( $request, $conversation->uuid() );
	}

	public function test_wrong_audience_is_denied(): void {
		$conversation = $this->conversations->create( self::factory()->user->create() );
		$request      = $this->build_signed_request( 'claim', $this->claim_body_for( $conversation->uuid() ), array( 'audience' => 'someone-else' ) );
		$this->assert_uniform_denial_no_mutation( $request, $conversation->uuid() );
	}

	public function test_unknown_key_id_is_denied(): void {
		$conversation = $this->conversations->create( self::factory()->user->create() );
		$request      = $this->build_signed_request( 'claim', $this->claim_body_for( $conversation->uuid() ), array( 'key_id' => 'universal-telegram.ffffffffffffffff' ) );
		$this->assert_uniform_denial_no_mutation( $request, $conversation->uuid() );
	}

	public function test_invalid_signature_is_denied(): void {
		$conversation = $this->conversations->create( self::factory()->user->create() );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- deliberately wrong signature bytes.
		$bad_signature = base64_encode( str_repeat( "\x00", 64 ) );
		$request       = $this->build_signed_request( 'claim', $this->claim_body_for( $conversation->uuid() ), array( 'signature' => $bad_signature ) );
		$this->assert_uniform_denial_no_mutation( $request, $conversation->uuid() );
	}

	public function test_body_tampered_after_hashing_is_denied(): void {
		$conversation = $this->conversations->create( self::factory()->user->create() );
		$request      = $this->build_signed_request( 'claim', $this->claim_body_for( $conversation->uuid() ), array(), true );
		$this->assert_uniform_denial_no_mutation( $request, $conversation->uuid() );
	}

	public function test_stale_timestamp_is_denied(): void {
		$conversation = $this->conversations->create( self::factory()->user->create() );
		$request      = $this->build_signed_request( 'claim', $this->claim_body_for( $conversation->uuid() ), array( 'timestamp' => (string) ( time() - 3600 ) ) );
		$this->assert_uniform_denial_no_mutation( $request, $conversation->uuid() );
	}

	public function test_query_string_present_is_denied(): void {
		$conversation = $this->conversations->create( self::factory()->user->create() );
		$request      = $this->build_signed_request( 'claim', $this->claim_body_for( $conversation->uuid() ) );
		$request->set_query_params( array( 'unexpected' => '1' ) );

		$before   = $this->conversations->find_by_uuid( $conversation->uuid() );
		$response = $this->controller->handle( $request );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'contract_auth_failed',
			),
			$response->get_data()
		);
		$this->assertNull( $before->assigned_operator_id() );
	}

	public function test_nonce_replay_is_denied(): void {
		$conversation = $this->conversations->create( self::factory()->user->create() );
		$body         = $this->claim_body_for( $conversation->uuid() );

		// Build once, replay the identical signed request twice.
		$nonce_override = array( 'nonce' => 'AAAAAAAAAAAAAAAAAAAAAA' );
		$first_request  = $this->build_signed_request( 'claim', $body, $nonce_override );
		$first_response = $this->controller->handle( $first_request );
		$this->assertSame( 200, $first_response->get_status() );

		$second_body     = $this->claim_body_for( $conversation->uuid() );
		$second_request  = $this->build_signed_request( 'claim', $second_body, $nonce_override );
		$second_response = $this->controller->handle( $second_request );

		$this->assertSame( 401, $second_response->get_status() );
		$this->assertSame(
			array(
				'ok'     => false,
				'reason' => 'contract_auth_failed',
			),
			$second_response->get_data()
		);
	}

	public function test_operation_not_on_peer_allow_list_is_denied(): void {
		global $wpdb;
		$table = $wpdb->prefix . Migrator::CHANNEL_PEERS_TABLE;
		$wpdb->update(
			$table,
			array( 'allowed_operations' => wp_json_encode( array( 'ingest_operator_reply' ) ) ),
			array( 'peer_id' => $this->peer_id ),
			array( '%s' ),
			array( '%s' )
		);

		$conversation = $this->conversations->create( self::factory()->user->create() );
		$request      = $this->build_signed_request( 'claim', $this->claim_body_for( $conversation->uuid() ) );
		$this->assert_uniform_denial_no_mutation( $request, $conversation->uuid() );
	}

	public function test_revoked_key_is_denied(): void {
		global $wpdb;
		$table = $wpdb->prefix . Migrator::CHANNEL_PEERS_TABLE;
		$wpdb->update(
			$table,
			array( 'status' => 'revoked' ),
			array( 'peer_id' => $this->peer_id ),
			array( '%s' ),
			array( '%s' )
		);

		$conversation = $this->conversations->create( self::factory()->user->create() );
		$request      = $this->build_signed_request( 'claim', $this->claim_body_for( $conversation->uuid() ) );
		$this->assert_uniform_denial_no_mutation( $request, $conversation->uuid() );
	}

	public function test_expired_key_is_denied(): void {
		global $wpdb;
		$table = $wpdb->prefix . Migrator::CHANNEL_PEERS_TABLE;
		$wpdb->update(
			$table,
			array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ),
			array( 'peer_id' => $this->peer_id ),
			array( '%s' ),
			array( '%s' )
		);

		$conversation = $this->conversations->create( self::factory()->user->create() );
		$request      = $this->build_signed_request( 'claim', $this->claim_body_for( $conversation->uuid() ) );
		$this->assert_uniform_denial_no_mutation( $request, $conversation->uuid() );
	}
}
