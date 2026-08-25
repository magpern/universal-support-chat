<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\ChannelContract\Auth;

use UniversalSupportChat\ChannelContract\Auth\ContractOperations;
use PHPUnit\Framework\TestCase;

final class ContractOperationsTest extends TestCase {

	public function test_full_adapter_allow_list_is_valid(): void {
		$this->assertTrue( ContractOperations::is_valid_adapter_allow_list( ContractOperations::ADAPTER_TO_SUPPORT_CHAT ) );
	}

	public function test_empty_allow_list_is_invalid(): void {
		$this->assertFalse( ContractOperations::is_valid_adapter_allow_list( array() ) );
	}

	public function test_support_chat_to_adapter_operation_is_not_a_valid_adapter_allow_list_entry(): void {
		$this->assertFalse( ContractOperations::is_valid_adapter_allow_list( array( 'ensure_channel_case' ) ) );
	}

	public function test_invented_operation_name_is_invalid(): void {
		$this->assertFalse( ContractOperations::is_valid_adapter_allow_list( array( 'delete_everything' ) ) );
	}

	public function test_update_operator_presence_is_not_an_implemented_operation(): void {
		$this->assertNotContains( 'update_operator_presence', ContractOperations::ADAPTER_TO_SUPPORT_CHAT );
		$this->assertFalse( ContractOperations::is_valid_adapter_allow_list( array( 'update_operator_presence' ) ) );
	}

	public function test_report_delivery_failure_is_an_implemented_operation(): void {
		$this->assertContains( 'report_delivery_failure', ContractOperations::ADAPTER_TO_SUPPORT_CHAT );
	}
}
