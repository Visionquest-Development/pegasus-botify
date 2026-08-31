# CLAUDE.md — pegasus-botify

Guidance for Claude Code when working in this plugin.

## What this is

`pegasus-botify` is a WordPress plugin — the delivery half of an AI chat-widget product
sold as a monthly subscription add-on to Pegasus client sites. It joins the existing
`pegasus-*` plugin suite (`pegasus-slider`, `pegasus-carousel`, …) and follows its
conventions (plugin header, GPL, Visionquest authorship).

**The authoritative spec is [`docs/pegasus-botify-spec.md`](docs/pegasus-botify-spec.md).**
Read it before making non-trivial changes. If anything here conflicts with the spec, the
spec wins — its decisions have documented reasons.

The full product is three parts: a **React widget** (lazy-loaded bundle), this **plugin**
(settings + config endpoint + asset enqueue), and a **multi-tenant backend** (holds model
keys, retrieval index, subscription state). **The backend is the product**; the plugin and
widget are the delivery mechanism.

## Current status

**Milestones 1–5 complete + AI via bring-your-own-key** (see the architecture-pivot note below).

## Architecture pivot: BYOK (bring-your-own-key), not a hosted backend

The original spec routed AI through a hosted multi-tenant backend the widget POSTs to. We
pivoted the **default** model to **bring-your-own-key**: the client sets *their own* Anthropic
API key in the plugin, and the plugin calls Claude **server-side from PHP** on the client's own
WordPress. The widget calls a **same-origin** endpoint (`/wp-json/pegasus-botify/v1/chat`), so
the key never leaves the client's server and never reaches the browser (§3.1). This removes the
need to host and gate a central service for the self-serve model.

- **Key custody:** `pegasus_botify_get_api_key()` prefers a `PEGASUS_BOTIFY_ANTHROPIC_KEY`
  wp-config constant, else the `anthropic_api_key` settings field. The key is used only in
  `includes/class-chat.php` and is never included in any REST response.
- **AI endpoint:** `includes/class-chat.php` — `POST /v1/chat`, nonce-gated + per-IP
  rate-limited, builds the system prompt (business identity + computed hours), calls the
  Anthropic Messages API via `wp_remote_post` (NOT the PHP SDK — avoids vendoring Guzzle into a
  distributed plugin), non-streaming, model from the `ai_model` setting (default
  `claude-haiku-4-5`). Deterministic Hours/Contact/quick replies still answer with no model call.
- **Settings:** Connection → Anthropic API key; Behavior → "Enable AI answers" toggle + model
  select. `pegasus_botify_ai_enabled()` gates on toggle + key present.
- **Knowledge base (FAQ):** a repeatable Question/Answer group (`knowledge_base`) plus a
  `help_url`, both in settings. `class-chat.php::build_system_prompt()` injects the Q&A as
  authoritative reference and instructs the model to defer to `help_url` when a question isn't
  covered. This is the manual precursor to the M8 crawler/retrieval index; keep an eye on
  prompt size as entries grow (`pegasus_botify_max_knowledge` caps how many are injected).
- **Config endpoint** now also returns `aiEnabled`, `chatUrl`, `nonce` (the widget uses these);
  `sessionEndpoint`/`publicToken` remain for the optional hosted tier.
- **Import/Export:** `includes/class-io.php` adds a "Botify Import / Export" admin page that
  exports all settings to JSON and imports them back (via `admin-post.php` handlers). Secrets
  and site-specific media (`Pegasus_Botify_IO::EXCLUDED_KEYS`) are never exported, and import
  MERGES into the existing option so it never clears the destination site's API key. Logic is
  in `export_data()` / `import_settings()` (unit-testable without the HTTP layer).

The **hosted backend** (`../../../../pegasus-botify-backend`, Node/TS) is **retained** for a
possible future **managed/subscription tier** (we host + hold one key + gate by subscription),
but is not used in BYOK mode. See that project's README and the earlier milestone notes.

