<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Privacy;

use PHPUnit\Framework\TestCase;
use UniversalSupportChat\Privacy\Classification;
use UniversalSupportChat\Privacy\Redactor;

final class RedactorTest extends TestCase {

	public function test_secret_fields_are_stripped_sensitive_masked_unmapped_dropped(): void {
		$redactor = new Redactor();
		$result   = $redactor->redact(
			array(
				'public_note' => 'hello',
				'token'       => 'sekrit',
				'email'       => 'a@example.com',
				'unknown'     => 'gone',
			),
			array(
				'public_note' => Classification::PUBLIC,
				'token'       => Classification::SECRET,
				'email'       => Classification::SENSITIVE,
			)
		);

		$this->assertSame( 'hello', $result['public_note'] );
		$this->assertArrayNotHasKey( 'token', $result );
		$this->assertSame( '***', $result['email'] );
		$this->assertArrayNotHasKey( 'unknown', $result );
	}
}
