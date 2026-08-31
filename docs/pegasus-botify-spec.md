# pegasus-botify — Build Specification

> **Purpose of this document:** This is the authoritative spec for the `pegasus-botify` project, written to be handed to Claude Code as a build prompt. It records decisions already made, constraints that must not be violated, and the intended build order. If something here conflicts with a habit or a default, this document wins — the decisions have reasons attached, and the reasons are documented.

**Author:** Jim O'Brien, Visionquest Development
**Status:** Pre-implementation. No code written yet.

---

## 1. What we are building

An AI-powered chat widget sold as a **monthly subscription add-on** to existing WordPress client sites. The widget answers customer questions 24/7, states office hours accurately, handles basic customer support, and captures leads when the business is closed.

Three separable pieces:

| Piece | What it is | Where it lives |
|---|---|---|
| **Widget** | React chat UI built on React ChatBotify | Bundled JS, shipped inside the plugin |
| **Plugin** | `pegasus-botify` WordPress plugin — settings UI, asset enqueue, config endpoint | Client's WordPress site |
| **Backend** | Multi-tenant service holding model credentials, knowledge base, subscription state | Our infrastructure |

**The backend is the product.** The plugin and widget are the delivery mechanism. Anything that constitutes durable business value — API keys, retrieval index, conversation logs, entitlement checks — lives server-side. See §3 for why this is non-negotiable.

---

## 2. Existing context

### Repositories

- **Pegasus theme** — https://github.com/Visionquest-Development/pegasus
  WordPress theme, v3.8 (approaching v4.0). Bootstrap 5.3.3, CMB2 for all custom fields and theme options, WooCommerce-ready. GPLv2+. Gulp build. Third-party libraries (including CMB2) bundled under `inc/`.
- **Pegasus child theme** — https://github.com/Visionquest-Development/pegasus-child
- **React ChatBotify** — https://github.com/react-chatbotify/react-chatbotify — MIT licensed, React 16–19 compatible.
- **LLM Connector plugin** — `@rcb-plugins/llm-connector` — provides the provider abstraction we will extend.

### Existing Pegasus plugin suite (naming convention)

`pegasus-blog`, `pegasus-callout`, `pegasus-carousel`, `pegasus-circle-progress`, `pegasus-countup`, `pegasus-lightbox`, `pegasus-masonry`, `pegasus-onepage`, `pegasus-popup`, `pegasus-slider`, `pegasus-tabs`, `pegasus-toggleslide`, `pegasus-wow`

`pegasus-botify` joins this suite and should follow its conventions. The theme uses TGMPA for plugin recommendations; add `pegasus-botify` to that list.

### Theme CSS custom properties available for brand matching

The theme exposes `--pegasus-background-color`, `--pegasus-header-bkg-color`, `--pegasus-nav-item-color`, `--pegasus-footer-bkg-color`, and others on `:root`.

---

## 3. Hard constraints

These are correctness requirements, not preferences. Violating any of them produces a broken or insecure product.

### 3.1 No model API key may ever reach the browser

React ChatBotify's LLM Connector ships built-in providers for OpenAI, Gemini, and WebLlm. **The OpenAI and Gemini built-ins call the model API directly from client-side JavaScript**, which means the key is visible in devtools on every client site. That is acceptable for a demo and fatal for a paid product deployed across many domains.

We write a **custom provider** (`PegasusProvider`) that POSTs to our own backend. The backend holds the credentials. No exceptions, no "just for local dev" shortcuts that might ship.

### 3.2 CMB2 must be loaded unconditionally, outside any hook

CMB2 has its own version-arbitration mechanism and **conditional loading breaks it**. Each copy of CMB2 defines a version-stamped bootstrap class (e.g. `CMB2_Bootstrap_2110` for v2.11.0), guarded internally by `class_exists( 'CMB2_Bootstrap_2110', false )`, and hooks its inclusion to `init` at a priority constant that *decrements with each release* (2.11.0 = 9957). Lowest priority number fires first, so the newest copy present on the site always wins and older copies no-op.

**Do:**
```php
// Top level of pegasus-botify.php, before any add_action calls
require_once __DIR__ . '/inc/cmb2/init.php';
```

**Do NOT:**
```php
// WRONG — breaks CMB2's version arbitration
if ( ! class_exists( 'CMB2' ) ) {
    require_once __DIR__ . '/inc/cmb2/init.php';
}

// WRONG — CMB2 explicitly documents that init.php must not load from a hook
add_action( 'init', function () {
    require_once __DIR__ . '/inc/cmb2/init.php';
} );
```

Failure mode of the conditional version: if the theme bundles an older CMB2 and the plugin bundles a newer one, the `class_exists` guard skips our load, the site silently runs the older library, and any newer field type we depend on breaks with no obvious cause.

Bundle CMB2 at `inc/cmb2/` to match the theme's convention and casing. Register fields on `cmb2_admin_init`.

### 3.3 Bundle our own React — do not alias to WordPress core's

The instinct to mirror the CMB2 "share if present" pattern does **not** transfer to JavaScript. CMB2 needs arbitration because PHP classes occupy one global namespace and genuinely collide. Properly bundled JS modules do not collide; two React instances merely cost bytes.

