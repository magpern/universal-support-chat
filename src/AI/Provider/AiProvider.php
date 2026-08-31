<?php
/**
 * Provider-neutral AI generation interface (ADR-0018 §7).
 *
 * @package UniversalSupportChat
 */

declare( strict_types=1 );

namespace UniversalSupportChat\AI\Provider;

/**
 * A single synchronous generation call. Implementations **must not throw**
 * for a provider-side failure (timeout, transport error, HTTP error,
 * malformed body): that is an {@see AiResult} in the ERROR outcome. Only a
 * programming error may surface as an exception.
 *
 * The only concrete implementation reaching the network is
 * {@see OpenAiChatProvider}, confined to `src/AI/Provider/` and invoked
 * exclusively by the async turn worker — never a visitor or Hub request
 * (ADR-0018 §2, §7). {@see FakeProvider} is used everywhere in tests.
 */
interface AiProvider {

	/**
	 * Generates one response for the given request.
	 *
	 * @param AiRequest $request Fully-assembled request.
	 */
	public function generate( AiRequest $request ): AiResult;
}
