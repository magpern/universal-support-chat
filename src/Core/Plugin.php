<?php
/**
 * Composition root.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Core;

use UniversalSupportChat\Administration\Compat\LegacySettingsRedirect;
use UniversalSupportChat\Administration\Conversations\ConversationDetailPage;
use UniversalSupportChat\Administration\Conversations\ConversationInboxPage;
use UniversalSupportChat\Administration\Conversations\HubActions;
use UniversalSupportChat\Administration\Diagnostics\DiagnosticsPage;
use UniversalSupportChat\Administration\Hub\HubPage;
use UniversalSupportChat\Administration\PluginActionLinks;
use UniversalSupportChat\Administration\Settings\SupportChatSettingsPage;
use UniversalSupportChat\Audit\AuditLogger;
use UniversalSupportChat\Availability\Admin\OverrideAction;
use UniversalSupportChat\Availability\AvailabilityResolver;
use UniversalSupportChat\Availability\AvailabilityService;
use UniversalSupportChat\Audit\AuditLogRepository;
use UniversalSupportChat\ChannelContract\Admin\PairingActions;
use UniversalSupportChat\ChannelContract\Admin\PairingPage;
use UniversalSupportChat\ChannelContract\Auth\NonceCleanupHandler;
use UniversalSupportChat\ChannelContract\Auth\NonceReplayRepository;
use UniversalSupportChat\ChannelContract\Auth\OwnKeyManager;
use UniversalSupportChat\ChannelContract\Auth\PairingService;
use UniversalSupportChat\ChannelContract\Auth\PeerRepository;
use UniversalSupportChat\ChannelContract\Auth\SignatureVerifier;
use UniversalSupportChat\ChannelContract\ChannelStatusRepository;
use UniversalSupportChat\ChannelContract\ContractDiscovery;
use UniversalSupportChat\ChannelContract\Outbound\AdapterContractClient;
use UniversalSupportChat\ChannelContract\Outbound\InProcessContractTransport;
use UniversalSupportChat\ChannelContract\Outbound\SignatureSigner as OutboundSignatureSigner;
use UniversalSupportChat\ChannelContract\Rest\ContractOperationDispatcher;
use UniversalSupportChat\ChannelContract\Rest\ContractOperationsController;
use UniversalSupportChat\ChatWidget\WidgetAssets;
use UniversalSupportChat\Conversations\ConversationRepository;
use UniversalSupportChat\Conversations\MessageRepository;
use UniversalSupportChat\Conversations\NoteRepository;
use UniversalSupportChat\Conversations\Rest\ConversationsController;
use UniversalSupportChat\Conversations\RetentionCleanupHandler;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;
use UniversalSupportChat\Core\Configuration\Settings;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Persistence\MigrationFailedException;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalSupportChat\Privacy\Redactor;
use UniversalSupportChat\TelegramDispatch\DispatchEnqueuer;
use UniversalSupportChat\TelegramDispatch\DispatchOutboxRepository;
use UniversalSupportChat\TelegramDispatch\DispatchWorker;
use UniversalSupportChat\TelegramDispatch\TelegramDispatchService;

/**
 * Hand-wired composition root. No dependency-injection container.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Whether init() has completed for this request.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Schema availability for this request.
	 *
	 * @var SchemaHealth|null
	 */
	private ?SchemaHealth $schema_health = null;

	/**
	 * Outbound Contract v1 client (Support Chat -> adapter), for future
	 * escalation/delivery call sites (SC-M03 work package 1).
	 *
	 * @var AdapterContractClient|null
	 */
	private ?AdapterContractClient $adapter_contract_client = null;

	/**
	 * ADR-0012 automatic Telegram dispatch worker, for tests/diagnostics.
	 *
	 * @var TelegramDispatchService|null
	 */
	private ?TelegramDispatchService $telegram_dispatch_service = null;

	/**
	 * ADR-0012 dispatch outbox repository, for tests/diagnostics.
	 *
	 * @var DispatchOutboxRepository|null
	 */
	private ?DispatchOutboxRepository $telegram_dispatch_outbox = null;

	/**
	 * Singleton accessor.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Boots the plugin once per request.
	 */
	public function init(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$settings      = new Settings();
		$schema_health = new SchemaHealth();
		$lock          = new MigrationLock();
		$migrator      = new Migrator( $lock );

		try {
			$migrator->maybe_migrate();
		} catch ( MigrationFailedException $exception ) {
			$schema_health->mark_unavailable( $exception->failure_code() );
		}

		$this->schema_health = $schema_health;

		$redactor   = new Redactor();
		$audit      = new AuditLogger( $schema_health, $redactor );
		$audit_repo = new AuditLogRepository( $schema_health );
		$vault      = new CredentialVault();
		$caps       = new CapabilityRegistrar();

		$conversations = new ConversationRepository( $schema_health );
		$messages      = new MessageRepository( $schema_health, $vault );
		$notes         = new NoteRepository( $schema_health, $vault );

		$peers          = new PeerRepository( $schema_health );
		$nonces         = new NonceReplayRepository( $schema_health );
		$own_keys       = new OwnKeyManager( $vault );
		$channel_status = new ChannelStatusRepository( $schema_health );
		$pairing        = new PairingService( $peers, $audit );
		$verifier       = new SignatureVerifier( $peers, $nonces );

		// ADR-0012: automatic Support Chat -> Telegram message dispatch.
		// The outbox is Support-Chat-owned durable delivery state; the
		// enqueuer is the post-commit seam the visitor REST path, the Hub
		// reply path, and (for loop prevention) the inbound Contract
		// ingest path call. Delivery itself runs only from WP-Cron
		// (DispatchWorker), never inside a visitor or Hub request.
		$dispatch_outbox   = new DispatchOutboxRepository( $schema_health );
		$dispatch_enqueuer = new DispatchEnqueuer( $settings, $dispatch_outbox );

		$dispatcher = new ContractOperationDispatcher( $conversations, $messages, $channel_status, $audit, $dispatch_enqueuer );

		// SC-M03 work package 1: outbound Contract v1 client (ADR-0005 §4,
		// ADR-0007). Wired here for future escalation/delivery call sites;
		// this work package does not itself trigger any outbound call.
		$outbound_signer               = new OutboundSignatureSigner( $own_keys );
		$outbound_transport            = new InProcessContractTransport();
		$this->adapter_contract_client = new AdapterContractClient( $peers, $outbound_signer, $outbound_transport, $audit );

		$settings->register();

		$dispatch_service = new TelegramDispatchService(
			$settings,
			$dispatch_outbox,
			$messages,
			$this->adapter_contract_client,
			$audit
		);

		// ADR-0014 Amendment 1: the visitor / Hub request only commits the
		// message + outbox row and fires a non-blocking async kick
		// (DispatchWorker::request_immediate_run). ALL Telegram-facing work —
		// topic creation, notify, delivery with delivery_class=interactive_chat
		// — happens only in this WP-Cron worker.

		// ADR-0017 (SC-M06): Support Chat is the sole availability authority.
		// The service loads the schedule / exceptions from Settings and the
		// manual override from its own autoloaded option, resolves state in
		// the site timezone, and reaps an expired override. Pure resolution
		// logic lives in AvailabilityResolver. Nothing here touches an adapter.
		$availability = new AvailabilityService( $settings, new AvailabilityResolver(), $audit );

		( new PluginActionLinks( UNIVERSAL_SUPPORT_CHAT_PLUGIN_FILE ) )->register();
		( new ConversationsController( $schema_health, $conversations, $messages, $dispatch_enqueuer, $availability ) )->register();
		( new RetentionCleanupHandler( $conversations, $messages, $notes, $settings, $audit, $dispatch_outbox ) )->register();
		( new OverrideAction( $audit ) )->register();
		( new DispatchWorker( $dispatch_service ) )->register();

		$this->telegram_dispatch_service = $dispatch_service;
		$this->telegram_dispatch_outbox  = $dispatch_outbox;
		( new ContractDiscovery( $peers ) )->register();
		( new ContractOperationsController( $verifier, $dispatcher ) )->register();
		( new NonceCleanupHandler( $nonces ) )->register();
		( new PairingPage( $own_keys, $peers ) )->register();
		( new PairingActions( $pairing, $own_keys ) )->register();

		// ADR-0015: the Support Chat menu owns three submenus — Conversations
		// (the Hub), Settings, and Diagnostics. The Hub top-level is
		// registered first so its explicit "Conversations" child label wins.
		// No new top-level menu is added.
		$inbox  = new ConversationInboxPage( $schema_health, $conversations, $availability );
		$detail = new ConversationDetailPage( $schema_health, $conversations, $messages, $notes );
		( new HubPage( $inbox, $detail ) )->register();
		( new SupportChatSettingsPage( $settings, $peers ) )->register();
		( new DiagnosticsPage( $schema_health, $audit_repo, $vault, $settings, $peers, $dispatch_outbox, $availability ) )->register();
		( new LegacySettingsRedirect() )->register();
		( new HubActions( $schema_health, $conversations, $messages, $notes, $audit, $dispatch_enqueuer ) )->register();
		( new WidgetAssets( $settings, $schema_health, $availability ) )->register();

		// The SC-M03 legacy-migration / final-cutover engine (legacy export,
		// Phase A/Phase B migration, quiescence, binding preparation, cutover
		// handoff) and its `wp universal-support-chat legacy-migrate` /
		// `legacy-bind` WP-CLI commands were RETIRED here (ADR-0013):
		// Universal Telegram ADR-0044 made that plugin transport/adapter-only,
		// so the machinery can no longer operate. Support Chat remains the
		// sole conversation system of record; the inbound
		// `ingest_operator_reply` Contract operation and the ADR-0012
		// outbound Telegram dispatch path are unaffected and wired above.

		unset( $caps );
	}

	/**
	 * Schema health for this request (tests / diagnostics).
	 */
	public function schema_health(): ?SchemaHealth {
		return $this->schema_health;
	}

	/**
	 * Outbound Contract v1 client for this request (tests / future
	 * escalation call sites).
	 */
	public function adapter_contract_client(): ?AdapterContractClient {
		return $this->adapter_contract_client;
	}

	/**
	 * ADR-0012 automatic Telegram dispatch worker (tests/diagnostics).
	 */
	public function telegram_dispatch_service(): ?TelegramDispatchService {
		return $this->telegram_dispatch_service;
	}

	/**
	 * ADR-0012 dispatch outbox repository (tests/diagnostics).
	 */
	public function telegram_dispatch_outbox(): ?DispatchOutboxRepository {
		return $this->telegram_dispatch_outbox;
	}
}
