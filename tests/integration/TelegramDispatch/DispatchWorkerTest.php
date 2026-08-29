<?php
/**
 * @package UniversalSupportChat
 */

namespace UniversalSupportChat\Tests\Integration\TelegramDispatch;

use UniversalSupportChat\TelegramDispatch\DispatchWorker;
use WP_UnitTestCase;

/**
 * ADR-0014 Amendment 1 — `DispatchWorker::request_immediate_run()` is the
 * visitor / Hub request's only expedite step: it schedules an async run and
 * fires a non-blocking cron loopback, and is non-throwing across its entire
 * boundary.
 */
final class DispatchWorkerTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_unschedule_hook( DispatchWorker::HOOK );
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'schedule_event' );
		parent::tear_down();
	}

	public function test_it_schedules_a_one_off_worker_run(): void {
		$this->assertFalse( wp_next_scheduled( DispatchWorker::HOOK ) );

		DispatchWorker::request_immediate_run();

		$this->assertNotFalse( wp_next_scheduled( DispatchWorker::HOOK ) );
	}

	public function test_it_does_not_stack_duplicate_events(): void {
		DispatchWorker::request_immediate_run();
		$first = wp_next_scheduled( DispatchWorker::HOOK );

		DispatchWorker::request_immediate_run();
		DispatchWorker::request_immediate_run();

		$this->assertSame( $first, wp_next_scheduled( DispatchWorker::HOOK ) );
	}

	public function test_it_never_throws_when_the_loopback_request_fails(): void {
		add_filter( 'pre_http_request', static fn () => new \WP_Error( 'down', 'loopback down' ), 10, 3 );

		DispatchWorker::request_immediate_run();

		$this->assertNotFalse( wp_next_scheduled( DispatchWorker::HOOK ) );
	}

	public function test_it_never_throws_when_the_loopback_request_raises(): void {
		add_filter(
			'pre_http_request',
			static function (): void {
				throw new \RuntimeException( 'catastrophic loopback failure' );
			},
			10,
			3
		);

		// Must not propagate.
		DispatchWorker::request_immediate_run();

		$this->addToAssertionCount( 1 );
	}

	public function test_it_never_throws_when_scheduling_itself_fails(): void {
		add_filter( 'schedule_event', '__return_false' );

		DispatchWorker::request_immediate_run();

		$this->addToAssertionCount( 1 );
	}
}