This is the "shippable" checkpoint — deterministic FAQ works with no key; AI turns on the
moment a client adds their Anthropic key.

**M1 — plugin skeleton:**
- `pegasus-botify.php` — main file, constants, top-level CMB2 load, activation/deactivation
  hooks, `pegasus_botify_is_pegasus_active()` theme detection, i18n loading, `[pegasus_botify]`
  shortcode (renders the widget mount point only — widget not built yet).
- `includes/class-options.php` — CMB2 settings page (single menu item), all spec §5.2 fields.
- `inc/cmb2/` — bundled CMB2 v2.10.1 (copied from the parent Pegasus theme).

**M2 — Hours module:**
- `includes/class-hours.php` — **WordPress-independent** `Pegasus_Botify_Hours`: normalize/
  serialize CMB2 hours to the §7 JSON schema, `validate()`, and `get_state()` computing
  `is_open` / `closes_at` / `next_open_at` + a plain-English `summary()`, DST-correctly via
  `DateTimeZone` (spec §3.5, §3.6). Exceptions override regular hours by date; interval
  arrays support split shifts / lunch closures.
- `pegasus_botify_get_hours()` (in the main file) — thin WP-side adapter mapping CMB2 group
  rows → the normalized array the class consumes.
- `tests/HoursTest.php` — 20 pure-PHPUnit tests (split shifts, exception overrides, empty
  days, DST spring-forward/fall-back boundaries, validation). Run with `composer install`
  then `composer test` (or `vendor/bin/phpunit`). No WP bootstrap needed.

**M3 — REST config endpoint:**
- `includes/class-rest.php` — `GET /wp-json/pegasus-botify/v1/config` (public, `Cache-Control:
  max-age=60`). Returns the widget's render config in camelCase: `greeting`, `quickReplies`
  `[{label,type,value}]`, `appearance` `{inheritTheme,bubbleColor,headerColor,accentColor,
  position,avatar}`, `afterHoursAction`, `business` `{name,phone}`, computed `hours` state
  (reuses M2), `sessionEndpoint`, and `publicToken`.
- **Security invariant:** the response NEVER includes the site key or the internal
  notification/escalation emails. The `publicToken` is a short-lived, domain-scoped
  HS256-style token (`base64url(payload).base64url(HMAC-SHA256(payload, site_key))`) minted
  locally from the site key, so the backend can verify it without the key ever reaching the
  browser (§3.1, §5.3). Filters: `pegasus_botify_config`, `pegasus_botify_session_endpoint`,
  `pegasus_botify_public_token`, `pegasus_botify_public_token_ttl`. Backend base URL comes
  from the `PEGASUS_BOTIFY_API_BASE` constant (null until set — backend is M6).

**M4 — widget build pipeline (Vite, two-stage):**
- `src/launcher.ts` → `dist/launcher.js` — always enqueued, ~2.7 KB, **no React**. Reads the
  inline `#pegasus-botify-config` JSON, renders the floating bubble, and on first click
  `import()`s the widget chunk. `src/widget.tsx` → `dist/assets/widget-[hash].js` — the lazy
  React chunk (M4 stub; real chat UI is M5). `src/provider/PegasusProvider.ts` is a documented
  placeholder for M7. `src/config.ts` holds the shared TS types.
- `vite.config.ts` — single `launcher` entry; the dynamic `import()` auto-splits the widget
  (and its React) into a hashed chunk. `base: './'` → **relative** chunk URLs that resolve
  against `import.meta.url`, so lazy loading works on subdirectory installs (§3.4) without
  baking the plugin URL in at build time. Target ES2019.
- `includes/class-enqueue.php` — registers/enqueues the launcher as an ES module (`type=module`
  via `script_loader_tag`, `filemtime` cache-bust) and prints the bootstrap config JSON tag.
  Enqueue is on-demand via the `[pegasus_botify]` shortcode (what the theme footer toggle
  calls), or site-wide via the `pegasus_botify_should_enqueue` filter (default false).

