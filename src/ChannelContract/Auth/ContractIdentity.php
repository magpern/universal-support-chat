<?php
/**
 * Fixed Contract v1 authentication identifiers (ADR-0007).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\ChannelContract\Auth;

/**
 * This plugin's own Contract identity plus the auth profile identifier,
 * pinned exactly to ADR-0007 §1 and §3. Not configurable.
 */
final class ContractIdentity {

	public const SELF_ID = 'universal-support-chat';

	public const AUTH_PROFILE_ID = 'support-channel-contract-auth/v1';
}
