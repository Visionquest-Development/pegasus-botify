/**
 * PegasusProvider — custom React ChatBotify LLM Connector provider (build spec §6.1).
 *
 * PLACEHOLDER (Milestone 7). The real implementation POSTs { messages, sessionId,
 * publicToken } to `sessionEndpoint` (our backend) and streams the response back to RCB.
 * It NEVER constructs a model API request client-side and NEVER sees a model API key
 * (§3.1) — its only network target is our backend.
 *
 * The interface is sketched here so the widget can be written against a stable shape.
 */

export interface PegasusProviderOptions {
  /** Backend chat endpoint (from RenderConfig.sessionEndpoint). */
  sessionEndpoint: string;
  /** Short-lived, domain-scoped token minted by the plugin (RenderConfig.publicToken). */
  publicToken: string;
}

export interface ChatMessage {
  role: 'user' | 'assistant' | 'system';
  content: string;
}

/**
 * Not wired until Milestone 7. Throwing here keeps the placeholder honest if it is ever
 * imported early by mistake.
 */
export function createPegasusProvider(_options: PegasusProviderOptions): never {
  throw new Error('PegasusProvider is not implemented until Milestone 7.');
}
