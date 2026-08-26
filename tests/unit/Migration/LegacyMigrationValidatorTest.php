<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Migration;

use PHPUnit\Framework\TestCase;
use UniversalSupportChat\Conversations\MessageRepository;
use UniversalSupportChat\Conversations\NoteRepository;
use UniversalSupportChat\Core\Security\CredentialVault;
use UniversalSupportChat\Migration\LegacyMigrationMessageMapRepository;
use UniversalSupportChat\Migration\LegacyMigrationValidator;
use UniversalSupportChat\Persistence\SchemaHealth;

/**
 * The validator's own collaborators are `final` repository classes, not
 * mockable — for a pure, DB-free unit test, real instances backed by an
 * unavailable schema are used instead, since
 * `validate_registry_self_consistency()` never touches them at all.
 *
 * @covers \UniversalSupportChat\Migration\LegacyMigrationValidator
 */
final class LegacyMigrationValidatorTest extends TestCase {

	private function validator(): LegacyMigrationValidator {
		$schema_health = new SchemaHealth();

		return new LegacyMigrationValidator(
			new MessageRepository( $schema_health, new CredentialVault() ),
			new NoteRepository( $schema_health, new CredentialVault() ),
			new LegacyMigrationMessageMapRepository( $schema_health )
		);
	}

	public function test_registry_self_consistency_passes_for_the_real_registry(): void {
		$this->assertSame( array(), $this->validator()->validate_registry_self_consistency() );
	}
}