**Build step:** the widget is committed pre-built in `dist/` (SiteGround deploys via `git pull`
with no build). After changing anything under `src/`, rebuild and commit `dist/`:

```bash
npm install       # one-time
npm run build     # emits dist/launcher.js + dist/assets/widget-*.js
npm run typecheck # optional: tsc --noEmit
```

**M5 — React ChatBotify + deterministic flows:**
- `src/widget.tsx` — the lazy chunk now mounts React ChatBotify (`react-chatbotify` v2, MIT).
  It fetches the full `RenderConfig` from `restUrl`, then builds an RCB `flow`/`settings`/
  `styles`. RCB renders its own floating button + window (opened by default) and the launcher
  retires its placeholder bubble once RCB mounts, so there's only ever one button.
- `src/flows.tsx` — the deterministic answer logic (§6.2). Quick-reply clicks and free text
  map to Hours (computed open/closed + next-open + weekly summary from the M2 state), Contact/
  phone, links, or canned text; anything unmatched gets a friendly no-backend fallback. **No
  model call at this milestone** — every answer is client-side, instant, and can't hallucinate.
- `src/brand.ts` — reads the theme's `--pegasus-*` CSS custom properties for accent colors when
  `inheritTheme` is on (§6.3), else uses the plugin's appearance settings, else defaults.
- **Styling isolation:** relies on RCB's own scoped `rcb-*` classes (RCB injects its CSS into
  the widget chunk — Vite auto-loads `dist/assets/widget-*.css` via the launcher's relative
  preload). Tested clean against the Pegasus theme. Full Shadow DOM isolation (§6.3's stronger
  option) is deferred; revisit if a host theme's globals ever bleed in.

Not yet built (later milestones): backend MVP `/v1/chat` (M6), PegasusProvider wiring the
widget to the backend with streaming (M7), retrieval (M8), tenancy/billing (M9), lead capture
(M10). See spec §9. The custom provider stub lives at `src/provider/PegasusProvider.ts`.

## Running the PHP tests

```bash
composer install          # one-time: pulls PHPUnit as a dev dependency
composer test             # or: vendor/bin/phpunit
```

The Hours class has zero WP dependencies, so the suite runs standalone (no DB, no WordPress).
`vendor/`, `composer.lock`, and the PHPUnit caches are gitignored.

## Hard constraints — do not violate (spec §3)

These are correctness requirements, not style preferences:

1. **No model API key ever reaches the browser** (§3.1). The widget talks only to our
   backend via a custom provider. Never call a model API from client-side JS.
2. **CMB2 loads unconditionally at the top level of `pegasus-botify.php`** (§3.2) — never
   inside a `class_exists()` guard, never hooked to `init`. Doing either breaks CMB2's
   version arbitration and can silently downgrade the library site-wide. The only guard
   allowed is the `file_exists()` check already there.
3. **Bundle our own React** in the widget chunk (§3.3) — do not alias to WordPress core's
   `react`/`wp-element` handles.
4. **Lazy-load the widget on bubble click** (§3.4).
5. **The model never does date math** (§3.5). Compute `is_open`/`closes_at`/`next_open_at`
   server-side and inject them as plain values.
6. **Store IANA timezone names, never fixed offsets** (§3.6).
7. **GPLv2+** (§3.7) — the code is freely redistributable; the subscription is enforced at
   the backend, not by hiding code.

## Local development

Runs inside the VVV Vagrant box (repo root: `/home/jim/Projects/vagrant-local`).

```bash
vagrant up      # start VM
vagrant ssh     # WP-CLI, DB access
```

Local URL: `http://visionquest.test`. No build step for PHP — edits are served directly.
(The widget, once added, will have a Vite build under `src/` → `dist/`.)

The parent `pegasus` theme is active locally, so the settings page appears as a **submenu
under "Pegasus Options"** rather than as a top-level menu.
