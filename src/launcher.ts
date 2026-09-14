/**
 * Launcher — stage 1 of the two-stage load (build spec §3.4).
 *
 * Always enqueued, a few KB, framework-free. It renders the chat bubble, reads the inline
 * bootstrap config, and does nothing else until the user clicks. On first click it
 * dynamically imports the widget chunk (stage 2 — React + the chat UI), which is what
 * defers React's bytes until they are actually needed.
 *
 * Deliberately no imports from ./widget at module scope — only the dynamic import() inside
 * the click handler — so Rollup keeps the widget (and React) in a separate lazy chunk.
 */

import type { BootstrapConfig } from './config';

const CONFIG_TAG_ID = 'pegasus-botify-config';

// These small helpers are duplicated (not imported from config) so the launcher stays a
// single self-contained chunk — importing shared *stateful* code from config would make
// Rollup emit a cross-chunk import between the entry and the lazy widget chunk.

function faClassName(raw: string | null | undefined): string {
  const v = (raw || 'fa-comment').trim();
  return /(^|\s)fa(\s|$)/.test(v) ? v : `fa ${v}`;
}

const CHAT_ICON_SVG =
  '<svg viewBox="0 0 24 24" width="26" height="26" fill="currentColor" aria-hidden="true">' +
  '<path d="M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2z"/></svg>';

function isFontAwesomeAvailable(): boolean {
  try {
    const probe = document.createElement('i');
    probe.className = 'fa';
    probe.style.cssText = 'position:absolute;left:-9999px;visibility:hidden;';
    document.body.appendChild(probe);
    const family = getComputedStyle(probe).fontFamily || '';
    probe.remove();
    return /font\s*awesome/i.test(family);
  } catch {
    return false;
  }
}

function readConfig(): BootstrapConfig {
  const el = document.getElementById(CONFIG_TAG_ID);
  const fallback: BootstrapConfig = {
    restUrl: '',
    position: 'bottom-right',
    bubbleColor: null,
    greeting: 'Hi! How can I help?',
    offsetBottom: 20,
    offsetRight: 20,
    windowOffsetBottom: 20,
    windowOffsetRight: 20,
    bubbleIcon: 'fa-comment',
  };
  if (!el || !el.textContent) {
    return fallback;
  }
  try {
    return { ...fallback, ...JSON.parse(el.textContent) };
  } catch {
    return fallback;
  }
}

function positionStyles(config: BootstrapConfig): Partial<CSSStyleDeclaration> {
  const bottom = `${config.offsetBottom}px`;
  const side = `${config.offsetRight}px`;
  const base: Partial<CSSStyleDeclaration> = { position: 'fixed', bottom, zIndex: '2147483000' };
  return config.position === 'bottom-left' ? { ...base, left: side } : { ...base, right: side };
}

function createBubble(config: BootstrapConfig): HTMLButtonElement {
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.setAttribute('aria-label', 'Open chat');
  btn.className = 'pegasus-botify-bubble';
  // Font Awesome 4 icon (the theme ships FA4), with a plain SVG fallback when FA is absent.
  if (isFontAwesomeAvailable()) {
    const icon = document.createElement('i');
    icon.className = faClassName(config.bubbleIcon);
    icon.setAttribute('aria-hidden', 'true');
    btn.appendChild(icon);
  } else {
    btn.innerHTML = CHAT_ICON_SVG;
  }
  Object.assign(btn.style, positionStyles(config), {
    width: '60px',
    height: '60px',
    borderRadius: '50%',
    border: 'none',
    cursor: 'pointer',
    fontSize: '26px',
    lineHeight: '60px',
    color: '#fff',
    background: config.bubbleColor || '#2563eb',
    boxShadow: '0 4px 14px rgba(0,0,0,0.25)',
  });
  return btn;
}

/**
 * Cache the widget-module and config promises so the work happens once, and — crucially —
 * so we can start it during idle time (or on hover) BEFORE the click. That way the first
 * click just mounts something already downloaded and configured, instead of waiting on a
 * ~125 KB chunk download + a config round-trip on the click path.
 */
let widgetPromise: Promise<typeof import('./widget')> | null = null;
let configPromise: Promise<unknown> | null = null;

function loadWidget() {
  if (!widgetPromise) {
    widgetPromise = import('./widget');
  }
  return widgetPromise;
}

function loadConfig(restUrl: string) {
  if (!configPromise) {
    configPromise = restUrl
      ? fetch(restUrl)
          .then((r) => (r.ok ? r.json() : null))
          .catch(() => null)
      : Promise.resolve(null);
  }
  return configPromise;
}

/** Warm the widget chunk + config so opening is instant. Safe to call more than once. */
function prefetch(config: BootstrapConfig) {
  loadWidget();
  loadConfig(config.restUrl);
}

/** Run a low-priority task when the browser is idle (with a timeout fallback). */
function whenIdle(fn: () => void) {
  const ric = (window as unknown as { requestIdleCallback?: (cb: () => void, opts?: { timeout: number }) => void })
    .requestIdleCallback;
  if (ric) {
    ric(fn, { timeout: 3000 });
  } else {
    setTimeout(fn, 1500);
  }
}

function init() {
  const config = readConfig();
  const bubble = createBubble(config);

  let mounted = false;

  const open = async () => {
    if (mounted) return;
    bubble.disabled = true;
    bubble.textContent = '…';
    try {
      const [mod, remoteConfig] = await Promise.all([loadWidget(), loadConfig(config.restUrl)]);
      if (mounted) return;
      // The widget (React ChatBotify) renders its own floating button + window, so once it
      // mounts we retire this placeholder bubble to avoid two buttons.
      const container = document.createElement('div');
      container.className = 'pegasus-botify-widget-root';
      document.body.appendChild(container);
      mod.mount(container, config, remoteConfig);
      mounted = true;
      bubble.remove();
    } catch (err) {
      // Non-fatal: keep the placeholder bubble usable so the user can retry.
      // eslint-disable-next-line no-console
      console.error('[pegasus-botify] failed to load chat widget', err);
      bubble.disabled = false;
      bubble.textContent = '💬';
    }
  };

  bubble.addEventListener('click', open);
  // Desktop: start warming the moment the pointer reaches the bubble.
  bubble.addEventListener('pointerenter', () => prefetch(config), { once: true });

  document.body.appendChild(bubble);

  // Warm the chunk + config in the background after the page settles, so the first open is
  // instant even without a hover (mobile). This is deferred to idle, so it never competes
  // with initial page load — the launcher itself stays tiny.
  whenIdle(() => prefetch(config));
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
