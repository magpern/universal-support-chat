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

		unset( $caps );
	}

	/**
	 * Schema health for this request (tests / diagnostics).
	 */
	public function schema_health(): ?SchemaHealth {
		return $this->schema_health;
	}
}