WordPress core registers `react` / `react-dom` / `wp-element` script handles, and aliasing to them saves ~45KB gzipped. We are not doing that, because we ship to arbitrary client sites whose WordPress version we do not control, and a version mismatch against another plugin that has already claimed the global produces hook-mismatch errors that are miserable to diagnose remotely.

Since the widget is lazy-loaded (§3.4), the bundle cost is deferred and effectively free. Predictability beats the bytes.

### 3.4 Lazy load on bubble click

Two-stage load:

1. **Launcher** — always enqueued, a few KB. Renders the chat bubble, reads config, does nothing else.
2. **Widget chunk** — `import()`ed on first bubble click. Contains React, React ChatBotify, and our provider.

Set Vite's `base` and `output.chunkFilename` to the plugin's URL so dynamic imports resolve correctly on subdirectory WordPress installs.

### 3.5 The model does not do date arithmetic

Never hand the model raw hours JSON and a clock and ask it to reason about whether the business is open. Compute `is_open`, `closes_at`, and `next_open_at` server-side as plain values and inject those into the prompt. Models are unreliable at date math and confidently wrong about it.

### 3.6 Timezone handling

Store IANA timezone names (`America/New_York`), never fixed UTC offsets. Use a real timezone library server-side. A fixed offset is wrong for roughly half the year in any DST region.

### 3.7 GPL reality

Pegasus is GPLv2+, so `pegasus-botify` is too. Anyone who receives the plugin may legally redistribute the PHP and JS. This is fine and expected — it is simply another reason the subscription must be enforced at the backend. Copyable code does not matter when the site key is what unlocks the model.

---

## 4. Decisions already made

| Decision | Choice |
|---|---|
| Chat UI library | React ChatBotify (MIT) — adopt, do not rebuild |
| LLM integration | `@rcb-plugins/llm-connector` with a **custom provider** pointing at our backend |
| Key storage | Our backend only |
| Distribution | Standalone plugin, **not** baked into the theme |
| Plugin name | `pegasus-botify` |
| CMB2 | Bundled in plugin; loaded unconditionally per §3.2 |
| React | Bundled in the lazy chunk; not aliased to WP core |
| Loading | Lazy on bubble click |
| Hours config | Per-site JSON, schema in §7 |
| Brand colors | Read `--pegasus-*` CSS custom properties when present, fall back to plugin settings |

---

## 5. WordPress plugin spec

### 5.1 File structure

```
pegasus-botify/
├── pegasus-botify.php          # Main plugin file; CMB2 require at top level
├── inc/
│   └── cmb2/                   # Bundled CMB2 library
├── includes/
│   ├── class-options.php       # CMB2 options page registration
│   ├── class-rest.php          # REST config endpoint
│   ├── class-enqueue.php       # Script/style enqueue + launcher config
│   └── class-hours.php         # Hours JSON validation + serialization
├── src/                        # Widget source (React)
│   ├── launcher.ts             # Always-loaded bubble
│   ├── widget.tsx              # Lazy chunk entry
│   └── provider/
│       └── PegasusProvider.ts  # Custom RCB LLM provider
├── dist/                       # Vite build output
├── vite.config.ts
├── package.json
└── readme.txt
```

### 5.2 Options page (CMB2)

Register under the existing Pegasus options structure when the theme is active; register a standalone top-level menu otherwise. Detect with `wp_get_theme()` / `get_template()`.

**Connection**
- Site key (text, required) — issued by our backend
- Connection status (read-only, populated by a backend ping)

**Business identity**
- Business name
- Business description / what we do (textarea, feeds the system prompt)
- Contact email for lead notifications
- Phone number

**Hours**
- Timezone (select, IANA names)
- Regular hours (CMB2 repeatable group: day select, open time, close time — multiple rows per day permitted for split shifts)
- Exceptions (CMB2 repeatable group: date, closed checkbox, optional open/close times, label)

> **Do not make clients edit raw JSON.** The CMB2 groups above serialize to the §7 schema on save. The JSON is a transport format, not a UI.

**Behavior**
- Greeting message
- Quick-reply buttons (repeatable group: label, response type, response value)
- After-hours action (select: capture lead / show hours only / show hours + contact form)
- Escalation email

**Appearance**
- Inherit Pegasus theme colors (checkbox, default on when theme is active)
- Bubble color, header color, accent color (color pickers, used when inherit is off)
- Bubble position (select: bottom-right / bottom-left)
- Avatar image (file field)

### 5.3 REST endpoint

`GET /wp-json/pegasus-botify/v1/config`

Public, cacheable. Returns everything the widget needs to render *before* any conversation starts. Must **not** include the site key — that is server-to-server only.

```json
{
  "greeting": "Hi! How can I help?",
  "quickReplies": [ { "label": "Hours", "value": "hours" } ],
  "appearance": { "inheritTheme": true, "bubbleColor": null, "position": "bottom-right" },
  "afterHoursAction": "capture_lead",
  "sessionEndpoint": "https://api.example.com/v1/chat",
  "publicToken": "..."
}
```

