/**
 * Deterministic conversation logic (build spec §6.2).
 *
 * The top questions — hours, contact/phone, links, canned answers — are resolved entirely
 * client-side from the config the plugin already provides. No model call, so these answers
 * are instant, free, and cannot hallucinate. Anything we can't map deterministically gets a
 * friendly fallback (the LLM + retrieval path is a later milestone; there is no backend yet).
 */

import type { RenderConfig } from './config';

export type Answer = {
  title: string;
  /** Extra lines rendered with preserved newlines (e.g. the weekly hours summary). */
  lines?: string[];
  link?: { href: string; label: string };
};

export interface ChatMessage {
  role: 'user' | 'assistant';
  content: string;
}

/** All quick-reply labels shown as options in the flow. */
export function optionLabels(cfg: RenderConfig): string[] {
  const fromConfig = cfg.quickReplies.map((q) => q.label).filter(Boolean);
  return fromConfig.length ? fromConfig : ['Hours', 'Contact'];
}

function inferType(input: string): string {
  const s = input.toLowerCase();
  // Substring (not word-boundary) matching so plurals/inflections match too:
  // "hours", "opens", "closing", "closed" all count as an hours question.
  if (/hour|open|clos/.test(s)) return 'hours';
  if (/phone|call|contact|reach|email|number/.test(s)) return 'contact';
  return 'unknown';
}

/**
 * True when the input has a deterministic answer (a quick reply, or a hours/contact keyword).
 * Anything else is handed to the AI (or the no-AI fallback).
 */
export function hasDeterministicAnswer(input: string, cfg: RenderConfig): boolean {
  const qr = cfg.quickReplies.find((q) => q.label.toLowerCase() === input.trim().toLowerCase());
  if (qr) return true;
  return inferType(input) !== 'unknown';
}

/** Flatten an Answer to plain text (used to seed the AI transcript with prior turns). */
export function answerToText(a: Answer): string {
  const parts = [a.title];
  if (a.lines?.length) parts.push(a.lines.join('\n'));
  if (a.link) parts.push(a.link.href);
  return parts.join('\n');
}

function contactLine(cfg: RenderConfig): string {
  const name = cfg.business.name || 'us';
  return cfg.business.phone
    ? `You can reach ${name} at ${cfg.business.phone}.`
    : `Please use our contact page to reach ${name}.`;
}

function hoursAnswer(cfg: RenderConfig): Answer {
  const h = cfg.hours;
  if (h.isOpen === null) {
    return { title: `Our hours aren't listed here yet. ${contactLine(cfg)}` };
  }
  const status = h.isOpen
    ? `We're open right now${h.closesAt ? ` until ${h.closesAt}` : ''}.`
    : `We're currently closed.${h.nextOpenAt ? ` We open ${h.nextOpenAt}.` : ''}`;
  return {
    title: status,
    lines: h.summary ? h.summary.split('\n') : undefined,
  };
}

/**
 * Resolve a user message (a clicked quick-reply label or free text) to a deterministic
 * answer. Quick-reply clicks are matched by label; free text falls back to keyword
 * inference; everything else gets the no-backend fallback.
 */
export function resolveAnswer(input: string, cfg: RenderConfig): Answer {
  const qr = cfg.quickReplies.find((q) => q.label.toLowerCase() === input.trim().toLowerCase());
  const type = qr ? qr.type : inferType(input);

  switch (type) {
    case 'hours':
      return hoursAnswer(cfg);
    case 'contact':
      return { title: contactLine(cfg) };
    case 'link':
      return qr && qr.value
        ? { title: 'Here you go:', link: { href: qr.value, label: qr.value } }
        : { title: contactLine(cfg) };
    case 'text':
      return { title: qr && qr.value ? qr.value : contactLine(cfg) };
    default:
      return {
        title: `I can't answer that one just yet — a chat assistant is coming soon. ${contactLine(cfg)}`,
      };
  }
}

/**
 * Render an Answer as a bot message bubble (JSX so multi-line hours keep their line breaks).
 */
/**
 * Ask the AI (same-origin plugin endpoint). The plugin calls the model server-side with the
 * client's own key — the key is never exposed here. Returns the reply text or throws.
 */
export async function callAI(cfg: RenderConfig, transcript: ChatMessage[]): Promise<string> {
  const res = await fetch(cfg.chatUrl, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Pegasus-Botify-Nonce': cfg.nonce,
    },
    body: JSON.stringify({ messages: transcript.slice(-12) }),
  });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const data = (await res.json()) as { reply?: string; error?: string };
  if (data.reply) return data.reply;
  throw new Error(data.error || 'no_reply');
}

/** A plain bot bubble for AI (or fallback) replies. */
export function TextBubble({ text }: { text: string }) {
  return <div style={{ whiteSpace: 'pre-line' }}>{text}</div>;
}

export function AnswerBubble({ input, cfg }: { input: string; cfg: RenderConfig }) {
  const answer = resolveAnswer(input, cfg);
  return (
    <div style={{ whiteSpace: 'normal' }}>
      <div>{answer.title}</div>
      {answer.lines && (
        <div style={{ marginTop: 6, fontSize: 13, whiteSpace: 'pre-line', opacity: 0.9 }}>
          {answer.lines.join('\n')}
        </div>
      )}
      {answer.link && (
        <div style={{ marginTop: 6 }}>
          <a href={answer.link.href} target="_blank" rel="noreferrer">
            {answer.link.label}
          </a>
        </div>
      )}
    </div>
  );
}
