<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\AI;

use UniversalSupportChat\AI\Knowledge\KnowledgeIndexer;
use UniversalSupportChat\AI\Knowledge\KnowledgeRetriever;
use UniversalSupportChat\AI\Knowledge\KnowledgeSourceRepository;
use UniversalSupportChat\AI\Policy\AiSystemPolicy;
use UniversalSupportChat\AI\Policy\PromptAssembler;
use UniversalSupportChat\AI\Provider\AiErrorClass;
use UniversalSupportChat\AI\Provider\AiResult;
use UniversalSupportChat\AI\Provider\FakeProvider;
use UniversalSupportChat\AI\Provider\ProviderKeyManager;
use UniversalSupportChat\AI\Turn\AiResponder;
use UniversalSupportChat\AI\Turn\AiTurnRateLimiter;
use UniversalSupportChat\AI\Turn\AiTurnRepository;
use UniversalSupportChat\AI\Turn\AiTurnWorker;
use UniversalSupportChat\AI\Turn\HandoffReason;
use UniversalSupportChat\AI\Turn\SafetyClassifier;
use UniversalSupportChat\Conversations\ConversationMessage;
use UniversalSupportChat\Conversations\ConversationRepository;
use UniversalSupportChat\Conversations\ConversationStatus;
use UniversalSupportChat\Conversations\MessageRepository;
use UniversalSupportChat\Conversations\Rest\ConversationsController;
use UniversalSupportChat\Core\Configuration\Settings;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use WP_REST_Request;
use UniversalSupportChat\Tests\Integration\AI\Support\TruncatesAiTables;
use WP_UnitTestCase;

/**
 * SC-M07 WP6 — the AI turn queue + async worker + escalation state machine.
 */
final class AiTurnFlowTest extends WP_UnitTestCase {

	use TruncatesAiTables;

	private SchemaHealth $health;
	private ConversationRepository $conversations;
	private MessageRepository $messages;
	private AiTurnRepository $turns;
	private KnowledgeSourceRepository $knowledge;
	private ProviderKeyManager $keys;
	private FakeProvider $provider;

	public function set_up(): void {
		parent::set_up();
		$this->truncate_ai_tables();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		$this->health        = new SchemaHealth();
		$this->conversations = new ConversationRepository( $this->health );
		$vault               = new CredentialVault();
		$this->messages      = new MessageRepository( $this->health, $vault );
		$this->turns         = new AiTurnRepository();
		$this->knowledge     = new KnowledgeSourceRepository( $vault );
		$this->keys          = new ProviderKeyManager( $vault );
		$this->keys->set( 'sk-test' );
		$this->provider = new FakeProvider();

		update_option(
			Settings::OPTION_NAME,
			array(
				'ai_enabled'                   => true,
				'ai_max_retries'               => 3,
				'ai_per_conversation_turn_cap' => 10,
				'ai_daily_request_cap'         => 500,
			)
		);
	}

	public function tear_down(): void {
		delete_option( Settings::OPTION_NAME );
		$this->keys->clear();
		unset( $_POST, $_SERVER['HTTP_X_WP_NONCE'] );
		parent::tear_down();
	}

	private function responder(): AiResponder {
		return new AiResponder(
			new Settings(),
			$this->keys,
			$this->turns,
			new AiTurnRateLimiter( $this->turns ),
			null
		);
	}

	private function worker(): AiTurnWorker {
		return new AiTurnWorker(
			new Settings(),
			$this->conversations,
			$this->messages,
			$this->turns,
			new KnowledgeRetriever( $this->knowledge ),
			new AiSystemPolicy(),
			new PromptAssembler(),
			$this->provider,
			new SafetyClassifier(),
			new AiTurnRateLimiter( $this->turns ),
			null,
			null
		);
	}

	private function post_visitor_message( int $owner, string $uuid, string $text ): array {
		wp_set_current_user( $owner );
		$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );

		$controller = new ConversationsController(
			$this->health,
			$this->conversations,
			$this->messages,
			null,
			null,
			$this->turns,
			$this->responder()
		);

