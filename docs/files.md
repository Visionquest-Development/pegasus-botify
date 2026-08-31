# File reference

Every meaningful file in the plugin, the widget source, and the optional backend, and what it
does.

## Plugin (PHP) — `pegasus-botify/`

| File | Responsibility |
|---|---|
| `pegasus-botify.php` | Main plugin file. Defines constants (`PEGASUS_BOTIFY_VERSION`, `_PATH`, `_URL`, `_OPTION_KEY`), loads CMB2 **unconditionally at the top level** (required for CMB2's version arbitration), `require`s the `includes/` classes, and bootstraps them on `plugins_loaded`. Also holds the shared helpers: `pegasus_botify_is_pegasus_active()`, `pegasus_botify_get_hours()` (CMB2 → Hours adapter), `pegasus_botify_get_api_key()`, `pegasus_botify_ai_enabled()`, the `[pegasus_botify]` shortcode, activation/deactivation hooks, and i18n loading. |
| `includes/class-options.php` | The CMB2 settings page (`Pegasus_Botify_Options`). Registers a single admin menu item (submenu under **Pegasus Options** when a Pegasus theme is active, else a top-level menu — at priority 11 so the parent menu exists first) and all settings fields. Enqueues the collapsible-sections admin JS/CSS on this page only. See [settings.md](settings.md). |
| `includes/class-hours.php` | `Pegasus_Botify_Hours` — **WordPress-independent** pure PHP. Normalizes/serializes hours to the JSON schema, `validate()`s it, and `get_state()` computes `is_open`/`closes_at`/`next_open_at` + a plain-English `summary()`, DST-correct via `DateTimeZone`. Unit-tested. |
| `includes/class-rest.php` | `Pegasus_Botify_REST` — the public config endpoint `GET /wp-json/pegasus-botify/v1/config`. Returns everything the widget needs to render (greeting, quick replies, appearance, hours state, `aiEnabled`, `chatUrl`, `nonce`) plus the hosted-mode fields (`sessionEndpoint`, `publicToken`). **Never** returns the API key. Also mints the `publicToken` (HMAC of `{domain, exp}` from the site key). |
| `includes/class-enqueue.php` | `Pegasus_Botify_Enqueue` — front-end asset loading. Registers/enqueues the launcher as an ES module (with a `filemtime` cache-bust), prints the inline `#pegasus-botify-config` JSON bootstrap tag, and exposes the `pegasus_botify_should_enqueue` filter for site-wide loading. |
| `includes/class-chat.php` | `Pegasus_Botify_Chat` — the **BYOK** AI endpoint `POST /wp-json/pegasus-botify/v1/chat`. Nonce-gated + per-IP rate-limited. Builds the system prompt (business + hours + knowledge base), calls the Anthropic Messages API via `wp_remote_post` using the client's key, and returns `{ reply }`. Non-streaming. |
| `includes/class-io.php` | `Pegasus_Botify_IO` — the **Botify Import / Export** admin page. Exports all settings to JSON and imports them back (merge). Excludes secrets/media (`EXCLUDED_KEYS`). Logic split into `export_data()` / `import_settings()` so it's testable without the HTTP layer. |
| `inc/cmb2/` | Bundled [CMB2](https://cmb2.io/) library (v2.10.1), copied from the parent Pegasus theme. Powers the settings fields. |
| `css/admin.css` | Admin styles, incl. the collapsible section headers/caret. |
| `js/admin-options.js` | Turns each settings section title into a collapsible toggle (state persisted in `localStorage`). UI-only. |
| `languages/` | i18n target directory (`load_plugin_textdomain`). |
| `readme.txt` | WordPress-format readme + changelog. |
| `CLAUDE.md` | Orientation for AI coding assistants. |

## Widget (TypeScript, built with Vite) — `src/` → `dist/`

| File | Responsibility |
|---|---|
| `src/launcher.ts` | Stage-1 launcher. Framework-free. Renders the bubble, reads the inline config, **prefetches** the widget chunk + config on idle/hover, and mounts the widget on click. |
| `src/widget.tsx` | Stage-2 lazy entry. Mounts React ChatBotify, builds the RCB flow/settings/styles, and routes each turn to deterministic vs AI answers. Uses the prefetched config so the window opens instantly. |
| `src/flows.tsx` | The deterministic answer logic (hours/contact/quick replies), the `callAI()` fetch to the chat endpoint, and small helpers (`hasDeterministicAnswer`, `answerToText`). |
| `src/brand.ts` | Reads the theme's `--pegasus-*` CSS custom properties for the widget's accent colors when "inherit theme" is on. |
| `src/config.ts` | Shared TypeScript types for the bootstrap config and the full render config. |
| `src/provider/PegasusProvider.ts` | Documented stub for the hosted-mode provider that would POST to the backend `sessionEndpoint` with the `publicToken`. Not yet wired. |
| `dist/launcher.js` | Built launcher (committed — deployed as-is). |
| `dist/assets/widget-*.js`, `*.css` | Built lazy chunk + its styles (committed). |
| `vite.config.ts` | Two-stage build config; relative `base`; the `rcbPrefix()` plugin that rewrites RCB's `rcb-` prefix to `pegasus-rcb-`. |
| `package.json`, `tsconfig.json` | Node/TS project + build scripts. |

## Tests & tooling

| File | Responsibility |
|---|---|
| `tests/HoursTest.php` | PHPUnit tests for `Pegasus_Botify_Hours` (split shifts, exceptions, DST boundaries, validation). |
| `tests/bootstrap.php` | Test bootstrap (loads the pure Hours class, no WordPress). |
| `phpunit.xml`, `composer.json` | PHPUnit config + the dev dependency. |

## Backend (Node/TypeScript) — separate project `pegasus-botify-backend/`

Only used in the hosted/subscription model. See [backend.md](backend.md) for detail.

| File | Responsibility |
|---|---|
| `src/server.ts` | HTTP server: CORS, routing, SSE. Implements `POST /v1/chat` (verify token → resolve tenant → check origin → check subscription → compute hours → build prompt → stream model) and `GET /v1/status`. |
| `src/tenants.ts` | Tenant registry (one hardcoded tenant in the MVP): site key, domain allowlist, business identity, hours, subscription flag. |
| `src/token.ts` | Verifies the plugin-minted `publicToken` (HMAC) against a tenant's site key. |
| `src/hours.ts` | TypeScript port of the plugin's hours computation (DST-safe). |
| `src/prompt.ts` | System-prompt assembly (business identity + hours state). |
| `src/claude.ts` | Anthropic streaming client (holds the server-side key). |
| `src/mint-dev-token.ts` | Dev helper to mint a token for curl tests. |
| `.env.example` | Backend configuration template (model, port, test site key). |
