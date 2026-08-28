<?php
/**
 * Cross-plugin interop (ADR-0012): Support Chat's automatic
 * message dispatch drives the REAL, signed Contract v1
 * `ensure_channel_case` + `deliver_message` path into Universal Telegram's
 * real merged `main`, producing a real encrypted transport row — and a
 * reply that arrived FROM Telegram is never mirrored back out.
 *
 * Wires real collaborators on both sides (no mocking of the Contract
 * client/server/signer/verifier/pairing) and performs a real two-way
 * Ed25519 pairing, exactly as UT's own interop suite does. The only test
 * double is a `pre_http_request` filter standing in for the Telegram Bot
 * API network boundary (never part of the Contract v1 chain under test).
 *
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Interop;

use UniversalSupportChat\Audit\AuditLogger;
use UniversalSupportChat\ChannelContract\Auth\ContractOperations as ScContractOperations;
use UniversalSupportChat\ChannelContract\Auth\OwnKeyManager as ScOwnKeyManager;
use UniversalSupportChat\ChannelContract\Auth\PairingService as ScPairingService;
use UniversalSupportChat\ChannelContract\Auth\PeerRepository as ScPeerRepository;
use UniversalSupportChat\Conversations\ConversationMessage;
use UniversalSupportChat\Conversations\ConversationRepository;
use UniversalSupportChat\Conversations\ConversationStatus;
use UniversalSupportChat\Conversations\MessageRepository as ScMessageRepository;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar as ScCapabilityRegistrar;
use UniversalSupportChat\Core\Configuration\Settings as ScSettings;
use UniversalSupportChat\Core\Plugin as ScPlugin;
use UniversalSupportChat\Core\Security\CredentialVault as ScCredentialVault;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth as ScSchemaHealth;
use UniversalSupportChat\Privacy\Redactor as ScRedactor;
use UniversalSupportChat\TelegramDispatch\DispatchOutboxRepository;
use UniversalSupportChat\TelegramDispatch\DispatchRecord;
use UniversalTelegram\Audit\AuditLogger as UtAuditLogger;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar as UtCapabilityRegistrar;
use UniversalTelegram\Core\Configuration\Settings as UtSettings;
use UniversalTelegram\Core\Security\CredentialVault as UtCredentialVault;
use UniversalTelegram\Persistence\SchemaHealth as UtSchemaHealth;
use UniversalTelegram\Privacy\Redactor as UtRedactor;
use UniversalTelegram\SupportChatAdapter\Auth\OwnKeyManager as UtOwnKeyManager;
use UniversalTelegram\SupportChatAdapter\Auth\PairingService as UtPairingService;
use UniversalTelegram\SupportChatAdapter\Auth\PeerRepository as UtPeerRepository;
use UniversalTelegram\SupportChatAdapter\ChannelBindingRepository;
use UniversalTelegram\SupportChatAdapter\ContractConstants;
use UniversalTelegram\SupportChatAdapter\DiscoveryClient;
use UniversalTelegram\SupportChatAdapter\Identity\OperatorIdentityMapRepository;
use UniversalTelegram\SupportChatAdapter\Inbound\InboundAdapterBridge;
use UniversalTelegram\SupportChatAdapter\Inbound\SupportChatContractClient;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use WP_UnitTestCase;

/**
 * @coversNothing
 */
final class TelegramDispatchInteropTest extends WP_UnitTestCase {

	private ConversationRepository $sc_conversations;
	private ScMessageRepository $sc_messages;
	private DispatchOutboxRepository $sc_outbox;

	private ChannelBindingRepository $ut_bindings;
	private BotProfileRepository $ut_bots;
	private DestinationRepository $ut_destinations;
	private DiscoveryClient $ut_discovery;
	private OperatorIdentityMapRepository $ut_identities;
	private SupportChatContractClient $ut_inbound_client;

	private int $bot_id;
	private string $parent_chat_id = '-1009999000001';

