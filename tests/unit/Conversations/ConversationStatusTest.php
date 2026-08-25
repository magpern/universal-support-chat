<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Conversations;

use PHPUnit\Framework\TestCase;
use UniversalSupportChat\Conversations\ConversationStatus;

final class ConversationStatusTest extends TestCase {

	public function test_authorized_vocabulary(): void {
		$this->assertSame(
			array(
				ConversationStatus::NEW,
				ConversationStatus::OPEN,
				ConversationStatus::WAITING_FOR_VISITOR,
				ConversationStatus::WAITING_FOR_OPERATOR,
				ConversationStatus::RESOLVED,
				ConversationStatus::ARCHIVED,
			),
			ConversationStatus::all()
		);
	}

	public function test_new_cannot_jump_to_resolved(): void {
		$this->assertFalse(
			ConversationStatus::is_valid_transition( ConversationStatus::NEW, ConversationStatus::RESOLVED )
		);
	}

	public function test_open_to_resolved_allowed(): void {
		$this->assertTrue(
			ConversationStatus::is_valid_transition( ConversationStatus::OPEN, ConversationStatus::RESOLVED )
		);
	}
}
