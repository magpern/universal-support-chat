<?php
/**
 * Composition root.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Core;

use UniversalSupportChat\Administration\Diagnostics\DiagnosticsPage;
use UniversalSupportChat\Audit\AuditLogger;
use UniversalSupportChat\Audit\AuditLogRepository;
use UniversalSupportChat\ChannelContract\ContractDiscovery;
use UniversalSupportChat\Conversations\ConversationRepository;
use UniversalSupportChat\Conversations\MessageRepository;
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

		$settings->register();

		( new DiagnosticsPage( $schema_health, $audit_repo, $vault ) )->register();
		( new ConversationsController( $schema_health, $conversations, $messages ) )->register();
		( new RetentionCleanupHandler( $conversations, $messages, $settings, $audit ) )->register();
		( new ContractDiscovery() )->register();

		unset( $caps );
	}

	/**
	 * Schema health for this request (tests / diagnostics).
	 */
	public function schema_health(): ?SchemaHealth {
		return $this->schema_health;
	}
}
