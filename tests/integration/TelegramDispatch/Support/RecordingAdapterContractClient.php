<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\TelegramDispatch\Support;

use UniversalSupportChat\ChannelContract\Outbound\AdapterContractClient;

/**
 * Test seam over the real outbound Contract v1 client: records every call
 * and returns a scripted outcome, so `TelegramDispatchService` can be
 * exercised without a paired Universal Telegram peer. The dedicated interop
 * suite covers the real signed Contract v1 path.
 */
final class RecordingAdapterContractClient extends AdapterContractClient {

	/** @var array<int, array<string, mixed>> */
	public array $calls = array();

	/** @var array{ok: bool, status: int, reason: string|null, channel_case_ref: string, case_status: string|null} */
	public array $ensure_result;

	/** @var array{ok: bool, status: int, reason: string|null, reused: bool} */
	public array $deliver_result;

	/** @var array{ok: bool, status: int, reason: string|null, reused: bool} */
	public array $notify_result;

	public function __construct() {
		$this->ensure_result = array(
			'ok'               => true,
			'status'           => 200,
			'reason'           => null,
			'channel_case_ref' => 'ref-abc',
			'case_status'      => 'created',
		);

		$this->deliver_result = array(
			'ok'     => true,
			'status' => 200,
			'reason' => null,
			'reused' => false,
		);

		$this->notify_result = array(
			'ok'     => true,
			'status' => 200,
			'reason' => null,
			'reused' => false,
		);
	}

	public function ensure_channel_case( string $peer_id, string $conversation_uuid, string $reason_code, array $summary_meta = array() ): array {
		$this->calls[] = array(
			'op'                => 'ensure_channel_case',
			'peer_id'           => $peer_id,
			'conversation_uuid' => $conversation_uuid,
			'reason_code'       => $reason_code,
		);

		return $this->ensure_result;
	}

	public function notify_operators( string $peer_id, string $channel_case_ref, string $kind, string $summary = '', ?string $idempotency_key = null ): array {
		$this->calls[] = array(
			'op'               => 'notify_operators',
			'peer_id'          => $peer_id,
			'channel_case_ref' => $channel_case_ref,
			'kind'             => $kind,
		);

		return $this->notify_result;
	}

	public function deliver_message( string $peer_id, string $channel_case_ref, string $message_uuid, string $body, string $attribution = '' ): array {
		$this->calls[] = array(
			'op'               => 'deliver_message',
			'peer_id'          => $peer_id,
			'channel_case_ref' => $channel_case_ref,
			'message_uuid'     => $message_uuid,
			'body'             => $body,
			'attribution'      => $attribution,
		);

		return $this->deliver_result;
	}

	/**
	 * @param string $op Operation name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function calls_for( string $op ): array {
		return array_values( array_filter( $this->calls, static fn ( array $c ) => $c['op'] === $op ) );
	}
}
