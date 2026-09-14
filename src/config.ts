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
  /** Offset of the bubble from the bottom edge, in px. */
  offsetBottom: number;
  /** Offset of the bubble from the side edge (right for bottom-right, left for bottom-left), in px. */
  offsetRight: number;
  /** Offset of the OPEN chat window from the bottom edge, in px. */
  windowOffsetBottom: number;
  /** Offset of the OPEN chat window from the side edge, in px. */
  windowOffsetRight: number;
  /** Font Awesome 4 icon class for the chat button, e.g. "fa-comment". */
  bubbleIcon: string;
}

/**
 * Normalize a Font Awesome 4 class: ensure the base `fa` prefix is present.
 * "fa-comment" -> "fa fa-comment"; "fa fa-comment" -> unchanged; empty -> "fa fa-comment".
 */
export function faClassName(raw: string | null | undefined): string {
  const v = (raw || 'fa-comment').trim();
  return /(^|\s)fa(\s|$)/.test(v) ? v : `fa ${v}`;
}

/** A plain speech-bubble SVG used when Font Awesome isn't available. Inherits `currentColor`. */
export const CHAT_ICON_SVG =
  '<svg viewBox="0 0 24 24" width="26" height="26" fill="currentColor" aria-hidden="true">' +
  '<path d="M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2z"/></svg>';

/**
 * Is Font Awesome present on the page? We probe the font-family the `.fa` base class resolves
 * to — if any Font Awesome stylesheet is loaded it's a "FontAwesome"/"Font Awesome …" family.
 * Cached after the first check.
 */
let faCache: boolean | null = null;
export function isFontAwesomeAvailable(): boolean {
  if (faCache !== null) return faCache;
  try {
    const probe = document.createElement('i');
    probe.className = 'fa';
    probe.style.cssText = 'position:absolute;left:-9999px;visibility:hidden;';
    document.body.appendChild(probe);
    const family = getComputedStyle(probe).fontFamily || '';
    probe.remove();
    faCache = /font\s*awesome/i.test(family);
  } catch {
    faCache = false;
  }
  return faCache;
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