		$request = new WP_REST_Request( 'POST', '/universal-support-chat/v1/conversations/' . $uuid . '/messages' );
		$request->set_param( 'conversation_uuid', $uuid );
		$request->set_param( 'text', $text );

		return $controller->handle_post_message( $request )->get_data();
	}

	private function open_conversation(): array {
		$owner = self::factory()->user->create();
		$conv  = $this->conversations->create( $owner );
		$this->conversations->transition( $conv, ConversationStatus::OPEN );

		return array( $owner, $conv );
	}

	private function ai_messages( int $conversation_id ): array {
		return array_values(
			array_filter(
				$this->messages->list_for_conversation( $conversation_id, 0, 100 ),
				static fn ( ConversationMessage $m ): bool => ConversationMessage::DIRECTION_AI === $m->direction()
			)
		);
	}

	public function test_visitor_message_queues_a_turn_and_the_worker_posts_an_ai_answer(): void {
		$this->provider->push( AiResult::answer( 'Yes, we ship to Norway.' ) );
		[ $owner, $conv ] = $this->open_conversation();

		$data = $this->post_visitor_message( $owner, $conv->uuid(), 'Do you ship to Norway?' );
		$this->assertTrue( $data['ok'] );
		$this->assertTrue( $this->turns->has_pending_turn( $conv->id() ) );
		$this->assertSame( 0, $this->provider->call_count(), 'the provider is never called in the visitor request' );

		$this->worker()->process_due( 10 );

		$ai = $this->ai_messages( $conv->id() );
		$this->assertCount( 1, $ai );
		$this->assertSame( 'Yes, we ship to Norway.', $ai[0]->plaintext_body() );
		$this->assertFalse( $this->turns->has_pending_turn( $conv->id() ) );
	}

	public function test_ai_disabled_is_byte_identical_legacy_behaviour(): void {
		update_option( Settings::OPTION_NAME, array( 'ai_enabled' => false ) );
		[ $owner, $conv ] = $this->open_conversation();

		$this->post_visitor_message( $owner, $conv->uuid(), 'hello' );

		$this->assertFalse( $this->turns->has_pending_turn( $conv->id() ) );
		$this->assertSame( 0, $this->turns->count_for_conversation( $conv->id() ) );
	}

	public function test_visitor_asking_for_a_human_hands_off_before_the_model(): void {
		$this->provider->push( AiResult::answer( 'should not be used' ) );
		[ $owner, $conv ] = $this->open_conversation();

		$this->post_visitor_message( $owner, $conv->uuid(), 'please connect me to a human' );
		$this->worker()->process_due( 10 );

		$this->assertSame( 0, $this->provider->call_count() );
		$this->assertSame( ConversationStatus::WAITING_FOR_OPERATOR, $this->conversations->find_by_id( $conv->id() )->status() );
		$this->assertSame( HandoffReason::VISITOR_REQUESTED, $this->turns->latest_for_conversation( $conv->id() )['handoff_reason'] );
	}

	public function test_model_refusal_hands_off(): void {
		$this->provider->push( AiResult::refusal( true ) );
		[ $owner, $conv ] = $this->open_conversation();

		$this->post_visitor_message( $owner, $conv->uuid(), 'What is the meaning of life?' );
		$this->worker()->process_due( 10 );

		$this->assertSame( ConversationStatus::WAITING_FOR_OPERATOR, $this->conversations->find_by_id( $conv->id() )->status() );
		$this->assertSame( HandoffReason::UNCERTAIN, $this->turns->latest_for_conversation( $conv->id() )['handoff_reason'] );
		$this->assertSame( array(), $this->ai_messages( $conv->id() ) );
	}

	public function test_provider_timeout_retries_then_hands_off_terminally(): void {
		$this->provider->push( AiResult::error( AiErrorClass::TIMEOUT ) );
		$this->provider->push( AiResult::error( AiErrorClass::TIMEOUT ) );
		$this->provider->push( AiResult::error( AiErrorClass::TIMEOUT ) );
		[ $owner, $conv ] = $this->open_conversation();

		$this->post_visitor_message( $owner, $conv->uuid(), 'a question' );

		// First run schedules a retry.
		$this->worker()->process_due( 10 );
		$row = $this->turns->latest_for_conversation( $conv->id() );
		$this->assertSame( AiTurnRepository::STATUS_QUEUED, $row['status'] );
		$this->assertSame( 1, (int) $row['attempts'] );

		// Force the retry to be due and drain remaining attempts.
		global $wpdb;
		$table = $wpdb->prefix . Migrator::AI_TURNS_TABLE;
		for ( $i = 0; $i < 4; $i++ ) {
			$wpdb->query( "UPDATE {$table} SET available_at = '2000-01-01 00:00:00' WHERE status = 'queued'" ); // phpcs:ignore
			$this->worker()->process_due( 10 );
		}

		$this->assertSame( ConversationStatus::WAITING_FOR_OPERATOR, $this->conversations->find_by_id( $conv->id() )->status() );
		$this->assertSame( HandoffReason::PROVIDER_FAILED, $this->turns->latest_for_conversation( $conv->id() )['handoff_reason'] );
		$this->assertSame( 'timeout', $this->turns->latest_for_conversation( $conv->id() )['provider_error_class'] );
	}

	public function test_operator_takeover_skips_the_queued_turn(): void {
		$this->provider->push( AiResult::answer( 'should be skipped' ) );
		[ $owner, $conv ] = $this->open_conversation();
		$operator         = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->post_visitor_message( $owner, $conv->uuid(), 'a question' );
		$this->conversations->claim( $this->conversations->find_by_id( $conv->id() ), $operator );

		$this->worker()->process_due( 10 );

		$this->assertSame( 0, $this->provider->call_count() );
		$this->assertSame( 'skipped', $this->turns->latest_for_conversation( $conv->id() )['status'] );
		$this->assertSame( array(), $this->ai_messages( $conv->id() ) );
	}

	public function test_a_handoff_stops_all_further_ai_turns(): void {
		$this->provider->push( AiResult::refusal( false ) );
		$this->provider->push( AiResult::answer( 'should never be produced' ) );
		[ $owner, $conv ] = $this->open_conversation();

		$this->post_visitor_message( $owner, $conv->uuid(), 'first question' );
		$this->worker()->process_due( 10 );
		$this->assertSame( HandoffReason::REFUSED, $this->turns->latest_for_conversation( $conv->id() )['handoff_reason'] );

		// The conversation is now waiting_for_operator; a further visitor
		// message must not create a new AI turn.
		$again = $this->post_visitor_message( $owner, $conv->uuid(), 'second question' );
		$this->assertTrue( $again['ok'] );
		$this->worker()->process_due( 10 );

		// Only the first turn ever reached the provider; the second message
		// created no turn and the second scripted answer is never produced.
		$this->assertSame( 1, $this->provider->call_count() );
		$this->assertSame( 1, $this->turns->count_for_conversation( $conv->id() ) );
		$this->assertSame( array(), $this->ai_messages( $conv->id() ) );
	}

	public function test_duplicate_visitor_message_delivery_yields_one_turn(): void {
		$this->provider->push( AiResult::answer( 'one answer' ) );
		[ $owner, $conv ] = $this->open_conversation();

		wp_set_current_user( $owner );
		$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );
		$controller                 = new ConversationsController(
			$this->health,
			$this->conversations,
			$this->messages,
			null,
			null,
			$this->turns,
			$this->responder()
		);

		$key = wp_generate_uuid4();
		foreach ( array( 1, 2 ) as $ignored ) {
			$request = new WP_REST_Request( 'POST', '/x' );
			$request->set_param( 'conversation_uuid', $conv->uuid() );
			$request->set_param( 'text', 'same question' );
			$request->set_param( 'idempotency_key', $key );
			$controller->handle_post_message( $request );
		}

		$this->assertSame( 1, $this->turns->count_for_conversation( $conv->id() ) );
	}
}
