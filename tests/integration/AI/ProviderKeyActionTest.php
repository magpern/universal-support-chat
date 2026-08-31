<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\AI;

use UniversalSupportChat\AI\Admin\ProviderKeyAction;
use UniversalSupportChat\AI\Provider\ProviderKeyManager;
use UniversalSupportChat\Audit\AuditLogRepository;
use UniversalSupportChat\Core\Capabilities\CapabilityRegistrar;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use WP_UnitTestCase;

/**
 * SC-M07 WP3 — the provider-key admin_post action: nonce + MANAGE gated,
 * set/rotate/clear, never renders the key back, audits only a marker.
 */
final class ProviderKeyActionTest extends WP_UnitTestCase {

	private ProviderKeyManager $keys;

	public function set_up(): void {
		parent::set_up();
		( new Migrator( new MigrationLock() ) )->maybe_migrate();
		( new CapabilityRegistrar() )->grant_to_administrator();
		$this->keys = new ProviderKeyManager( new CredentialVault() );
		$this->keys->clear();
	}

	public function tear_down(): void {
		$this->keys->clear();
		unset( $_POST, $_REQUEST );
		parent::tear_down();
	}

	private function run_action(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test harness sets a real nonce for the action under test.
		$_REQUEST['_wpnonce'] = wp_create_nonce( ProviderKeyAction::NONCE );
		$_POST['_wpnonce']    = $_REQUEST['_wpnonce']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.

		$catch = static function (): void {
			throw new \RuntimeException( 'redirected' );
		};
		add_filter( 'wp_redirect', $catch );

		try {
			( new ProviderKeyAction( $this->keys, null ) )->handle();
		} catch ( \RuntimeException $e ) {
			unset( $e );
		} finally {
			remove_filter( 'wp_redirect', $catch );
		}
	}

	public function test_requires_manage(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_POST['provider_key_op']  = 'set';
		$_POST['provider_api_key'] = 'sk-abc';
		$_REQUEST['_wpnonce']      = wp_create_nonce( ProviderKeyAction::NONCE );

		$this->expectException( \WPDieException::class );
		( new ProviderKeyAction( $this->keys, null ) )->handle();
	}

	public function test_set_then_rotate_then_clear(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_POST['provider_key_op']  = 'set';
		$_POST['provider_api_key'] = 'sk-first-key';
		$this->run_action();
		$this->assertSame( 'sk-first-key', $this->keys->token() );

		$_POST['provider_api_key'] = 'sk-second-key';
		$this->run_action();
		$this->assertSame( 'sk-second-key', $this->keys->token() );

		$_POST['provider_key_op'] = 'clear';
		$this->run_action();
		$this->assertFalse( $this->keys->is_configured() );
	}

	public function test_audit_records_only_a_marker_never_the_key(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$audit_repo = new AuditLogRepository( new SchemaHealth() );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test harness sets a real nonce for the action under test.
		$_REQUEST['_wpnonce']      = wp_create_nonce( ProviderKeyAction::NONCE );
		$_POST['_wpnonce']         = $_REQUEST['_wpnonce']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		$_POST['provider_key_op']  = 'set';
		$_POST['provider_api_key'] = 'sk-super-secret-should-not-be-logged';

		$catch = static function (): void {
			throw new \RuntimeException( 'redirected' );
		};
		add_filter( 'wp_redirect', $catch );
		try {
			( new ProviderKeyAction(
				$this->keys,
				new \UniversalSupportChat\Audit\AuditLogger( new SchemaHealth(), new \UniversalSupportChat\Privacy\Redactor() )
			) )->handle();
		} catch ( \RuntimeException $e ) {
			unset( $e );
		} finally {
			remove_filter( 'wp_redirect', $catch );
		}

		$rows = $audit_repo->recent( 10 );
		$this->assertNotEmpty( $rows );
		$row = $rows[0];
		$this->assertSame( 'ai.token_rotated', $row['action'] );
		$this->assertStringNotContainsString( 'sk-super-secret', wp_json_encode( $row ) );
	}
}
