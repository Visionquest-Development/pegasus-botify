# Settings reference

Every field on **Pegasus Options → Botify**, what it does, and how it's used. Fields are
stored in one option array (`pegasus_botify_options`). Sections are collapsible; repeatable
groups start collapsed.

Fields marked **(reserved)** are saved but not acted on yet — they belong to milestones not
finished (lead capture, hosted backend).

---

## Connection

| Field | Type | What it does |
|---|---|---|
| **Site key** | text | *(Optional.)* Only used by the hosted/subscription backend (see [backend.md](backend.md)). Leave blank in BYOK mode. It's the shared secret the plugin uses to mint the `publicToken`. |
| **Anthropic API key** | text | Your Anthropic API key. Powers AI answers; **used server-side only**, never sent to the browser. Get one at console.anthropic.com. Stored in the site database. **More secure alternative:** define `PEGASUS_BOTIFY_ANTHROPIC_KEY` in `wp-config.php` — then this field is ignored and the key never touches the DB. |

---

## Business identity

Feeds the AI's system prompt and (later) lead routing.

| Field | Type | What it does |
|---|---|---|
| **Business name** | text | Shown as the chat window title and used in the AI's system prompt. Falls back to the site title. |
| **What we do** | textarea | A short description of the business/services. **This is the AI's main knowledge about what you offer** — fill it in or the AI won't know your services. Injected into the prompt. |
| **Contact email for lead notifications** | email | **(reserved)** Where captured leads will be sent (lead capture is a later milestone). Never exposed to visitors. |
| **Phone number** | text | Public. Shown by the "Contact" quick reply and offered by the AI when it can't help. |

---

## Hours

Drives both the deterministic "Hours" answer and the open/closed status in the AI prompt. All
times are local to the selected timezone; open/closed is computed server-side (DST-correct).

| Field | Type | What it does |
|---|---|---|
| **Timezone** | select (IANA) | e.g. `America/New_York`. Stored as a name, never a fixed offset, so DST is handled correctly. |
| **Regular hours** | repeatable group | One row per open block: **Day**, **Opens**, **Closes**. Add **multiple rows for the same day** to model split shifts / lunch closures (e.g. Mon 9–12 and Mon 1–5). A day with no row = closed. |
| **Exceptions** | repeatable group | Date-specific overrides: **Date**, **Closed all day**, optional **Opens/Closes**, **Label** (e.g. "Thanksgiving"). An exception overrides the weekly schedule for that date. |

---

## Behavior

| Field | Type | What it does |
|---|---|---|
| **Enable AI answers** | checkbox | When on **and** an API key is set, free-text questions that don't match a quick reply are answered by Claude. Off = a quick-reply-only FAQ widget (no model calls at all). |
| **AI model** | select | Which Claude model answers: **Haiku 4.5** (cheapest/fastest, default), **Sonnet 5** (balanced), **Opus 4.8** (most capable, priciest). The client pays Anthropic per message. |
| **Greeting message** | textarea | The first message the bot shows when the window opens. |
| **Quick-reply buttons** | repeatable group | Buttons shown under the greeting: **Button label**, **Response type** (Show hours / Show contact info / Open a link / Send a canned message), **Response value** (the URL for a link, or the text for a canned message). These are answered deterministically — no model call. |
| **After-hours action** | select | **(reserved)** Intended behavior when closed (capture a lead / show hours / show hours + contact form). Returned in the config; not yet acted on by the widget. |
| **Escalation email** | email | **(reserved)** Where escalations/leads are sent (later milestone). Not exposed to visitors. |

---

## Knowledge base (FAQ)

The AI's authoritative Q&A. Injected into the prompt so answers stay accurate and on-brand.

| Field | Type | What it does |
|---|---|---|
| **When the AI can't answer, send users here** | URL | Optional. When a question isn't covered by the knowledge base and the AI is unsure, it directs the user to this URL (e.g. your docs site). |
| **Knowledge base** | repeatable group | **Question / topic** + **Answer** pairs. Keep answers short and factual. All entries (up to `pegasus_botify_max_knowledge`, default 40) are injected into every AI request, so watch prompt size as this grows — large sites want the crawler/retrieval index (a later milestone) instead. |

---

## Appearance

| Field | Type | What it does |
|---|---|---|
| **Inherit Pegasus theme colors** | checkbox | When on (default when a Pegasus theme is active), the widget reads the theme's `--pegasus-*` CSS custom properties for its accent colors. Turn off to use the colors below. |
| **Bubble color** | color | The launcher bubble color (used when not inheriting). |
| **Header color** | color | The chat window header color. |
| **Accent color** | color | The primary accent (buttons, user bubbles). |
| **Bubble position** | select | `bottom-right` or `bottom-left`. |
| **Avatar image** | file | Optional bot avatar shown in the chat header. |

---

## Where the widget shows up (placement)

- **Shortcode:** put `[pegasus_botify]` on any page — it enqueues the launcher (a floating
  bubble appears; the shortcode itself outputs no visible markup).
- **Site-wide (theme toggle):** if the parent Pegasus theme edits are deployed, **Pegasus
  Options → Pegasus Botify (Chatbot) → Enable Botify Chatbot Site-wide** injects it in the
  footer everywhere. That toggle only appears when the plugin is active.
- **Site-wide (plugin filter):** `add_filter('pegasus_botify_should_enqueue', '__return_true')`
  loads it on every front-end page without touching the theme.

---

## wp-config constants

| Constant | Effect |
|---|---|
| `PEGASUS_BOTIFY_ANTHROPIC_KEY` | Supplies the Anthropic key without storing it in the database (preferred). Overrides the settings field. |
| `PEGASUS_BOTIFY_API_BASE` | Sets the hosted backend base URL, which makes the config endpoint return a `sessionEndpoint` (hosted mode). See [backend.md](backend.md). |

## Filters (for developers)

Placement: `pegasus_botify_should_enqueue`. Config/backend: `pegasus_botify_config`,
`pegasus_botify_session_endpoint`, `pegasus_botify_public_token`,
`pegasus_botify_public_token_ttl`. Chat limits: `pegasus_botify_max_tokens`,
`pegasus_botify_request_timeout`, `pegasus_botify_rate_limit`, `pegasus_botify_rate_window`,
`pegasus_botify_message_max_chars`, `pegasus_botify_max_history`,
`pegasus_botify_max_knowledge`.
