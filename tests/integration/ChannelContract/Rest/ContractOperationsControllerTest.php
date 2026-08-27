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
use UniversalSupportChat\ChannelContract\HandoffMapRepository;
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
	private HandoffMapRepository $handoff_map;
	private string $peer_id = 'universal-telegram';
	private string $peer_secret;
	private string $peer_key_id;

	public function set_up(): void {
		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		$this->clean_tables_committed_by_real_transactions();

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

		$verifier          = new SignatureVerifier( $peers, $nonces );
		$this->handoff_map = new HandoffMapRepository( $health );
		$dispatcher        = new ContractOperationDispatcher( $this->conversations, $this->messages, $channel_status, $audit, $this->handoff_map );
		$this->controller  = new ContractOperationsController( $verifier, $dispatcher );
	}

	/**
	 * ADR-0010 §4's `dispatch_with_provenance()` performs a real
	 * `START TRANSACTION`/`COMMIT` for every provenance-carrying call this
	 * class's own new tests exercise — the identical class of hazard
	 * `QuiescenceProviderIntegrationTest::tear_down()` already documents
	 * for Universal Telegram's own quiescence lock: a real COMMIT collapses
	 * `WP_UnitTestCase`'s savepoint-based per-test isolation for every test
	 * that runs afterward in the same PHPUnit process, letting this class's
	 * own fixed `peer_id` ('universal-telegram') pairing — and any
	 * conversation/message/handoff-map row a provenance test wrote — leak
	 * past rollback into real, still-committed state that a later test's
	 * own `setUp()` would otherwise collide with. Explicit cleanup here,
	 * not reliance on the framework's own rollback, is the actual fix.
	 */
	public function tear_down(): void {
		$this->clean_tables_committed_by_real_transactions();
		parent::tear_down();
	}

	/**
	 * Called from both `set_up()` (before this test's own fixtures) and
	 * `tear_down()` (after). Cleaning only in `tear_down()` is not
	 * sufficient: once a real COMMIT has broken `WP_UnitTestCase`'s
	 * savepoint chain (see this class's own `tear_down()` docblock — now
	 * folded into this shared helper's own reasoning), the framework's own
	 * `parent::tear_down()` rollback-to-savepoint call can itself undo
	 * whatever this class's own explicit cleanup just committed, if that
	 * cleanup is not itself a real, standalone commit. Running the
	 * identical cleanup again at the *start* of the next test's `set_up()`
	 * — after the framework's own (possibly-inert) rollback has already
	 * happened — is what actually guarantees a clean slate, mirroring
	 * `QuiescenceProviderIntegrationTest`'s own established two-call
	 * pattern.
	 */
	private function clean_tables_committed_by_real_transactions(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::CHANNEL_PEERS_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::CONTRACT_NONCES_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::CONVERSATIONS_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::CONVERSATION_MESSAGES_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::CHANNEL_STATUS_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::LEGACY_HANDOFF_MAP_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// A real COMMIT already broke the ambient savepoint chain (once any
		// test in this class reaches `dispatch_with_provenance()`'s own
		// transaction), meaning autocommit is effectively off and every
		// statement since — including these DELETEs — is pending inside
		// whatever transaction is implicitly still open. An explicit COMMIT
		// here guarantees this cleanup is durable regardless of that
		// ambient state, so a later, unrelated test class (e.g.
		// `VisitorRestTest`) can never observe a peer/conversation/message
		// row this class itself created.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'COMMIT' );
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

	/**
	 * ADR-0010 §4: a genuine cutover-replay retry (identical
	 * source_bot_id/source_update_id, identical kind/channel_case_ref)
	 * converges — exactly one message, exactly one handoff-map row, both
	 * calls return 200.
	 */
	public function test_ingest_operator_reply_with_provenance_retry_converges_to_one_message_and_one_map_row(): void {
		$visitor      = self::factory()->user->create();
		$conversation = $this->conversations->create( $visitor );

		$body = array(
			'channel_case_ref' => $conversation->uuid(),
			'body'             => 'Handed off from a deferred Telegram update',
			'idempotency_key'  => 'tg-update-501-9001',
			'operator_user_id' => 1,
			'source_bot_id'    => 501,
			'source_update_id' => 9001,
		);

		$first  = $this->controller->handle( $this->build_signed_request( 'ingest_operator_reply', $body ) );
		$second = $this->controller->handle( $this->build_signed_request( 'ingest_operator_reply', $body ) );

		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 200, $second->get_status() );
		$this->assertCount( 1, $this->messages->list_for_conversation( $conversation->id() ) );

		$row = $this->handoff_map->find( 501, 9001 );
		$this->assertNotNull( $row );
		$this->assertSame( 'message', $row['kind'] );
		$this->assertSame( $conversation->uuid(), $row['channel_case_ref'] );
		$this->assertNotNull( $row['target_message_uuid'] );
	}

	/**
	 * ADR-0010 §4: a second call carrying the identical
	 * source_bot_id/source_update_id but a different `channel_case_ref`
	 * (a genuine provenance inconsistency, never silently accepted) is
	 * refused `409 handoff_provenance_conflict`, performs no domain write,
	 * and leaves the original map row and its original message untouched.
	 */
	public function test_ingest_operator_reply_provenance_mismatch_is_refused_409_with_no_domain_write(): void {
		$visitor       = self::factory()->user->create();
		$conversation  = $this->conversations->create( $visitor );
		$other_visitor = self::factory()->user->create();
		$other         = $this->conversations->create( $other_visitor );

		$first_body = array(
			'channel_case_ref' => $conversation->uuid(),
			'body'             => 'First, correct disposition',
			'idempotency_key'  => 'tg-update-502-9002',
			'operator_user_id' => 1,
			'source_bot_id'    => 502,
			'source_update_id' => 9002,
		);
		$first      = $this->controller->handle( $this->build_signed_request( 'ingest_operator_reply', $first_body ) );
		$this->assertSame( 200, $first->get_status() );

		$mismatched_body                     = $first_body;
		$mismatched_body['channel_case_ref'] = $other->uuid();
		$mismatched_body['idempotency_key']  = 'tg-update-502-9002-b';

		$second = $this->controller->handle( $this->build_signed_request( 'ingest_operator_reply', $mismatched_body ) );

		$this->assertSame( 409, $second->get_status() );
		$this->assertSame( 'handoff_provenance_conflict', $second->get_data()['reason'] );

		$this->assertCount( 1, $this->messages->list_for_conversation( $conversation->id() ) );
		$this->assertCount( 0, $this->messages->list_for_conversation( $other->id() ), 'The mismatched retry must never write to the second conversation.' );

		$row = $this->handoff_map->find( 502, 9002 );
		$this->assertNotNull( $row );
		$this->assertSame( $conversation->uuid(), $row['channel_case_ref'], 'The original map row must remain untouched by the refused mismatched retry.' );
	}

	/**
	 * Live traffic (no source_bot_id/source_update_id) must never write a
	 * handoff-map row — proving zero behavior change for every existing
	 * call site.
	 */
	public function test_ingest_operator_reply_without_provenance_never_writes_a_handoff_map_row(): void {
		$visitor      = self::factory()->user->create();
		$conversation = $this->conversations->create( $visitor );

		$response = $this->controller->handle(
			$this->build_signed_request(
				'ingest_operator_reply',
				array(
					'channel_case_ref' => $conversation->uuid(),
					'body'             => 'Ordinary live reply',
					'idempotency_key'  => wp_generate_uuid4(),
					'operator_user_id' => 1,
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $this->handoff_map->find( 0, 0 ) );

		global $wpdb;
		$table = $wpdb->prefix . Migrator::LEGACY_HANDOFF_MAP_TABLE;
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 0, $count );
	}

	/**
	 * `claim`/`release`/`resolve`/`reopen`'s already-in-target-state early
	 * return must still run through the transactional co-write when
	 * provenance is present — proving the wrapper covers every success
	 * path, not only the "real transition happened" branch.
	 */
	public function test_duplicate_resolve_with_provenance_still_writes_exactly_one_map_row(): void {
		$visitor      = self::factory()->user->create();
		$conversation = $this->conversations->create( $visitor );
		$this->conversations->transition( $conversation, ConversationStatus::OPEN );

		$body = array(
			'channel_case_ref' => $conversation->uuid(),
			'source_bot_id'    => 503,
			'source_update_id' => 9003,
		);

		$first  = $this->controller->handle( $this->build_signed_request( 'resolve', $body ) );
		$second = $this->controller->handle( $this->build_signed_request( 'resolve', $body ) );

		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 200, $second->get_status(), 'The already-resolved early-return branch must also succeed as a genuine retry, not a conflict.' );

		global $wpdb;
		$table = $wpdb->prefix . Migrator::LEGACY_HANDOFF_MAP_TABLE;
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE bot_id = %d AND update_id = %d", 503, 9003 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 1, $count );
	}

	/**
	 * `report_channel_unavailable` with provenance writes its own
	 * `kind = 'channel_unavailable'` map row — proving the wrapper is
	 * genuinely shared across operation types, not copy-pasted per
	 * operation with a subtly different `kind`.
	 */
	public function test_report_channel_unavailable_with_provenance_writes_channel_unavailable_kind_row(): void {
		$visitor      = self::factory()->user->create();
		$conversation = $this->conversations->create( $visitor );

		$response = $this->controller->handle(
			$this->build_signed_request(
				'report_channel_unavailable',
				array(
					'channel_case_ref' => $conversation->uuid(),
					'reason_code'      => 'telegram_topic_closed',
					'source_bot_id'    => 504,
					'source_update_id' => 9004,
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );

		$row = $this->handoff_map->find( 504, 9004 );
		$this->assertNotNull( $row );
		$this->assertSame( 'channel_unavailable', $row['kind'] );
		$this->assertNull( $row['target_message_uuid'] );
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
