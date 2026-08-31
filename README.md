# pegasus-botify

AI chat widget for Pegasus WordPress sites. It answers visitor questions from a
settings-managed knowledge base, states business hours accurately, and offers deterministic
quick replies (hours, contact) with no model call. Part of the `pegasus-*` plugin suite by
[Visionquest Development](https://visionquestdevelopment.com/).

## Two ways to run it

The plugin supports two operating models. **Bring-your-own-key is the default and the only
one wired end-to-end today**; the hosted/subscription model is built as a separate service
and scaffolded in the plugin (see [docs/backend.md](docs/backend.md)).

| Model | Who holds the API key | Who is billed | Needs a server? | Status |
|---|---|---|---|---|
| **Bring-your-own-key (BYOK)** | The client (their key, on their WordPress) | The client, by Anthropic | No | ✅ Live |
| **Hosted / subscription (middleman)** | You (one key on your backend) | The client pays *you* a subscription | Yes (the Node backend) | 🚧 Backend MVP built; widget wiring pending |

In **BYOK**, the widget calls a same-origin endpoint on the client's own site and the plugin
calls Claude server-side with the client's key — the key never reaches the browser. In the
**hosted** model, the widget talks to your backend, which holds the key and enforces the
subscription. See [docs/architecture.md](docs/architecture.md) for the request flows.

## Quick start (BYOK)

1. Install and activate the plugin (**Plugins → Add New → Upload**, or `git pull` on
   SiteGround). It joins the Pegasus Options menu when a Pegasus theme is active.
2. Go to **Pegasus Options → Botify** and set:
   - **Anthropic API key** (or define `PEGASUS_BOTIFY_ANTHROPIC_KEY` in `wp-config.php`).
   - Business identity, hours, quick replies, and a few knowledge-base Q&As.
3. Show the widget: add the `[pegasus_botify]` shortcode to a page, or enable it site-wide
   from the theme's Pegasus Options footer toggle.

Deterministic answers (hours, contact) work with no key. AI answers turn on the moment a key
is present and **Enable AI answers** is on.

## Build (only if you change the widget under `src/`)

The widget is committed pre-built in `dist/` (SiteGround deploys via `git pull`, no build
step). After editing `src/`:

```bash
npm install
npm run build       # emits dist/launcher.js + dist/assets/widget-*.js|css
npm run typecheck   # optional
```

## Tests

The hours logic is WordPress-independent and unit-tested:

```bash
composer install
composer test        # PHPUnit; no WordPress bootstrap needed
```

## Documentation

- **[docs/architecture.md](docs/architecture.md)** — how the whole system works, both
  operating models, request flows, and the security model.
- **[docs/files.md](docs/files.md)** — every file in the plugin, widget, and backend, and
  what it does.
- **[docs/settings.md](docs/settings.md)** — every settings field and exactly what it does.
- **[docs/backend.md](docs/backend.md)** — the hosted / subscription "middleman" backend:
  what it is, when to turn it on, endpoints, the token flow, and tenancy.
- **[docs/pegasus-botify-spec.md](docs/pegasus-botify-spec.md)** — the original build spec
  (historical; some decisions have since changed, e.g. the BYOK pivot).
- **[CLAUDE.md](CLAUDE.md)** — orientation for AI coding assistants working in this repo.

## License

GPLv2 or later — https://www.gnu.org/licenses/gpl-2.0.html
