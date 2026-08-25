<?php
/**
 * Inert Contract v1 discovery surface.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\ChannelContract;

use WP_REST_Response;

/**
 * Advertises Contract v1 capabilities without invoking any adapter.
 */
final class ContractDiscovery {

	public const CONTRACT_VERSION_ID = 'support-channel-contract/v1';

	/**
	 * Registers WordPress hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'universal-support-chat/v1',
			'/channel-contract',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_discover' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Returns inert Contract v1 discovery metadata.
	 */
	public function handle_discover(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'ok'                => true,
				'contract_version'  => self::CONTRACT_VERSION_ID,
				'adapter_required'  => false,
				'channel_available' => false,
				'operations'        => array(
					'ensure_channel_case',
					'notify_operators',
					'deliver_transcript_backfill',
					'deliver_message',
					'ingest_operator_reply',
					'claim',
					'release',
					'resolve',
					'reopen',
					'update_assignment',
					'update_operator_presence',
					'report_channel_unavailable',
					'report_delivery_failure',
				),
			),
			200
		);
	}
}
