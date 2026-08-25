<?php
/**
 * Conversation status transition map.
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\Conversations;

/**
 * Status vocabulary for SC-M01. Availability/waiting-queue product states
 * beyond these remain SC-M06.
 */
final class ConversationStatus {

	public const NEW                  = 'new';
	public const OPEN                 = 'open';
	public const WAITING_FOR_VISITOR  = 'waiting_for_visitor';
	public const WAITING_FOR_OPERATOR = 'waiting_for_operator';
	public const RESOLVED             = 'resolved';
	public const ARCHIVED             = 'archived';

	/**
	 * Transition map.
	 *
	 * @return array<string, array<int, string>>
	 */
	private static function map(): array {
		return array(
			self::NEW                  => array( self::OPEN, self::ARCHIVED ),
			self::OPEN                 => array( self::WAITING_FOR_VISITOR, self::WAITING_FOR_OPERATOR, self::RESOLVED, self::ARCHIVED ),
			self::WAITING_FOR_VISITOR  => array( self::OPEN, self::RESOLVED, self::ARCHIVED ),
			self::WAITING_FOR_OPERATOR => array( self::OPEN, self::RESOLVED, self::ARCHIVED ),
			self::RESOLVED             => array( self::ARCHIVED, self::OPEN ),
			self::ARCHIVED             => array(),
		);
	}

	/**
	 * Whether $from may move to $to.
	 *
	 * @param string $from Current status.
	 * @param string $to   Target status.
	 */
	public static function is_valid_transition( string $from, string $to ): bool {
		$map = self::map();
		return isset( $map[ $from ] ) && in_array( $to, $map[ $from ], true );
	}

	/**
	 * All authorized status values.
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array_keys( self::map() );
	}

	/**
	 * Whether the status is terminal for visitor activity.
	 *
	 * @param string $status Status value.
	 */
	public static function is_terminal( string $status ): bool {
		return self::ARCHIVED === $status || self::RESOLVED === $status;
	}

	/**
	 * Whether the status is still active for the visitor.
	 *
	 * @param string $status Status value.
	 */
	public static function is_active( string $status ): bool {
		return ! self::is_terminal( $status );
	}
}
