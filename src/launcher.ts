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

import type { BootstrapConfig, BubblePosition } from './config';

const CONFIG_TAG_ID = 'pegasus-botify-config';

function readConfig(): BootstrapConfig {
  const el = document.getElementById(CONFIG_TAG_ID);
  const fallback: BootstrapConfig = {
    restUrl: '',
    position: 'bottom-right',
    bubbleColor: null,
    greeting: 'Hi! How can I help?',
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

function positionStyles(position: BubblePosition): Partial<CSSStyleDeclaration> {
  const base: Partial<CSSStyleDeclaration> = { position: 'fixed', bottom: '20px', zIndex: '2147483000' };
  return position === 'bottom-left' ? { ...base, left: '20px' } : { ...base, right: '20px' };
}

function createBubble(config: BootstrapConfig): HTMLButtonElement {
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.setAttribute('aria-label', 'Open chat');
  btn.className = 'pegasus-botify-bubble';
  btn.textContent = '💬';
  Object.assign(btn.style, positionStyles(config.position), {
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
