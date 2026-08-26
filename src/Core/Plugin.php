<?php
/**
 * Composition root.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Core;

use UniversalSupportChat\Administration\Conversations\ConversationDetailPage;
use UniversalSupportChat\Administration\Conversations\ConversationInboxPage;
use UniversalSupportChat\Administration\Conversations\HubActions;
use UniversalSupportChat\Administration\Diagnostics\DiagnosticsPage;
use UniversalSupportChat\Administration\Hub\HubPage;
use UniversalSupportChat\Audit\AuditLogger;
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
use UniversalSupportChat\Migration\Cli\LegacyMigrateCommand;
use UniversalSupportChat\Migration\DefaultDenyQuiescenceStateProvider;
use UniversalSupportChat\Migration\InProcessLegacyExportClient;
use UniversalSupportChat\Migration\LegacyMigrationBatchLogRepository;
use UniversalSupportChat\Migration\LegacyMigrationMapRepository;
use UniversalSupportChat\Migration\LegacyMigrationMessageMapRepository;
use UniversalSupportChat\Migration\LegacyMigrationRunRepository;
use UniversalSupportChat\Migration\LegacyMigrationValidator;
use UniversalSupportChat\Migration\PhaseABackfillService;
use UniversalSupportChat\Migration\PhaseBReconciliationService;
use UniversalSupportChat\Persistence\MigrationFailedException;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalSupportChat\Privacy\Redactor;

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
	 * SC-M03 work packages 3-4: conversation-level legacy migration map,
	 * for tests/diagnostics.
	 *
	 * @var LegacyMigrationMapRepository|null
	 */
	private ?LegacyMigrationMapRepository $legacy_migration_map = null;

	/**
	 * SC-M03 work packages 3-4: Phase A preparatory backfill, for tests.
	 *
	 * @var PhaseABackfillService|null
	 */
	private ?PhaseABackfillService $phase_a_backfill_service = null;

	/**
	 * SC-M03 work packages 3-4: Phase B final reconciliation/validation, for tests.
	 *
	 * @var PhaseBReconciliationService|null
	 */
	private ?PhaseBReconciliationService $phase_b_reconciliation_service = null;

	/**
	 * SC-M03 work packages 3-4: read-only migration validators, for tests.
	 *
	 * @var LegacyMigrationValidator|null
	 */
	private ?LegacyMigrationValidator $legacy_migration_validator = null;

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
		$dispatcher     = new ContractOperationDispatcher( $conversations, $messages, $channel_status, $audit );

		// SC-M03 work package 1: outbound Contract v1 client (ADR-0005 §4,
		// ADR-0007). Wired here for future escalation/delivery call sites;
		// this work package does not itself trigger any outbound call.
		$outbound_signer               = new OutboundSignatureSigner( $own_keys );
		$outbound_transport            = new InProcessContractTransport();
		$this->adapter_contract_client = new AdapterContractClient( $peers, $outbound_signer, $outbound_transport, $audit );

		$settings->register();

		( new DiagnosticsPage( $schema_health, $audit_repo, $vault ) )->register();
		( new ConversationsController( $schema_health, $conversations, $messages ) )->register();
		( new RetentionCleanupHandler( $conversations, $messages, $notes, $settings, $audit ) )->register();
		( new ContractDiscovery( $peers ) )->register();
		( new ContractOperationsController( $verifier, $dispatcher ) )->register();
		( new NonceCleanupHandler( $nonces ) )->register();
		( new PairingPage( $own_keys, $peers ) )->register();
		( new PairingActions( $pairing, $own_keys ) )->register();

		$inbox  = new ConversationInboxPage( $schema_health, $conversations );
		$detail = new ConversationDetailPage( $schema_health, $conversations, $messages, $notes );
		( new HubPage( $inbox, $detail ) )->register();
		( new HubActions( $schema_health, $conversations, $messages, $notes, $audit ) )->register();
		( new WidgetAssets( $settings, $schema_health ) )->register();

		// SC-M03 work packages 3-4: legacy migration engine (ADR-0008,
		// sc-m03-wp3-wp4-legacy-migration-engine-plan-v1.md). Reaches
		// Universal Telegram only through InProcessLegacyExportClient, its
		// own dedicated WP-CLI command below — never through the widget,
		// Hub, or Contract v1 request paths above.
		$legacy_export_client         = new InProcessLegacyExportClient();
		$quiescence                   = new DefaultDenyQuiescenceStateProvider();
		$legacy_migration_map         = new LegacyMigrationMapRepository( $schema_health );
		$legacy_migration_message_map = new LegacyMigrationMessageMapRepository( $schema_health );
		$legacy_migration_runs        = new LegacyMigrationRunRepository( $schema_health );
		$legacy_migration_batch_log   = new LegacyMigrationBatchLogRepository( $schema_health );

		$this->legacy_migration_map           = $legacy_migration_map;
		$this->legacy_migration_validator     = new LegacyMigrationValidator( $messages, $notes, $legacy_migration_message_map );
		$this->phase_a_backfill_service       = new PhaseABackfillService(
			$legacy_export_client,
			$conversations,
			$messages,
			$notes,
			$legacy_migration_map,
			$legacy_migration_message_map,
			$legacy_migration_runs,
			$legacy_migration_batch_log
		);
		$this->phase_b_reconciliation_service = new PhaseBReconciliationService(
			$legacy_export_client,
			$quiescence,
			$messages,
			$notes,
			$legacy_migration_map,
			$legacy_migration_message_map,
			$this->legacy_migration_validator
		);

		( new LegacyMigrateCommand(
			$this->phase_a_backfill_service,
			$this->phase_b_reconciliation_service,
			$legacy_migration_map,
			$this->legacy_migration_validator
		) )->register();

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
	 * SC-M03 work packages 3-4 conversation-level legacy migration map (tests/diagnostics).
	 */
	public function legacy_migration_map(): ?LegacyMigrationMapRepository {
		return $this->legacy_migration_map;
	}

	/**
	 * SC-M03 work package 3 Phase A preparatory backfill (tests).
	 */
	public function phase_a_backfill_service(): ?PhaseABackfillService {
		return $this->phase_a_backfill_service;
	}

	/**
	 * SC-M03 work package 4 Phase B reconciliation/validation (tests).
	 */
	public function phase_b_reconciliation_service(): ?PhaseBReconciliationService {
		return $this->phase_b_reconciliation_service;
	}

	/**
	 * SC-M03 work package 4 read-only migration validators (tests).
	 */
	public function legacy_migration_validator(): ?LegacyMigrationValidator {
		return $this->legacy_migration_validator;
	}
}
