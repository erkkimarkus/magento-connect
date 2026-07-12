# Smaily Connect for Magento 2 — User Guide

Smaily Connect keeps your newsletter audience, marketing automations and
(optionally) Smaily Campaign Intelligence in sync with your Magento store.

- [Installation](#installation)
- [Finding your way around](#finding-your-way-around)
- [Connecting your Smaily account](#connecting-your-smaily-account)
- [Subscriber synchronization](#subscriber-synchronization)
- [Automations](#automations)
- [Abandoned cart](#abandoned-cart)
- [Product RSS feed](#product-rss-feed)
- [Campaign Intelligence](#campaign-intelligence)
- [Historical import (backfill)](#historical-import-backfill)
- [The log and troubleshooting](#the-log-and-troubleshooting)
- [Privacy and GDPR](#privacy-and-gdpr)
- [CLI reference](#cli-reference)
- [FAQ](#faq)

---

## Installation

### Composer (recommended)

```bash
composer require smaily/smailyformagento
bin/magento module:enable Smaily_Connect
bin/magento setup:upgrade
bin/magento setup:di:compile   # production mode
bin/magento cache:flush
```

### Manual

Extract the release ZIP to `app/code/Smaily/Connect` and run the same
`module:enable` / `setup:upgrade` steps.

### Requirements

- Magento Open Source / Adobe Commerce **2.4.4+** or Mage-OS
- PHP **8.1 – 8.4**
- A working Magento cron. The module runs its jobs in a dedicated
  `smaily_connect` cron group; for near-real-time delivery make sure
  `bin/magento cron:run` executes **every minute** on the server.

### Upgrading from Smaily for Magento 2.8.x

Just update the package and run `bin/magento setup:upgrade` — see
[UPGRADING.md](UPGRADING.md) for exactly what is migrated and what changed.

---

## Finding your way around

Everything lives under **Marketing > Smaily Connect**, four pages:

| Page | What it is |
|---|---|
| **Dashboard** | The landing page: a one-sentence health verdict, connection status for Smaily / Campaign Intelligence / browse tracking, operational counters (deliveries, failures) and the latest queue activity. Every number is a real local queue query. |
| **Setup Wizard** | The guided five-step onboarding. On a fresh install every Smaily Connect page brings you here until setup is completed; you can re-run it any time — your settings are kept. |
| **Settings** | The wizard's content as always-available tabs — Connection, Subscribers, Automations, Intelligence, RSS. Each tab saves instantly via AJAX into the same configuration the wizard and Stores > Configuration edit. Tabs are deep-linkable (`?tab=rss`). |
| **Log** | One unified delivery log for both Smaily and Campaign Intelligence, with mass retry for failed rows. |

Advanced fields (multilingual mode, abandoned-cart product fields, logging
verbosity) and per-website / per-store-view overrides live in
**Stores > Configuration > Smaily > Smaily Connect** as before — the
Settings page and the wizard are views over that same configuration.

After a major version upgrade the module posts a one-time admin
notification suggesting a settings review — nothing is changed or blocked.

---

## Connecting your Smaily account

The fastest path is the guided wizard: **Marketing > Smaily Connect >
Setup Wizard** — five steps (Connect, Subscribers, Automations,
Intelligence, Done), each saved separately, with connection testing and
live workflow lists built in. Completed steps stay unlocked in the step
bar, so you can move back and forward between them freely — also when
revisiting the wizard after finishing it. Everything the wizard writes
lands in the regular configuration, so you can fine-tune it later on the
**Settings** page or at
**Stores > Configuration > Smaily > Smaily Connect > API Connection**

| Field | Notes |
|---|---|
| Subdomain | Your Smaily subdomain. Pasting the full URL (`https://demo.sendsmaily.net`) also works — it is normalized on save. |
| API Username / Password | Create these in Smaily under *Preferences > API*. The password is stored encrypted. |
| Multilingual Mode | See [Multilingual stores](#multilingual-stores). |

Use the **Test Connection** button next to the fields for instant feedback
on the credentials as typed — no save needed; a successful test also
refreshes the automation workflow dropdowns. Saving always succeeds; if the
saved credentials are wrong you get a clear warning instead of a blocked
save.

Credentials can be set per **website**, or per **store view** when each
language uses its own Smaily account (multilingual mode "Per-language
Smaily accounts").



### Multilingual stores

A store view's language is derived from its locale (`et_EE` → `et`). Four
routing modes (API Connection > Multilingual Mode):

| Mode | Meaning |
|---|---|
| Single language | One workflow per trigger (default). |
| Per-language Smaily accounts | Each store view has its own Smaily account — override the API credentials at store view scope. |
| One account, per-language workflows | One Smaily account; each language maps to its own workflow (rows in the automation mapping table; defaults are seeded by the 2.8.x migration). |
| One workflow branching by language | One workflow; the language split happens inside Smaily. The `language` field is sent with every contact. |

---

## Subscriber synchronization

**Stores > Configuration > Smaily > Smaily Connect > Subscriber Synchronization**

Subscribers sync in near-real-time through a durable queue (no lost events
if Smaily is briefly unreachable — deliveries retry with backoff).

### Contact sync mode (lawful basis)

| Mode | Who is synced | Smaily unsubscribes mirror back? |
|---|---|---|
| **Subscribers only (consent)** — default | Only opted-in newsletter subscribers | **Yes** — a contact who unsubscribes in Smaily is unsubscribed in Magento too (and vice versa) |
| All customers (legitimate interest) | Every registered customer; `is_unsubscribed` is omitted so Smaily manages suppression | No |
| Checkout opt-in only | Nobody automatically — only shoppers who tick the checkout newsletter checkbox | No |

Additional options:

- **Synchronized Fields** — which optional fields ride along (first/last
  name, prefix, gender, date of birth, customer ID, customer group,
  subscription type). Empty values are omitted so existing Smaily values
  are never wiped.
- **Include Guest Order Emails** — also sync guest-order emails (always on
  in checkout opt-in mode).
- **Show Newsletter Checkbox At Checkout** — adds an opt-in checkbox to the
  checkout payment step; ticking it creates a real Magento newsletter
  subscriber (double opt-in is honoured if your store requires
  confirmation).
- **Let Smaily Send Opt-In Emails** — suppresses Magento's own confirmation
  success/unsubscribe emails so your Smaily automations own that
  communication. The double-opt-in confirmation *request* email is never
  suppressed.

---

## Automations

**Stores > Configuration > Smaily > Smaily Connect > Automations**

Map Smaily automation workflows (the dropdowns load live from your account)
to store events:

- **Welcome** — fires when someone becomes a subscriber.
- **First Order** — fires on a customer's first order, with
  `order_id`, `order_total`, `order_currency`, `is_first_order` fields for
  template personalization.
- **Abandoned Cart** — see below.

Whether automations may re-subscribe an unsubscribed contact
(`force_opt_in`) follows the contact sync mode: never under consent or
checkout-only; under legitimate interest only when the advanced toggle is
enabled.

## Abandoned cart

A cart counts as abandoned when it has items and an email address and has
been idle past the **cutoff** (default 30 minutes, minimum 10). Carts older
than 24 hours are never mailed — a recovering cron never blasts stale
reminders. Each cart is mailed **once**.

The automation receives up to 10 products as numbered fields
(`product_name_1`, `product_sku_1`, `product_quantity_1`, `product_price_1`
(incl. tax), `product_base_price_1`, `product_description_1`,
`product_image_url_1`, … plus `over_10_products` when the cart is bigger) —
select which under **Abandoned Cart Product Fields**.

`{{abandoned_cart_url}}` is a secure recovery link that restores the exact
cart when clicked (a signed link; carts belonging to a registered customer
ask them to sign in first).

## Product RSS feed

A product feed for the Smaily template editor's RSS block:

```
https://your-store.example/smaily/rss/feed
```

Optional query parameters:

| Param | Values | Default |
|---|---|---|
| `category` | Category **ID** | all products |
| `limit` | 1–250 | 50 |
| `sort` | `created_at`, `updated_at`, `name`, `price` | `created_at` |
| `order` | `asc`, `desc` | `desc` |

You do not need to build the URL by hand: the **Feed URL Builder** in the
**Product RSS Feed** config group assembles it live as you pick the
category, limit and sorting, with a one-click **Copy** button. The
wizard's Done step links straight to it.

Items include `smly:price` / `smly:old_price` / `smly:discount` (prices as
shown in your storefront, tax included). Only catalog-visible, enabled
products are listed; configurable variants resolve to their parent. The
feed can be disabled per store view under the **Product RSS Feed** config
group. Responses are cached for 15 minutes.

---

## Campaign Intelligence

The optional Smaily recommendation engine: your catalog, customers, orders
and (opt-in) browsing behavior power personalized recommendations and
engine-run automations (replenishment reminders, win-back, …).

### Connecting

1. Get a one-time **setup URL/token** from Smaily.
2. Paste it under **Configuration > Smaily Connect > Campaign
   Intelligence > Setup Token or URL** and save. The token is exchanged
   immediately and never stored; the status row shows the connected tenant.

### What syncs

| Data | When | Toggle |
|---|---|---|
| Catalog | On product save/delete (deletes become out-of-stock) | Sync Catalog |
| Customers | On profile create/update (no consent fields — the engine is a separate lawful surface) | Sync Customers |
| Orders | On order placement and status changes | Sync Orders |
| Browse events | Product views, searches, cart adds, checkout — batched from the storefront | Storefront Browse Tracking (**off by default**) |

Browse tracking respects Magento's cookie restriction mode and sends events
through your own server (`smaily/relay`) so the API key never reaches the
browser.

### Recommendation attribution

Campaign clicks (`smaily_rec`/`smaily_vt`/`smaily_ctx` URL parameters) are
captured into first-party cookies and stamped onto the resulting order, so
the engine can credit purchases to recommendations. This works with Full
Page Cache because the capture runs client-side.

### Engine automations

The engine-run triggers live right under your regular automations:
**Stores > Configuration > Smaily Connect > Automations** lists the
triggers available to your sector. Each row maps a trigger to a
Smaily workflow with a cooldown, an optional daily cap and a **test mode**
(on by default — fires reach only the listed test emails until you turn it
off). Nothing is enabled without your explicit action.

---

## Historical import (backfill)

Historical imports live on the **Settings** page (or the CLI) and run in
the background, a chunk per cron minute, without ever blocking live
traffic:

- **Subscribers → Smaily** — Settings > **Subscribers** tab (per website;
  both subscribed and unsubscribed, so suppression state is correct)
- **Catalog / Customers / Orders → Campaign Intelligence** — Settings >
  **Intelligence** tab

Each import button shows live progress right where you started it, and a
**Cancel import** button appears while a job is running — the background
worker stops cleanly at its next page boundary. A cancelled import is
terminal: starting the same import again begins a fresh run from the
beginning. (An import interrupted by an error, on the other hand, resumes
from its last cursor.) Imports are safe to re-run either way: deliveries
are deduplicated on the receiving side.

After an import finishes, its outcome and timestamp stay visible on the
panel — you do not have to keep the page open:

- **"Done, X of Y synced"** — everything landed.
- **"Done, X of Y synced — N failed"** — individual items failed
  permanently, with a link to the Log pre-filtered to those rows. Items
  still waiting for an automatic retry are *not* counted as failed.
- **"Stopped before an error"** — the job itself hit an error and stopped
  at a page boundary; nothing was lost, press the import button to run it
  again.
- **"Cancelled"** — stopped on your request; starting again begins a
  fresh import.

## The log and troubleshooting

- **Marketing > Smaily Connect > Log** — every delivery in one grid:
  Smaily (contact syncs, automation triggers) and Campaign Intelligence
  (catalog, customers, orders, browse events), told apart by the
  **Source** column, with status, attempts and the last error. Select
  failed rows and **Retry** — each row is routed back to its own queue.
- **Details** on any row opens a slide-out with the full picture: the
  payload exactly as it was (or will be) sent, the attempt count, when the
  next automatic retry happens (or an honest "this row will not retry on
  its own"), the last error and the last API response. Sensitive values
  (passwords, API keys) are never shown, and email addresses are masked.
- When deliveries failed in the last 24 hours, a banner above the grid
  says so and links straight to the grid pre-filtered to failed rows; the
  dashboard's failed-deliveries tile links to the same view.
- Deliveries retry automatically with backoff (1 min → 6 h, 5 attempts)
  before parking as *failed* for manual retry.
- An admin notification appears when the engine has been unreachable for
  over an hour, or when many events failed within 24 hours.
- Logs: `var/log/smaily_connect.log`. Verbosity under **Configuration >
  Smaily Connect > Logging** (debug logs summarize payloads — customer PII
  is not written to disk).
- Sent queue rows are pruned after 30 days, failed rows after 90.

## Privacy and GDPR

- **Marketing consent** lives on the Magento newsletter subscription and
  the Smaily contact (`is_unsubscribed`) — bidirectional in consent mode.
- **Personalization (profiling) consent** is a separate axis: customers can
  opt out of personalized recommendations under **My Account >
  Personalization**. The choice is stored on the Smaily contact and
  enforced by the engine.
- **Data subject requests** for engine data:
  `bin/magento smaily:gdpr export <email>` (Art. 15) and
  `bin/magento smaily:gdpr erase <email> --force` (Art. 17, idempotent).
- Magento-side customer data is handled by Magento's own tooling; Smaily
  contact deletion is done in the Smaily UI.

## CLI reference

| Command | Purpose |
|---|---|
| `smaily:backfill:start [contacts\|catalog\|customers\|orders] [--website=N]` | Start a historical import |
| `smaily:backfill:status` | Show import progress |
| `smaily:engine:ping` | Campaign Intelligence health check |
| `smaily:engine:disconnect --force` | Remove the local engine connection |
| `smaily:gdpr export\|erase <email> [--force]` | Engine data export / erasure |

## FAQ

**Nothing is syncing.** Check that Magento cron runs (`bin/magento
cron:run --group smaily_connect` manually to test), the connection is
saved, and look at the Log for errors — the Dashboard verdict points
there when deliveries fail.

**A contact unsubscribed in Smaily but is still subscribed in Magento.**
Reconciliation runs every 15 minutes and only in *Subscribers only
(consent)* mode.

**The abandoned cart email never arrives.** The automation must be enabled
with a workflow selected; the cart needs an email address (guest carts get
one at checkout's email step); the cart must be idle past the cutoff but
younger than 24 h; and each cart is only ever mailed once.

**The checkout checkbox doesn't show.** It renders on the Luma/Knockout
checkout payment step — including Hyvä's default Luma-fallback checkout.
The commercial Hyvä Checkout product is a different integration surface and
is not supported (see [HYVA_SUPPORT.md](HYVA_SUPPORT.md)).

**Where did the sync frequency setting go?** v3 syncs in near-real-time via
observers plus a 15-minute consent reconcile; the old 4h/12h/daily presets
are obsolete.

**What languages does the module speak?** English and Estonian — the admin
and storefront follow the configured Magento locale (`et_EE` for Estonian).
Translation packs live in the module's `i18n/` directory; contributions for
other languages are welcome.