	protected function setUp(): void {
		parent::setUp();

		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		$sc_schema              = new ScSchemaHealth();
		$this->sc_conversations = new ConversationRepository( $sc_schema );
		$this->sc_messages      = new ScMessageRepository( $sc_schema, new ScCredentialVault() );
		$this->sc_outbox        = new DispatchOutboxRepository( $sc_schema );

		$ut_schema             = new UtSchemaHealth();
		$this->ut_bindings     = new ChannelBindingRepository( $ut_schema );
		$this->ut_bots         = new BotProfileRepository( $ut_schema, new UtCredentialVault() );
		$this->ut_destinations = new DestinationRepository( $ut_schema );
		$this->ut_discovery    = new DiscoveryClient();
		$this->ut_identities   = new OperatorIdentityMapRepository( $ut_schema );

		add_filter( 'pre_http_request', array( $this, 'fake_telegram_http' ), 10, 3 );

		( new ScCapabilityRegistrar() )->grant_to_administrator();
		( new UtCapabilityRegistrar() )->grant_to_administrator();

		// --- Real two-way pairing. ---------------------------------------
		$sc_own = new ScOwnKeyManager( new ScCredentialVault() );
		$ut_own = new UtOwnKeyManager( new UtCredentialVault() );
		$sc_key = $sc_own->ensure_key_pair();
		$ut_key = $ut_own->ensure_key_pair();
		self::assertIsArray( $sc_key );
		self::assertIsArray( $ut_key );

		$sc_pairing = new ScPairingService(
			new ScPeerRepository( $sc_schema ),
			new AuditLogger( $sc_schema, new ScRedactor() )
		);
		$ut_pairing = new UtPairingService(
			new UtPeerRepository( $ut_schema ),
			new UtAuditLogger( $ut_schema, new UtRedactor() )
		);

		self::assertTrue(
			$sc_pairing->pair(
				'universal-telegram',
				$ut_key['public_key'],
				$ut_key['key_id'],
				ScContractOperations::ADAPTER_TO_SUPPORT_CHAT,
				UtCapabilityRegistrar::MANAGE,
				false,
				1,
				null,
				'universal-telegram/v1/support-chat'
			)->ok()
		);
		self::assertTrue(
			$ut_pairing->pair(
				'universal-support-chat',
				$sc_key['public_key'],
				$sc_key['key_id'],
				ContractConstants::support_chat_to_adapter_operations(),
				ScCapabilityRegistrar::MANAGE,
				false,
				1
			)->ok()
		);

		// --- UT adapter enabled + safe bot/destination fixtures. --------
		$bot = $this->ut_bots->create( 'interop-dispatch-bot', 'not-a-real-secret' );
		self::assertNotNull( $bot );
		$this->bot_id = $bot->id();

		$parent = $this->ut_destinations->create( $this->bot_id, DestinationKind::SUPERGROUP, $this->parent_chat_id, null, 'interop-parent' );
		self::assertNotNull( $parent );

		update_option(
			UtSettings::OPTION_NAME,
			array_merge(
				( new UtSettings() )->get(),
				array(
					'support_chat_adapter_enabled'        => true,
					'support_chat_adapter_bot_id'         => $this->bot_id,
					'support_chat_adapter_destination_id' => $parent->id(),
				)
			)
		);

		$this->ut_inbound_client = new SupportChatContractClient(
			new UtPeerRepository( $ut_schema ),
			$ut_own,
			$this->ut_discovery,
			new \UniversalTelegram\SupportChatAdapter\Auth\SignatureSigner( $ut_own ),
			true
		);

		// SC dispatch feature on.
		update_option( ScSettings::OPTION_NAME, array( 'telegram_dispatch_enabled' => true ) );

		do_action( 'rest_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'fake_telegram_http' ), 10 );
		parent::tearDown();
	}

	private function outbox_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}universal_telegram_outbound_messages" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	private function service(): \UniversalSupportChat\TelegramDispatch\TelegramDispatchService {
		$service = ScPlugin::instance()->telegram_dispatch_service();
		self::assertNotNull( $service, 'SC dispatch service was not wired — is the plugin booted?' );

