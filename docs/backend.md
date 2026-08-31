# The hosted / subscription backend (the "middleman")

This document explains the optional hosted model: what the middleman backend is, why it
exists, how it works when turned on, and exactly what is and isn't built yet.

> **TL;DR of current status.** The backend is a working **MVP** (a separate Node/TypeScript
> project at `../pegasus-botify-backend`), and the plugin already mints and exposes the tokens
> it needs. It is **not the default** — the shipping widget uses the BYOK same-origin path.
> Turning it on fully still needs one code step (point the widget at the backend) plus real
> tenancy/billing, which are not built. See [What's built vs planned](#whats-built-vs-planned).

## Why a separate backend exists

The plugin is GPL and runs on the client's server; the widget runs in every visitor's
browser. Both are readable/copyable. So two things **cannot** live there:

1. **Your Anthropic API key** — if it were in the plugin, it would sit on every client site
   and leak. It must live somewhere only you control.
2. **Subscription enforcement** — anyone can copy the GPL plugin, so the thing that actually
   unlocks the AI must be a server you control that can refuse to answer for a lapsed
   subscription. Copyable code doesn't matter when the key + gating are server-side.

The backend is that server. It is **multi-tenant**: one deployment serves all your client
sites. (The alternative — [BYOK](architecture.md#byok-bring-your-own-key--the-default-live-path) —
skips the backend entirely by having each client bring their own key; you don't run
infrastructure, but there's no recurring subscription to sell.)

## How it works when turned on

### The token flow (no key in the browser)

1. The plugin holds a **site key** — a shared secret you issue per client site (set in
   **Pegasus Options → Botify → Site key**, and stored on the backend for that tenant).
2. On each config request, the plugin **mints a `publicToken`**: `base64url(json{domain,
   exp})` + "." + `base64url(HMAC-SHA256(body, siteKey))`. Short-lived (default 1h),
   domain-scoped, and useless on its own — it can't call Anthropic and only *your* backend
   accepts it.
3. The widget sends the `publicToken` to the backend. The backend **verifies** it with the
   tenant's site key, so the site key itself never travels to the browser.

This is the key point: a leaked `publicToken` exposes one domain for one hour; the real
secret (the site key, and the model key) never leaves your server.

### Per-request pipeline (`POST /v1/chat`)

```
1. Validate the publicToken (HMAC) and decode { domain, exp }
2. Resolve the tenant by domain
3. Check the request Origin against the tenant's domain allowlist
4. Check subscription — if inactive, return a degraded static reply (NO model call)
5. Compute hours state (server-side, DST-correct)
6. Assemble the system prompt: business identity + hours + (later) retrieved knowledge
7. Call the model with YOUR key, stream the reply back (SSE)
8. (planned) Log the conversation; extract a lead if the after-hours flow captured one
```

Steps 1–7 are implemented in the MVP; step 8 is planned.

### Endpoints

| Endpoint | Status | Purpose |
|---|---|---|
| `POST /v1/chat` | ✅ MVP | Main conversation. Auth via `publicToken`, origin-checked, streams the reply (SSE). |
| `GET /v1/status` | ✅ MVP | Health/connection check (for the options page's connection status). |
| `POST /v1/session` | 🚧 planned | Mint a `publicToken` from the site key server-to-server (an alternative to the plugin minting it locally). |
| `POST /v1/index` | 🚧 planned | Trigger a crawl/reindex of the client's site for retrieval. |

### Tenancy model

A **tenant** = one client site. In the MVP it's a single hardcoded record
(`src/tenants.ts`); productionizing it means a real store keyed by site key. Each tenant
carries:

- `siteKey` — the shared secret used to verify tokens (never leaves the server).
- `domains[]` — the Origin allowlist.
- `business` — name/description/phone for the prompt.
- `hours` — the hours config (timezone/regular/exceptions).
- `subscriptionActive` — the gate. Inactive tenants get a degraded static reply, no model
  call. (Wiring this to a billing processor — Stripe, etc. — is future work; there is **no
  billing/subscription page built yet**, just the boolean gate.)

## Turning it on

1. **Deploy the backend.** It's a small Node service; run it serverless (Lambda/Cloudflare
   Workers/Vercel — scales to zero) or on a tiny always-on host (Fly.io/Render/a VPS). It
   needs `ANTHROPIC_API_KEY` in its environment. See the backend project's `README.md` and
   `.env.example`.
2. **Register the tenant** on the backend: site key, domain allowlist, business info, hours,
   `subscriptionActive: true`.
3. **Configure the WordPress site:**
   - Set the same **Site key** in Pegasus Options → Botify.
   - Define `PEGASUS_BOTIFY_API_BASE` (your backend base URL) in `wp-config.php`. This makes
     the config endpoint return `sessionEndpoint` = `<base>/v1/chat` and a valid
     `publicToken`.
4. **Wire the widget (the remaining code step).** Today the widget's AI call
   (`src/flows.tsx` → `callAI`) always posts to the same-origin `chatUrl`. To use the hosted
   backend, implement `src/provider/PegasusProvider.ts` (a documented stub) and have the
   widget post to `sessionEndpoint` with the `publicToken` when they're present, falling back
   to `chatUrl` otherwise. Then rebuild the widget.

## Local development

The backend runs locally against your VVV WordPress:

```bash
cd ../pegasus-botify-backend
npm install
cp .env.example .env        # set values, or rely on an exported ANTHROPIC_API_KEY
npm run dev                 # http://localhost:8787

# mint a token and test /v1/chat end-to-end:
TOKEN=$(npm run -s mint-token -- visionquest.test)
curl -N -X POST http://localhost:8787/v1/chat \
  -H 'Content-Type: application/json' \
  -d "{\"publicToken\":\"$TOKEN\",\"messages\":[{\"role\":\"user\",\"content\":\"Are you open?\"}]}"
```

The test tenant's site key (`PEGASUS_BOTIFY_TEST_SITE_KEY`, default `SK_visionquest_local_dev`)
must match the **Site key** set in the plugin for the plugin-minted token to verify.

## What's built vs planned

**Built (MVP, verified end-to-end):** the Node service; `POST /v1/chat` with token
verification, tenant resolution, origin check, subscription gate, server-side hours
computation, and real streaming model calls; `GET /v1/status`; the plugin's token minting and
`sessionEndpoint`/`publicToken` config fields.

**Planned:** wiring the widget to the backend (PegasusProvider), `POST /v1/session`,
retrieval (`POST /v1/index` + crawler/embeddings), a real multi-tenant store, quotas, a
billing processor + subscription sync (there is no subscription/billing page yet — only the
`subscriptionActive` flag), conversation logging, and lead capture.

## BYOK vs hosted at a glance

| | BYOK (default, live) | Hosted / subscription (this doc) |
|---|---|---|
| API key location | Client's WordPress server | Your backend |
| Who Anthropic bills | The client | You (client pays you a subscription) |
| Infra you run | None | The backend (cheap serverless or a small host) |
| Enforcement on non-payment | n/a (client owns it) | Backend refuses to answer |
| Widget target | Same-origin `chatUrl` | Your `sessionEndpoint` (+ `publicToken`) |
| Status | Fully wired | Backend MVP built; widget wiring pending |
