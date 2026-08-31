/**
 * Widget — stage 2 of the two-stage load (build spec §3.4, §6).
 *
 * The lazily-imported chunk. It mounts React ChatBotify and drives the deterministic
 * quick-reply flows (§6.2). There is NO model/backend call at this milestone — every answer
 * is resolved client-side from the config (see flows.tsx). The custom PegasusProvider and
 * the LLM fallback arrive in later milestones (§6.1, M6–M7).
 */

import { useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import ChatBot, { type Flow, type Settings, type Styles, type Params } from 'react-chatbotify';
import type { BootstrapConfig, RenderConfig } from './config';
import {
  optionLabels,
  callAI,
  hasDeterministicAnswer,
  resolveAnswer,
  answerToText,
  type ChatMessage,
} from './flows';
import { resolveBrandColors } from './brand';

/**
 * Build a usable RenderConfig even if the REST fetch fails, so the widget still opens with
 * at least the greeting the launcher already had.
 */
function fallbackConfig(bootstrap: BootstrapConfig): RenderConfig {
  return {
    version: 0,
    restUrl: bootstrap.restUrl,
    position: bootstrap.position,
    bubbleColor: bootstrap.bubbleColor,
    greeting: bootstrap.greeting,
    quickReplies: [],
    appearance: {
      inheritTheme: true,
      bubbleColor: bootstrap.bubbleColor,
      headerColor: null,
      accentColor: null,
      position: bootstrap.position,
      avatar: null,
    },
    afterHoursAction: 'capture_lead',
    business: { name: '', phone: '' },
    hours: { isOpen: null, closesAt: null, nextOpenAt: null, summary: '', timezone: 'UTC' },
    aiEnabled: false,
    chatUrl: '',
    nonce: '',
    sessionEndpoint: null,
    publicToken: null,
  };
}

function buildFlow(cfg: RenderConfig, transcript: ChatMessage[]): Flow {
  const options = optionLabels(cfg);

  // Return plain text so React ChatBotify wraps replies in its bot-message bubble (and shows
  // the typing indicator while an async AI call resolves). Returning a `component` instead
  // renders raw/unstyled.
  const respond = async (params: Params): Promise<string> => {
    const input = params.userInput;
    transcript.push({ role: 'user', content: input });

    const remember = (text: string) => {
      transcript.push({ role: 'assistant', content: text });
      return text;
    };

    // Deterministic first: quick replies + hours/contact keywords (instant, no model call).
    if (hasDeterministicAnswer(input, cfg)) {
      return remember(answerToText(resolveAnswer(input, cfg)));
    }

    // Otherwise hand it to the AI (the plugin calls the model server-side).
    if (cfg.aiEnabled && cfg.chatUrl) {
      try {
        return remember(await callAI(cfg, transcript));
      } catch {
        return `Sorry — I couldn't answer that just now. ${contactLine(cfg)}`;
      }
    }

    // AI off: fall back to the deterministic generic reply.
    return remember(answerToText(resolveAnswer(input, cfg)));
  };

  return {
    start: { message: cfg.greeting, options, path: 'route' },
    route: { message: respond, options, path: 'route' },
  };
}

function contactLine(cfg: RenderConfig): string {
  return cfg.business.phone ? `Please call ${cfg.business.phone}.` : 'Please use our contact page.';
}

function buildSettings(cfg: RenderConfig): Settings {
  const colors = resolveBrandColors(cfg);
  const avatar = cfg.appearance.avatar || undefined;

  return {
    general: {
      embedded: false,
      primaryColor: colors.primary,
      secondaryColor: colors.secondary,
      showFooter: true,
    },
    chatWindow: { defaultOpen: true, showTypingIndicator: true },
    header: {
      title: cfg.business.name || 'Chat',
      showAvatar: !!avatar,
      avatar,
    },
    tooltip: { mode: 'CLOSE', text: 'Need help?' },
    footer: { text: '' },
    // Keep it a focused support widget — no emoji/file/voice/notifications.
    emoji: { disabled: true },
    fileAttachment: { disabled: true },
    voice: { disabled: true },
    notification: { disabled: true },
    chatInput: { enabledPlaceholderText: 'Type a message…' },
  };
}

function buildStyles(cfg: RenderConfig): Styles {
  if (cfg.position === 'bottom-left') {
    return {
      chatButtonStyle: { left: 20, right: 'auto' },
      chatWindowStyle: { left: 20, right: 'auto' },
      tooltipStyle: { left: 20, right: 'auto' },
    };
  }
  return {};
}

function Widget({ bootstrap, prefetched }: { bootstrap: BootstrapConfig; prefetched: RenderConfig | null }) {
  // If the launcher already fetched the config (during idle/hover), use it immediately so the
  // window opens with no round-trip. Otherwise fetch it here.
  const [cfg, setCfg] = useState<RenderConfig | null>(prefetched);
  const transcriptRef = useRef<ChatMessage[]>([]);

  useEffect(() => {
    if (cfg) return; // already have config (prefetched)
    let cancelled = false;
    const finish = (c: RenderConfig) => {
      if (!cancelled) setCfg(c);
    };

    if (!bootstrap.restUrl) {
      finish(fallbackConfig(bootstrap));
      return;
    }
    fetch(bootstrap.restUrl)
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then((data: RenderConfig) => finish(data))
      .catch(() => finish(fallbackConfig(bootstrap)));

    return () => {
      cancelled = true;
    };
  }, [bootstrap, cfg]);

  // Wait for config so the flow (greeting, hours, quick replies) is correct on first paint.
  if (!cfg) {
    return null;
  }

  return (
    <ChatBot
      flow={buildFlow(cfg, transcriptRef.current)}
      settings={buildSettings(cfg)}
      styles={buildStyles(cfg)}
    />
  );
}

/**
 * Mount the widget into a container. Called by the launcher after the dynamic import.
 *
 * `prefetched` is the full render config if the launcher already fetched it (during idle or
 * on hover) — passing it lets the window open with no config round-trip.
 */
export function mount(target: HTMLElement, config: BootstrapConfig, prefetched?: unknown): void {
  const remote = (prefetched && typeof prefetched === 'object' ? (prefetched as RenderConfig) : null);
  createRoot(target).render(<Widget bootstrap={config} prefetched={remote} />);
}