		return $service;
	}

	private function open_conversation(): \UniversalSupportChat\Conversations\Conversation {
		$owner        = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$conversation = $this->sc_conversations->create( $owner );
		self::assertNotNull( $conversation );
		$opened = $this->sc_conversations->transition( $conversation, ConversationStatus::OPEN );

		return $opened ?? $conversation;
	}

	public function test_visitor_message_reaches_ut_as_a_real_encrypted_transport_row(): void {
		$conversation = $this->open_conversation();
		$message      = $this->sc_messages->create( $conversation->id(), ConversationMessage::DIRECTION_VISITOR, 'Where is my parcel?', 'stored', null );
		self::assertNotNull( $message );
		$this->sc_outbox->enqueue( $message->uuid(), $conversation->id(), $conversation->uuid(), 'visitor' );

		$before = $this->outbox_count();
		$result = $this->service()->dispatch_due();

		self::assertSame( 1, $result['delivered'], 'reason: ' . (string) ( $this->sc_outbox->find( $message->uuid() )->last_reason() ?? 'none' ) );
		self::assertGreaterThan( $before, $this->outbox_count(), 'a real UT transport row must have been created' );

		// A real active binding was created against the real SC conversation UUID.
		$binding = $this->ut_bindings->find_by_conversation_uuid( $conversation->uuid() );
		self::assertNotNull( $binding );
		self::assertTrue( $binding->is_active() );

		self::assertSame( DispatchRecord::STATE_DELIVERED, $this->sc_outbox->find( $message->uuid() )->state() );

		// No legacy UT conversation table exists at all.
		global $wpdb;
		self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'universal_telegram_conversations' ) ) );
	}

	public function test_repeated_dispatch_does_not_duplicate_the_telegram_delivery(): void {
		$conversation = $this->open_conversation();
		$message      = $this->sc_messages->create( $conversation->id(), ConversationMessage::DIRECTION_OPERATOR, 'Shipped today.', 'stored', null );
		$this->sc_outbox->enqueue( $message->uuid(), $conversation->id(), $conversation->uuid(), 'operator' );

		$this->service()->dispatch_due();
		$after_first = $this->outbox_count();

		// Re-enqueue attempt (idempotent) + another sweep.
		self::assertFalse( $this->sc_outbox->enqueue( $message->uuid(), $conversation->id(), $conversation->uuid(), 'operator' ) );
		$this->service()->dispatch_due();

		self::assertSame( $after_first, $this->outbox_count(), 'a retry must not create a second transport row' );
	}

	public function test_telegram_originated_reply_is_ingested_but_never_mirrored_back(): void {
		$conversation = $this->open_conversation();

		// Establish the binding via a first outbound visitor message.
		$seed = $this->sc_messages->create( $conversation->id(), ConversationMessage::DIRECTION_VISITOR, 'Hello?', 'stored', null );
		$this->sc_outbox->enqueue( $seed->uuid(), $conversation->id(), $conversation->uuid(), 'visitor' );
		$this->service()->dispatch_due();

		$binding = $this->ut_bindings->find_by_conversation_uuid( $conversation->uuid() );
		self::assertNotNull( $binding );

		$operator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		self::assertNotNull( $this->ut_identities->create( $operator_id, 556677, 'tg_op', $operator_id ) );

		$bot    = $this->ut_bots->find( $this->bot_id );
		$bridge = new InboundAdapterBridge(
			$this->ut_bindings,
			$this->ut_discovery,
			$this->ut_inbound_client,
			$this->ut_identities,
			new UtAuditLogger( new UtSchemaHealth(), new UtRedactor() ),
			true
		);

		$bridge->try_handle(
			$bot,
			$this->parent_chat_id,
			$binding->telegram_topic_id(),
			array(
				'update_id' => 424242,
				'message'   => array(
					'message_id'        => 424242,
					'message_thread_id' => $binding->telegram_topic_id(),
					'from'              => array(
						'id'         => 556677,
						'is_bot'     => false,
						'first_name' => 'Op',
					),
					'chat'              => array(
						'id'   => (int) $this->parent_chat_id,
						'type' => 'supergroup',
					),
					'text'              => 'Replying from Telegram',
				),
			),
			424242
		);

		// SC now has the operator message.
		$messages = $this->sc_messages->list_for_conversation( $conversation->id() );
		$operator = array_values(
			array_filter( $messages, static fn ( ConversationMessage $m ) => ConversationMessage::DIRECTION_OPERATOR === $m->direction() )
		);
		self::assertNotEmpty( $operator );
		$ingested = end( $operator );
		self::assertSame( 'Replying from Telegram', $ingested->plaintext_body() );

		// That ingested message carries a permanent suppression marker...
		$marker = $this->sc_outbox->find( $ingested->uuid() );
		self::assertNotNull( $marker );
		self::assertSame( DispatchRecord::STATE_SUPPRESSED, $marker->state() );

		// ...and a dispatch sweep produces no further UT transport row.
		$before = $this->outbox_count();
		$this->service()->dispatch_due();
		self::assertSame( $before, $this->outbox_count(), 'a Telegram-originated reply must never be mirrored back out' );
	}

	public function test_message_is_retained_and_retryable_when_ut_is_disabled(): void {
		update_option(
			UtSettings::OPTION_NAME,
			array_merge( ( new UtSettings() )->get(), array( 'support_chat_adapter_enabled' => false ) )
		);

		$conversation = $this->open_conversation();
		$message      = $this->sc_messages->create( $conversation->id(), ConversationMessage::DIRECTION_VISITOR, 'anyone there?', 'stored', null );
		$this->sc_outbox->enqueue( $message->uuid(), $conversation->id(), $conversation->uuid(), 'visitor' );

		$result = $this->service()->dispatch_due();

		self::assertSame( 1, $result['failed'] );
		// The Support Chat message is untouched and still readable.
		self::assertSame( 'anyone there?', $this->sc_messages->find_by_uuid( $message->uuid() )->plaintext_body() );
		// Delivery state is durable and retryable.
		$record = $this->sc_outbox->find( $message->uuid() );
		self::assertSame( DispatchRecord::STATE_FAILED, $record->state() );
		self::assertGreaterThan( 0, $record->attempts() );
	}

	/**
	 * @param false|array<string, mixed> $preempt Preempt value.
	 * @param array<string, mixed>       $args    Request args.
	 * @param string                     $url     Request URL.
	 *
	 * @return false|array<string, mixed>
	 */
	public function fake_telegram_http( $preempt, array $args, string $url ) {
		if ( false === strpos( $url, 'api.telegram.org' ) ) {
			return $preempt;
		}

		if ( false !== strpos( $url, '/createForumTopic' ) ) {
			static $thread = 500;
			++$thread;

			return array(
				'response' => array( 'code' => 200 ),
				'body'     => (string) wp_json_encode(
					array(
						'ok'     => true,
						'result' => array( 'message_thread_id' => $thread ),
					)
				),
			);
		}

		return array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode(
				array(
					'ok'     => true,
					'result' => array(),
				)
			),
		);
	}
}