`publicToken` is a short-lived, domain-scoped token minted by the plugin from the site key — used by the widget to authenticate to the backend without exposing the site key itself.

### 5.4 Enqueue

- Launcher enqueued on all front-end pages (filterable so clients can exclude specific pages/post types)
- Config passed via `wp_localize_script` or a `type="application/json"` script tag
- Widget chunk not enqueued; loaded via dynamic `import()` from the launcher

---

## 6. Widget spec

### 6.1 Custom provider

```
PegasusProvider implements the LLM Connector provider interface
  → POST { messages, sessionId, publicToken } to sessionEndpoint
  → stream response back to RCB
  → surface a friendly fallback message on non-2xx
```

Never construct a model API request client-side. The provider's only network target is our backend.

### 6.2 Conversation design

Hybrid, deliberately:

- **Deterministic paths** for the top questions — hours, location, phone, book appointment, pricing page link. These render as quick-reply buttons and never involve a model call. Faster, free, and cannot hallucinate.
- **LLM + retrieval fallback** for everything else.

### 6.3 Styling

Scope aggressively. Shadow DOM preferred; if that proves painful with React ChatBotify's own styles, use a hashed class prefix and a CSS reset boundary. The widget lands in arbitrary themes and must not inherit or leak styles.

Brand matching: on mount, read `getComputedStyle(document.documentElement)` for `--pegasus-header-bkg-color` and friends. If present and `inheritTheme` is on, use them. Otherwise use plugin settings.

### 6.4 Build

Vite, two entry points. `base` set to the plugin's asset URL at build time or patched at runtime via `__vite_public_path__`. Target ES2019 or later.

---

## 7. Hours JSON schema

```json
{
  "version": 1,
  "timezone": "America/New_York",
  "regular": {
    "mon": [["09:00", "12:00"], ["13:00", "17:00"]],
    "tue": [["09:00", "17:00"]],
    "wed": [["09:00", "17:00"]],
    "thu": [["09:00", "17:00"]],
    "fri": [["09:00", "15:00"]],
    "sat": [],
    "sun": []
  },
  "exceptions": [
    { "date": "2026-11-26", "closed": true, "label": "Thanksgiving" },
    { "date": "2026-12-24", "hours": [["09:00", "13:00"]], "label": "Christmas Eve" }
  ]
}
```

**Rules:**
- Interval arrays, not open/close pairs — supports lunch closures and split shifts
- Empty array = closed that day
- Exceptions override `regular` by date; this is the field clients use most and the one competitors forget
- `version` exists so the schema can migrate without breaking deployed sites
- Times are local to `timezone`, 24-hour, zero-padded

**Backend computes and injects into the prompt:**
- `is_open` (boolean)
- `closes_at` / `next_open_at` (human-readable local strings)
- A rendered plain-English hours summary

---

## 8. Backend spec

Not built in the first milestone, but the plugin must be written against this contract.

**Endpoints**
- `POST /v1/chat` — main conversation endpoint. Auth via `publicToken`, origin-checked against the tenant's registered domain.
- `POST /v1/session` — mint `publicToken` from site key (server-to-server, called by the plugin)
- `POST /v1/index` — trigger a crawl/reindex of the client's site
- `GET /v1/status` — connection check for the options page

**Per-request pipeline**
1. Validate token, resolve tenant, check origin
2. Check subscription status — inactive tenants get a degraded static response, no model call
3. Check monthly quota
4. Retrieve top-k chunks from the tenant's index
5. Compute hours state (§3.5)
6. Assemble system prompt: business identity + hours state + retrieved context
7. Call model, stream back
8. Log conversation, extract lead if the after-hours flow captured one

**Tenant model:** site key → domain allowlist, subscription status, plan/quota, knowledge index, hours config, prompt customizations.

---

## 9. Build order

1. **Plugin skeleton** — main file with correct CMB2 require, options page registering, activation/deactivation hooks, settings persisting
2. **Hours module** — CMB2 groups → JSON serialization → validation, with unit tests covering DST boundaries and split shifts
3. **REST config endpoint** — returns the §5.3 payload
4. **Widget build pipeline** — Vite two-entry config, launcher renders a bubble that dynamic-imports a stub, verified working on a subdirectory install
5. **RCB integration** — real widget, deterministic quick-reply flows only, no backend yet
6. **Backend MVP** — `/v1/chat` with a hardcoded tenant, hours computation, no retrieval
7. **PegasusProvider** — wire widget to backend, streaming working end to end
8. **Retrieval** — crawler, chunker, embeddings, top-k
9. **Tenancy + billing** — site keys, subscription gating, quotas, degraded mode
10. **Lead capture + notifications**

Milestones 1–5 are shippable as a non-AI FAQ widget, which is a useful de-risking checkpoint and a sellable product on its own.

---

## 10. Open questions

- Backend hosting and runtime — undecided
- Model provider — undecided; the provider abstraction means this is a late-binding choice
- Billing processor and how subscription state syncs to the tenant record
- Whether conversation logs surface in WP admin or only in a hosted dashboard
- Data retention policy and what to tell clients about it — matters for any client in a regulated vertical
