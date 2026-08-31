<?php
/**
 * Server-side handoff pre-check on visitor text (ADR-0018 §4, §11).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\AI\Turn;

/**
 * A deterministic keyword pre-check run **before** the provider is called.
 * It catches the cases that must never reach the model:
 *
 * - the visitor explicitly asks for a person → {@see HandoffReason::VISITOR_REQUESTED};
 * - a safety-sensitive category (the fixed list the PO ratified in
 *   `sc-adr-0018-ai-first-po-acceptance.md`) → {@see HandoffReason::SAFETY};
 * - an order- or account-specific / side-effecting request → the AI has no
 *   tool for it → {@see HandoffReason::UNSUPPORTED_REQUEST}.
 *
 * It is intentionally simple substring/word matching — not a classifier
 * model — and it only ever *adds* a handoff; it never suppresses one.
 */
final class SafetyClassifier {

	/**
	 * "Get me a human" phrases.
	 */
	private const HUMAN_REQUEST = array(
		'speak to a human',
		'talk to a human',
		'speak to a person',
		'talk to a person',
		'real person',
		'speak to someone',
		'talk to someone',
		'human agent',
		'live agent',
		'speak to an agent',
		'talk to an agent',
		'connect me to',
		'get me a human',
		'human please',
		'agent please',
	);

	/**
	 * Safety-sensitive category phrases (PO-ratified list, ADR-0018 §4).
	 */
	private const SAFETY = array(
		// self-harm / suicide
		'suicide',
		'kill myself',
		'kill my self',
		'end my life',
		'self harm',
		'self-harm',
		'hurt myself',
		// threats / violence
		'kill you',
		'shoot',
		'bomb',
		'i will hurt',
		'threaten',
		'weapon',
		// legal advice
		'sue you',
		'lawsuit',
		'take legal action',
		'my lawyer',
		'legal advice',
		// medical advice
		'medical advice',
		'should i take',
		'diagnos',
		'prescription',
		'symptoms',
		// payment disputes / chargebacks / fraud
		'chargeback',
		'charge back',
		'dispute the charge',
		'unauthorized charge',
		'unauthorised charge',
		'fraud',
		'scammed',
		// account security / compromised account
		'account was hacked',
		'account hacked',
		'someone accessed my account',
		'compromised account',
		'reset my password',
	);

	/**
	 * Order- / account-specific or side-effecting request phrases. The AI
	 * ships zero tools, so these always hand off (ADR-0018 §5, §10).
	 */
	private const UNSUPPORTED = array(
		'refund',
		'money back',
		'cancel my order',
		'cancel order',
		'change my order',
		'modify my order',
		'where is my order',
		'track my order',
		'order status',
		'my order number',
		'cancel my subscription',
		'coupon',
		'discount code',
		'promo code',
		'voucher',
		'store credit',
		'change my email',
		'change my address',
		'update my address',
		'delete my account',
		'close my account',
	);

	/**
	 * Returns a {@see HandoffReason} if the visitor text must not reach the
	 * model, or null to let the turn proceed.
	 *
	 * @param string $visitor_text The visitor's last message.
	 */
	public function classify( string $visitor_text ): ?string {
		$haystack = ' ' . mb_strtolower( trim( $visitor_text ) ) . ' ';

		foreach ( self::SAFETY as $needle ) {
			if ( str_contains( $haystack, $needle ) ) {
				return HandoffReason::SAFETY;
			}
		}

		foreach ( self::HUMAN_REQUEST as $needle ) {
			if ( str_contains( $haystack, $needle ) ) {
				return HandoffReason::VISITOR_REQUESTED;
			}
		}

		foreach ( self::UNSUPPORTED as $needle ) {
			if ( str_contains( $haystack, $needle ) ) {
				return HandoffReason::UNSUPPORTED_REQUEST;
			}
		}

		return null;
	}
}
