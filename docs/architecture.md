# Architecture

How Pegasus Botify is put together, the two operating models, and the request flows.

## The three pieces

1. **Widget** — a React ([React ChatBotify](https://react-chatbotify.com/)) chat UI, built
   with Vite. Ships pre-built in `dist/`. Loaded in two stages (a tiny launcher, then a lazy
   chunk) so React's bytes only download when needed.
2. **Plugin** — the WordPress plugin (`pegasus-botify`). Owns the settings UI, the front-end
   asset enqueue, a public config endpoint, and — in BYOK mode — the server-side model call.
3. **Backend** — an optional, separate Node/TypeScript service
   (`../pegasus-botify-backend`). Only used in the hosted/subscription model. Holds one API
   key, verifies per-site tokens, and (eventually) enforces subscriptions. See
   [backend.md](backend.md).

## Two operating models

### BYOK (bring-your-own-key) — the default, live path

```
Visitor's browser                     Client's WordPress (their server)
┌───────────────┐   GET /v1/config    ┌───────────────────────────────┐
│ launcher.js   │────────────────────▶│ class-rest.php  → render config│
│  (bubble)     │                     └───────────────────────────────┘
│      │ click                                 
│      ▼                                
│ widget chunk  │   POST /v1/chat      ┌───────────────────────────────┐   HTTPS
│ (RCB)         │────────────────────▶│ class-chat.php                 │────────▶ Anthropic API
│               │◀────────────────────│  reads the CLIENT's key,       │◀────────  (Claude)
└───────────────┘   { reply }          │  builds prompt, calls model    │
                                       └───────────────────────────────┘
```

- The widget only ever talks to the client's **own** WordPress (same origin).
- `class-chat.php` reads the client's Anthropic key (from a `wp-config.php` constant or the
  settings field) and calls the Anthropic Messages API via `wp_remote_post`. The key stays on
  the client's server; it is never in the config response or the browser.
- The client is billed by Anthropic directly. No central server to run.

### Hosted / subscription (middleman) — scaffolded, not yet wired in the widget

```
Visitor's browser        Client's WordPress            Your backend (one server, many tenants)
┌──────────┐  /v1/config  ┌──────────────┐            ┌────────────────────────────────────┐
│ widget   │─────────────▶│ class-rest   │  mints a   │ /v1/session  (mint token from key)  │
│          │              │  publicToken │◀──────────▶│ /v1/chat     verify token → check    │  HTTPS
│          │  /v1/chat    └──────────────┘            │  subscription → call model → stream  │─────▶ Anthropic
│          │───────────────────────────────────────▶ │ /v1/status   connection check        │◀───── (your key)
└──────────┘◀─────────────────────────────────────── └────────────────────────────────────┘
                { streamed reply }
```

- The widget posts to your backend's `sessionEndpoint` with a short-lived, domain-scoped
  `publicToken` (minted by the plugin from the site's **site key**).
- Your backend holds the model key, checks the subscription, and streams the reply.
- The client pays *you* a subscription; if they stop paying, the backend stops answering —
  copying the (GPL) plugin code doesn't help them, because the key and gating live on your
  server. This is the whole reason the backend is separate.

**Current status:** the backend exists as an MVP (verified end-to-end via curl — see
[backend.md](backend.md)), and the plugin already returns `sessionEndpoint` + `publicToken`
in its config and mints the token correctly. The remaining work is pointing the widget's AI
call at `sessionEndpoint` (with `publicToken`) instead of the same-origin `chatUrl` when the
hosted mode is configured — see `src/provider/PegasusProvider.ts` (a documented stub).

## The two-stage widget load

To keep pages fast, the widget loads in two stages (build spec §3.4):

1. **Launcher** (`dist/launcher.js`, ~3 KB, no React) — always enqueued. Renders the chat
   bubble and reads the inline bootstrap config.
2. **Widget chunk** (`dist/assets/widget-*.js`, React + RCB) — dynamically `import()`ed. It's
   **prefetched during browser idle** (and on hover) so the first click opens instantly.

Relative asset URLs (Vite `base: './'`) make the dynamic import resolve correctly on
subdirectory installs. React ChatBotify's `rcb-` CSS/markup prefix is rewritten to
`pegasus-rcb-` at build time to avoid collisions with other `rcb-` styles on the host site.

## Conversation logic

Answers are resolved in this order (`src/flows.tsx`, `src/widget.tsx`):

1. **Deterministic** — quick replies and hours/contact keywords are answered client-side from
   the config, instantly, with **no model call** (can't hallucinate, costs nothing).
2. **AI** — anything else is sent to the chat endpoint. The system prompt is assembled from
   business identity + computed hours state + the knowledge base (`class-chat.php`).
3. **Fallback** — if AI is off, the widget shows a friendly "not available" message; if the
   AI can't answer, it defers to the **help URL** from settings.

## Hours: computed server-side, never by the model

The business's open/closed state (`is_open`, `closes_at`, `next_open_at`, a plain-English
summary) is computed in PHP (`class-hours.php`) — DST-correct via `DateTimeZone`, from IANA
timezone names — and injected into the prompt as plain values. The model never does date
math. The same logic is ported to TypeScript in the backend (`hours.ts`) for the hosted path.

## Security model

- **The Anthropic key never reaches the browser.** BYOK keeps it on the client's server;
  hosted keeps it on your backend. The public config endpoint never includes it.
- **The chat endpoint is gated.** BYOK's `/v1/chat` requires a WordPress nonce and is per-IP
  rate-limited (the client pays per message). Hosted `/v1/chat` requires a valid
  `publicToken` (HMAC of `{domain, exp}` signed with the site key) and checks the request
  Origin against the tenant's domain allowlist.
- **Import/export excludes secrets.** Settings export omits the API key and site key; import
  merges, so it never wipes the key on the destination site.
