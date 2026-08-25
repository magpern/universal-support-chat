<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\Audit;

use UniversalSupportChat\Audit\AuditLogger;
use UniversalSupportChat\Audit\AuditLogRepository;
use UniversalSupportChat\Persistence\MigrationLock;
use UniversalSupportChat\Persistence\Migrator;
use UniversalSupportChat\Persistence\SchemaHealth;
use UniversalSupportChat\Privacy\Classification;
use UniversalSupportChat\Privacy\Redactor;
use WP_UnitTestCase;

final class AuditLoggerTest extends WP_UnitTestCase {

	public function test_record_redacts_secrets_from_persisted_context(): void {
		( new Migrator( new MigrationLock() ) )->maybe_migrate();

		$health  = new SchemaHealth();
		$logger  = new AuditLogger( $health, new Redactor() );
		$repo    = new AuditLogRepository( $health );

		$ok = $logger->record(
			'diagnostics.self_test',
			'system',
			null,
			array(
				'note'  => 'visible',
				'token' => 'should-not-persist',
			),
			array(
				'note'  => Classification::PUBLIC,
				'token' => Classification::SECRET,
			),
			Classification::INTERNAL
		);

		$this->assertTrue( $ok );

		$recent  = $repo->recent( 1 );
		$context = json_decode( (string) $recent[0]['context'], true );

		$this->assertSame( 'visible', $context['note'] );
		$this->assertArrayNotHasKey( 'token', $context );
	}
}
