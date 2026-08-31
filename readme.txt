=== Pegasus Botify ===
Contributors: visionquestdevelopment
Author: Jim O'Brien
Author URI: https://visionquestdevelopment.com/
Tags: chat, chatbot, ai, support, leads
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered chat widget for Pegasus sites — answers questions 24/7, states office hours, and captures leads after hours.

== Description ==

Pegasus Botify is the WordPress delivery half of an AI chat-widget product sold as a
monthly subscription add-on to Pegasus client sites. The plugin provides the settings
UI, a public config endpoint, and asset enqueue; model credentials, the knowledge base,
and subscription state live on a separate multi-tenant backend.

Part of the `pegasus-*` plugin suite by Visionquest Development.

= Current status =

Milestone 1 of 10 — settings skeleton. This build ships the plugin skeleton: a CMB2
settings page and a `[pegasus_botify]` shortcode that renders the widget's mount point.
The chat widget and backend are not wired up yet, so the plugin does not yet chat.
See docs/pegasus-botify-spec.md for the full build order and architecture.

== Installation ==

1. Upload the `pegasus-botify` folder to `/wp-content/plugins/`.
2. Activate **Pegasus Botify** through the Plugins menu.
3. Configure under **Pegasus Options -> Botify** (or the top-level **Botify** menu on a
   non-Pegasus theme).

== Usage ==

Place the shortcode on any page to render the widget mount point:

`[pegasus_botify]`

On Pegasus themes you can instead enable the widget site-wide from
**Pegasus Options -> Pegasus Botify (Chatbot)**.

== Changelog ==

= 0.8.0 =
* Settings import/export. A new "Botify Import / Export" page (under Pegasus Options) exports
  all Botify settings — knowledge base, hours, exceptions, business info, behavior, appearance
  — to a JSON file, and imports them on another site. Secrets (Anthropic API key, site key)
  and site-specific media are never exported; import MERGES, so it never wipes the API key you
  set on the destination site. Configure locally, move to production without re-entering fields.
* Settings UX: repeatable groups (hours, exceptions, quick replies, knowledge base) now start
  collapsed, and each settings section (Connection, Business identity, Hours, …) is collapsible
  with its open/closed state remembered per section.

= 0.7.0 =
* Knowledge base (FAQ). A new Knowledge base section in the settings lets you add Question/
  Answer pairs the AI answers from, plus a "when unsure, send users here" help URL. The Q&A
  and URL are injected into the AI's prompt so answers stay accurate and on-brand; anything
  not covered is deferred to the help URL. (For large sites, a crawler/retrieval index is
  planned; this is the manual precursor.) Also: prefetch the widget on idle so the first
  open is instant, and rename React ChatBotify's `rcb-` CSS/markup prefix to `pegasus-rcb-`
  to prevent style collisions on the host site.

= 0.6.0 =
* Bring-your-own-key AI answers. Free-text questions that don't match a quick reply are now
  answered by Claude, called server-side from the plugin using the site's own Anthropic API
  key (set via the settings field or the PEGASUS_BOTIFY_ANTHROPIC_KEY wp-config constant).
  The key stays on the server and is never sent to the browser. Adds a same-origin
  /wp-json/pegasus-botify/v1/chat endpoint (nonce-gated, rate-limited), an "Enable AI answers"
  toggle, and a model selector (default Claude Haiku 4.5). Deterministic Hours/Contact/quick
  replies still answer instantly with no model call. The hosted multi-tenant backend remains
  available for a future managed/subscription tier.

= 0.5.0 =
* Working non-AI FAQ widget (Milestone 5): the lazy chunk now mounts React ChatBotify with
  deterministic quick-reply flows — Hours (computed open/closed + next-open + weekly summary),
  Contact/phone, links, and canned answers, all resolved client-side with no model call.
  Free text gets a friendly fallback. Brand colors inherit the theme's --pegasus-* custom
  properties when enabled. Milestones 1–5 together are a sellable non-AI FAQ widget.

= 0.4.0 =
* Widget build pipeline (Milestone 4): Vite two-stage build. A tiny always-loaded launcher
  (~2.7 KB, no React) renders the chat bubble and lazy-imports the widget chunk (React) only
  on click. Relative dynamic imports keep it working on subdirectory installs. Adds
  includes/class-enqueue.php (module enqueue + JSON bootstrap config) and a React stub widget.
  Requires a build: `npm install && npm run build`.

= 0.3.0 =
* REST config endpoint (Milestone 3): GET /wp-json/pegasus-botify/v1/config returns the
  widget's render config (greeting, quick replies, appearance, after-hours action, business
  name/phone, computed hours state) plus a short-lived, domain-scoped publicToken minted
  from the site key. The site key and internal notification emails are never exposed.

= 0.2.0 =
* Hours module (Milestone 2): serialize CMB2 hours to the schema, validation, and DST-safe
  is_open / closes_at / next_open_at computation with a plain-English summary. Supports split
  shifts and date exceptions. Covered by 20 standalone PHPUnit tests.

= 0.1.0 =
* Initial skeleton: CMB2 settings page, activation/deactivation hooks, `[pegasus_botify]`
  shortcode mount point, bundled CMB2 2.10.1, i18n loading.
