/**
 * Shared types for the bootstrap config the plugin injects into the page, and the fuller
 * render config the REST endpoint returns.
 */

export type BubblePosition = 'bottom-right' | 'bottom-left';

/**
 * Minimal data the plugin prints inline (in the #pegasus-botify-config JSON tag) so the
 * launcher can render the bubble immediately, before any network request.
 */
export interface BootstrapConfig {
  /** Absolute URL of the REST config endpoint (widget fetches the full config from here). */
  restUrl: string;
  position: BubblePosition;
  bubbleColor: string | null;
  greeting: string;
}

/** The full payload from GET /wp-json/pegasus-botify/v1/config (see PHP class-rest.php). */
export interface RenderConfig extends BootstrapConfig {
  version: number;
  quickReplies: Array<{ label: string; type: string; value: string }>;
  appearance: {
    inheritTheme: boolean;
    bubbleColor: string | null;
    headerColor: string | null;
    accentColor: string | null;
    position: BubblePosition;
    avatar: string | null;
  };
  afterHoursAction: string;
  business: { name: string; phone: string };
  hours: {
    isOpen: boolean | null;
    closesAt: string | null;
    nextOpenAt: string | null;
    summary: string;
    timezone: string;
  };
  /** Bring-your-own-key AI: same-origin endpoint the widget POSTs to. */
  aiEnabled: boolean;
  chatUrl: string;
  nonce: string;
  /** Optional hosted-backend fields (managed tier); unused in BYOK mode. */
  sessionEndpoint: string | null;
  publicToken: string | null;
}
