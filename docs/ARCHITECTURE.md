# Architecture

Developer documentation for the `Smaily_Connect` module (namespace
`Smaily\Connect`). The module is a ground-up v3 rewrite targeting feature
parity with the Smaily Connect plugins for WooCommerce and Shopify; the
three connectors share wire contracts, so cross-platform consistency is a
design constraint, not an accident.

## Layout

Classes live at the **package root** (classic Magento module layout) so the
package works both as a composer dependency (`vendor/smaily/smailyformagento`)
and as a manual `app/code/Smaily/Connect` install — Magento's app/code
autoloader maps `Smaily\Connect\*` to the module root.

```
Api/                service contracts (queue handler interface)
Block/              adminhtml config renderers
Console/Command/    CLI (backfill, engine ping/disconnect, GDPR)
Controller/         frontend: rss, relay, checkout optin, cart restore, privacy
Controller/Adminhtml/  dashboard, wizard, settings, unified log, JSON api
Cron/               queue flushers, abandoned cart, reconcile, backfill tick,
                    janitor, health check
Model/
  Client/           Smaily marketing API client (Guzzle, Basic auth)
  Engine/           Campaign Intelligence: client, settings, ingest queue,
                    payload builders, attribution, browse validation
  Queue/            marketing event queue + per-type handlers
  ContactSync/      lawful-basis mode, payload builder, dispatcher, guard
  AbandonedCart/    quote-scan state, payload, restore tokens
  Automation/       trigger routing (multilingual mapping table + saver)
  Multilingual/     store-view → language, account-key → store-view
  Backfill/         chunked import jobs + processors
  Migration/        2.8.x config mapper (pure, unit-tested)
  Privacy/          profiling consent
Observer/           thin event bridges (all logic lives in Model/)
Plugin/             newsletter email suppression, config validation,
                    checkout layout injection
Setup/Patch/        legacy schema cleanup (Schema/), config migration (Data/)
ViewModel/          template data providers
view/               adminhtml pages/panels/grid, frontend JS + templates
i18n/               translation packs (en_US canonical, et_EE)
```

## The two delivery pipelines

Everything outbound flows through one of two **durable queues** — nothing
user-facing ever blocks on an HTTP call, and nothing is lost when an API is
down.

### Marketing events → Smaily (`smaily_event_queue`)

```
Observer / cron ──enqueue──> smaily_event_queue ──cron flush (1 min)──> Smaily API
```

- Event types (`Model/Queue/EventType`): `contact.sync` (batched per store
  view — per-language accounts hit the right credentials),
  `automation.trigger` (delivered one-by-one so a partial failure can never
  re-trigger an automation), `engine.identity_merge`.
- Automation routing (`Model/Automation/Router`, Woo `Multilingual\Router`
  parity): multilingual modes `single`/`c` use the config-default workflow;
  modes `a`/`b` resolve `smaily_automation_mapping` rows — the exact
  (trigger, language) row first, then the trigger's `is_default_fallback`
  row, then the config default; no match anywhere is a terminal skip. Rows
  are looked up for the event's website with legacy global rows
  (`website_id 0` — the 2.8.x migration's default-scope seeding, or a
  pre-Phase-3 save) as the fallback; a website-specific row wins. A matched
  row's `account_key` travels with the workflow (`WorkflowMatch`) and the
  handler posts through THAT account's credentials
  (`Multilingual\AccountResolver` maps the key to a store view; `default`
  = default scope) — so a mode-A fallback row never fires another
  account's workflow ID through the event store view's credentials. The
  admin panels write rows through `Model/Automation/MappingSaver` at the
  target website scope (full-desired-state sync: unique-key upsert,
  cleared rows deleted, other websites' rows untouched).
- Handlers are registered per type in `di.xml`
  (`Model/Queue/HandlerPool`); adding an event type = adding a handler.
- Payloads are built at enqueue time (`ContactSync\SubscriberPayloadBuilder`,
  `ContactSync\SyncDispatcher`) and stored on the row.

### Engine ingest → Campaign Intelligence (`smaily_ingest_queue`)

```
Observer / backfill ──enqueue──> smaily_ingest_queue ──cron flush (1 min)──> engine
       (domain: catalog | customers | orders | browse | catalog_remove)
```

- One wire item per row; the row UUID doubles as the wire `event_id`, so
  engine-side transport dedup makes retries safe.
- `Cron/FlushIngestQueue` sends one batch per domain per run (batch caps
  100/100/50/100 per the contract) and maps the D6 response's
  `errors[].index` back onto individual rows — a 200 is never treated as
  all-or-nothing.
- Every catalog row carries `tags.product_id` — the platform parent product
  id (`Engine\Payload\ParentProductResolver`: a configurable child resolves
  to its parent's entity id, everything else to its own). It keys the
  engine's product-level removal; the `sku` keying is untouched.
- **Product delete** (`Observer/Engine/ProductDeleteBefore`): a
  parent/standalone hard-delete enqueues one `catalog_remove` row; the
  flusher drains those through its own non-D6 path to
  `POST /api/v1/ingest/catalog/remove` (contract §3b — the engine
  tombstones every row matching `tags.product_id`; `not_found` in the
  response is a success, never a retry). A configurable child's deletion
  keeps the per-SKU `in_stock=false` upsert instead — §3b is product-level
  and would tombstone the surviving parent/siblings. A merely
  disabled/hidden product flows through `ProductSaveAfter`'s soft
  tombstone, never §3b.
- **Stock changes (PRO-1951).** `in_stock` is the one catalog field the store
  can move without a product save, and the engine's back-in-stock feature
  reads it, so four hooks feed catalog ingest, all funnelling through
  `Engine\CatalogIngest`:
  `Observer/Engine/ProductSaveAfter` (`catalog_product_save_after`),
  `Observer/Engine/StockItemSaveAfter`
  (`cataloginventory_stock_item_save_after` — Advanced Inventory, the
  `products/{sku}/stockItems` endpoint, the non-MSI order decrement and
  restock) and one thin plugin per MSI seam:
  `Plugin/Engine/SourceItemsSave` on
  `InventoryApi\Api\SourceItemsSaveInterface` and
  `Plugin/Engine/SourceDeduction` on
  `InventorySourceDeductionApi\Model\SourceDeductionServiceInterface`. On a
  default 2.4.x install MSI owns inventory and *placing* an order decrements
  nothing — it writes a reservation, leaving `is_in_stock` untouched; the real
  decrement is the shipment's source deduction, and the credit-memo return to
  stock is its mirror. Both go through `SourceDeductionService`, neither
  through `SourceItemsSave`, hence the two seams. MSI is treated as an
  optional dependency: neither plugin names an MSI type at all, so on an
  install without the Inventory modules they are simply never wired and the
  legacy observer covers everything.
  `CatalogIngest` is also where the engine-connected gate is checked — once,
  for every path including the delete tombstone and the backfill pages — and
  where one product save's duplicate rows are collapsed: it legitimately
  reaches three of the hooks, so a byte-identical row queued twice in a row
  within one request is dropped. This is NOT queue-wide dedupe; two real stock
  moves still queue two rows.
- **`in_stock` source, deliberately the legacy flag (PRO-1951).** It is read
  from `CatalogInventory`'s `is_in_stock`, which MSI keeps synced, not from
  MSI's salable-per-website quantity. A catalog row is keyed on `sku` per
  tenant and one installation is one tenant, so a per-website salable answer
  has nowhere to go until the multi-website tenant work (RFC Phase 4, gated on
  PRO-1459) gives each website its own tenant. `CatalogPayloadBuilder::
  isInStock()` drops the stock registry's per-request memo immediately before
  it reads (MSI mirrors onto the legacy row with direct SQL and so never
  invalidates that memo — otherwise selling out publishes `in_stock: true`).
  It lives at the read, not in a caller, so live hooks, the delete tombstone
  and the backfill/nightly-resync pages are all correct by construction. It
  falls back to **true** if the stock read throws: the fallback is
  deliberate — the engine's recommender excludes out-of-stock products, so
  defaulting to false would silently pull a product out of every campaign over
  a transient read error, which is the worse failure. The nightly reconciler
  corrects a wrong `true` within a day.
- **Periodic catalog reconciler (PRO-1951).** `Cron/CatalogResync` (daily,
  `40 3 * * *` store time — off-peak, just after the queue janitor) starts an
  ordinary catalog backfill job rather than walking the catalog itself, so
  `Cron/BackfillTick` pages it in with the same cursor, time budget and flood
  guard a merchant-started import gets. It never runs disconnected, and the
  one-active-job lock refuses the start while a catalog import is already
  open, so a merchant's own import is never trampled — the sweep skips that
  night instead. It exists for
  the one class of change events cannot see: a CSV / `bin/magento import` run
  writes the catalog tables directly. Latency: event-driven changes ~1–2 min,
  everything else at most ~24 h.
- **Order return signals (contract §5, v1.8.0):** `items[].returned_at` is
  derived from the order's own credit memos on every build
  (`OrderPayloadBuilder`), never from a one-shot event — the engine replaces
  an order's items wholesale on re-ingest, so a later sync that omitted the
  field would erase the return. A line is marked returned only once its FULL
  quantity has been credited (a partly credited quantity stays kept, §5);
  neither reason field is sent, because Magento has no structured return
  taxonomy to map from. A partial credit memo leaves the order state alone,
  so `OrderSaveAfter`'s enqueue gate treats a moved `total_refunded` as a
  change worth re-sending.
- **A refused account stops every send path (PRO-2451).** Contract §2 answers
  `403 tenant_inactive` from every API-key-authenticated endpoint when the
  tenant is deactivated (operator suspension or a GDPR purge — the wire never
  tells the two apart, and the correct reaction is the same). `Engine\Client`
  records it at the single chokepoint every engine call passes through
  (`Engine\Settings::recordRefusal()`, the FIRST refusal's timestamp at
  default scope) and clears it on any authenticated call that answers — the
  health-check ping and the admin's "Check again" are the only calls that
  still go out. `Settings::isSendingAllowed()` (`isConnected() && !isRefused()`)
  is the one gate every SENDING path consults: `Cron/FlushIngestQueue`,
  the engine-bound backfills at `Cron/BackfillTick`'s router,
  `Controller/Relay/Index`, `Queue\Handler\IdentityMergeHandler`,
  `Privacy\ProfilingConsent` and `Cron/CatalogResync`. The two paths that
  loop re-ask only its refusal half once connectedness is proved — per domain
  in the flusher (so one refusal ends the run) and per row in the merge
  handler — because that is the half a mid-run 403 can change.
  `isConnected()` stays the gate for everything that only enqueues, reads or
  displays, so live observers keep queueing and the store's history is intact
  when the account comes back. Rows a refusal met
  mid-batch go back to pending untouched (`IngestQueue::release()` — never
  burned, never failed, attempt counters intact); the janitor prunes them by
  its normal age rule.
- Browse events are the exception: loss-tolerant by design, they are relayed
  synchronously (`Controller/Relay/Index`) and never queued.
- **Catalog price/URL/language scope (PRO-1352/1353):** one Magento
  installation is one engine tenant with one base currency (which every
  catalog row now names, contract §3 `currency`, v1.7.0) — the plugin, not
  the engine, is responsible for always sending one consistent scope's
  price. Both catalog
  ingest paths resolve through the SAME single canonical store
  (`CatalogPayloadBuilder::canonicalStoreId()` — the default store view of
  the default website, the same "default scope" concept as `Engine\Client`'s
  base-URL fallback and `Multilingual\AccountResolver`'s default account),
  never Magento's implicit current-store resolver: the backfill collection
  (`EngineCatalogProcessor::loadPage()`) calls `setStoreId()` with it
  explicitly before `addUrlRewrite()`/`addPriceData()`, and the live path
  (`ProductSaveAfter`/`ProductDeleteBefore`) re-scopes the product to it via
  `ProductRepository::getById($id, false, $canonicalStoreId)` before reading
  price whenever the admin save/delete didn't already resolve that same
  scope. This is a deliberate single-pinned-scope simplification, not a
  per-website fan-out: a multi-website installation with divergent base
  currencies or divergent per-website prices still ingests only the
  canonical website's price. Each `smaily_ingest_queue` catalog row's
  `store_id` column records the scope the payload was built under, for
  audit.

### Queue semantics (both queues)

- **Retry policy:** backoff 60 s / 5 min / 15 min / 1 h / 6 h, max 5
  attempts, then parked as `failed` for manual retry from the admin Log.
  Only failures that can plausibly pass later get those attempts: a
  permanent refusal (4xx other than 429) stops on the first one, parked as
  `failed` with a `permanent_http_<code>` reason. A 429 is parked for the
  `Retry-After` the response asked for (delta-seconds form, capped at 6 h;
  an HTTP-date falls back to the ladder step). `Model\Queue\RetryPolicy`
  for the Smaily queue, `Engine\Client` + `Cron\FlushIngestQueue` for the
  ingest queue.
- **Claiming:** rows are claimed with a per-worker `claim_token`; only rows
  the worker actually won are processed, so concurrent flushes (manual cron,
  multi-node) can never double-send. Rows stuck in `sending` (killed
  worker) are requeued after 15 minutes.
- **Idempotency:** `event_uuid` is unique; callers may pass a deterministic
  UUID to make an enqueue idempotent.
- **Retention:** sent 30 days, failed 90 days (`Cron/QueueJanitor`).

## Database tables

| Table | Purpose |
|---|---|
| `smaily_event_queue` | Marketing event queue |
| `smaily_ingest_queue` | Engine ingest queue |
| `smaily_abandoned_cart` | Per-quote send state + checkout opt-in flag (the core `quote` table is never altered) |
| `smaily_automation_mapping` | (website, trigger, language, account) → workflow |
| `smaily_backfill_job` | Chunked import jobs (cursor-resumable) |
| `smaily_order_attribution` | Recommendation attribution per order (sales connection) |

All schema is declarative (`etc/db_schema.xml` + whitelist).
`Setup/Patch/Schema/MigrateLegacyQuoteColumns` drops the legacy 2.8.x
artifacts (`quote.reminder_date`, `quote.is_sent`, `smaily_customer_sync`)
because a renamed module's declarative schema cannot.

## Cron jobs (group `smaily_connect`)

The group runs in a separate process (`etc/config.xml`). Host cron should
invoke `bin/magento cron:run` every minute.

| Job | Schedule | Does |
|---|---|---|
| `smaily_flush_event_queue` | every minute | Drain marketing queue |
| `smaily_flush_ingest_queue` | every minute | Drain engine queue (all domains) |
| `smaily_backfill_tick` | every minute | Advance the oldest active import one time-budgeted chunk |
| `smaily_abandoned_cart` | every 5 min | Scan idle quotes, enqueue automations |
| `smaily_contact_reconcile` | every 15 min | Smaily→Magento consent mirror |
| `smaily_health_check` | every 15 min | Engine-down / failure-volume notices |
| `smaily_queue_janitor` | daily 02:20 | Retention pruning |
| `smaily_catalog_resync` | daily 03:40 | Full catalog re-sync — the reconciler for stock/price changes no event can see (CSV import) |

## Key flows

### Consent reconcile (consent mode only)

`Cron/ContactReconcile` polls Smaily's action log
(`GET /api/history.php?since_seq_id=…&actions=optin,optout,delete,complaint`,
comma-separated — note the Woo reference's bracket-array form is a latent
bug there, not here) per **resolved account** (a website's own account, plus
one per distinct mode-A per-language account — `Multilingual\AccountResolver`,
deduplicated by resolved credentials) with a durable cursor per account
(`FlagManager`; a website's own account keeps its pre-existing
`smaily_connect_reconcile_seq_w<websiteId>` key, an additional per-language
account gets a `_<accountKey>`-suffixed one, since the action log's `seq_id`
numbering is per Smaily account), and mirrors state onto
`newsletter_subscriber` inside the `ContactSync\ReconcileGuard` so the
subscriber-save observer never echoes the write back to Smaily. Writes use
import mode — no Magento emails.

### Abandoned cart

`Cron/AbandonedCart` scans the native `quote` table (active, has items +
email, idle past cutoff, younger than 24 h), diffs against the
`smaily_abandoned_cart` side table, marks `mailed` **before** dispatching
(a crash costs one reminder, never a duplicate), and enqueues the
automation. The payload's `abandoned_cart_url` is an HMAC-signed
`smaily/cart/restore` link (`AbandonedCart\RestoreTokenManager`, keyed with
the installation crypt key) that restores the exact quote.

### Attribution (FPC-safe by construction)

Landing capture is client-side (`view/frontend/web/js/attribution.js` —
URL params → first-party cookies), because server-side capture never runs
on FPC-cached pages. At order save (`Observer/Engine/OrderSaveAfter`, where
`entity_id` exists) cookies are stamped into `smaily_order_attribution`;
`OrderPayloadBuilder` forwards them on the order wire
(`smaily_rec_id` / `smaily_visitor_token` / `smaily_rec_ctx` /
`session_id`). The order is the only path these reach the engine on: since
contract v1.7.0 the engine ignores the rec id/context echo on browse
events, so the tracker no longer sends it.

### Browse tracking

`view/frontend/web/js/tracker.js` (RequireJS; core is framework-free
vanilla for a future Hyvä path) reads page context from
`window.smailyPageContext` (set by FPC-cached per-page templates), batches
events for 5 s, and posts to `smaily/relay`. The relay
(`Controller/Relay/Index`) is CSRF-exempt (anonymous beacon), strictly
sanitized (`Engine\BrowseEventValidator` — UUID v4 event ids, event-type
enum, **no client-asserted `customer_email`**, and no `smaily_rec_id` /
`smaily_ctx` hints the engine has ignored since v1.7.0), rate-limited per IP, stamps
`source: plugin_magento` server-side, and forwards so the API key never
reaches the browser. Under Magento cookie restriction mode without cookie
consent the tracker runs in sender-side anonymous mode (contract §6):
events still flow with `session_id` + `event_id` but the
`smaily_visitor_token` identity hint is omitted.

## Admin UI

Four pages under **Marketing > Smaily Connect** (menu.xml): Dashboard,
Setup Wizard, Settings, Log. Design rules:

- **One source of truth.** The wizard and the Settings page write through
  the same `Model\Adminhtml\WizardStepSaver` into the same system-config
  paths declared in `etc/adminhtml/system.xml` — that section's
  `showInDefault`/`showInWebsite`/`showInStore` are all `"0"` (PRO-1461), so
  it never renders under Stores > Configuration for any admin; the
  declarations stay only for encrypted backend models and CLI
  `config:set`/`config:show`. There is no parallel settings store, and no
  second editing surface.
- **Multi-website (RFC_MULTI_WEBSITE.md).** `Model\Adminhtml\WebsiteContext`
  is the single seam every admin save/prefill path reads to know its target
  website: it resolves a `website` request param (validated against real
  websites) falling back to the installation's default website. On a
  2+-website install, the Settings page renders an explicit website
  selector next to the tab strip and the wizard renders a website-chooser
  step before Connect (own chrome, not Magento's native store-switcher);
  both work by reloading with `?website=<id>` — every AJAX call and the
  page's own prefill (`ViewModel\Adminhtml\WizardData::getBootJson()`)
  already read that same context, so no further plumbing is needed per
  field. Single-website installs never see either control.
  `Model\Adminhtml\SetupGuard`'s completed flag is website-scoped the same
  way, so each website's own onboarding is tracked independently (a new
  website falls back to the installation's existing completed flag via the
  normal website→default scope chain, same as any other field). Campaign
  Intelligence stays installation-wide regardless of the selected website
  (Phase 4 of the RFC, gated on engine-side confirmation).
- **Shared step partials.** The wizard's step content and the Settings
  tabs are the SAME templates (`view/adminhtml/templates/panel/*.phtml`),
  composed by two thin page templates (`wizard/index.phtml`,
  `settings/index.phtml`). Shared behavior (AJAX saves, test connection,
  live workflow dropdowns, backfill progress polling, field reactivity)
  lives once in `panel/panels-js.phtml`, which defines
  `window.smailyPanelsInit` — each page script passes jQuery in and drives
  the stepper (wizard) or the deep-linkable `?tab=` tabs (settings).
  Strings in these inline scripts are translated server-side with `__()`
  (js-translation.json never collects `$t()` from phtml).
- **Multilingual UI is part of the shared panels.** When more than one
  store-view language is detected (`Multilingual\AccountResolver`), the
  Connection panel renders the routing-mode choice cards; mode `a` swaps
  the single credential block for per-language blocks (each with its own
  Test Connection — saved accounts re-test via `store_id` against the
  saved store-view credentials) plus a default-fallback picker whose
  account's credentials double as the default scope. Modes `a`/`b` reveal
  the per-language workflow mapping editor on the Automations panel
  (workflow dropdowns loaded live per account). All sections show/hide
  live on mode change with no save round-trip; leaving mode `a` removes
  the per-store-view credential overrides on save (confirmed in the UI
  first). Single-language installs are locked to `single` and see none of
  this.
- **Dashboard is operational truth.** Every number on
  `dashboard/index.phtml` is a real local queue query
  (`Model\Adminhtml\DashboardStats`); the health verdict reuses the
  HealthCheck cron's failed-rows query (`Model\Health\QueueHealth`) and
  engine-down flag, so the dashboard can never disagree with the admin
  notifications. Unknowable numbers are omitted, not estimated.
- **Unified log.** One grid (`smaily_log_grid`) over BOTH queues:
  `Model\ResourceModel\Log\Collection` builds a `UNION ALL` of the two
  queue tables as a derived table, keyed by the synthetic
  `log_id` (`smaily-<id>` / `intelligence-<id>`) that
  `Controller\Adminhtml\Log\MassRetry` splits to route retries back to the
  right queue. Grid filters/sorting apply to the outer select.
- **Wizard-first gating.** `Model\Adminhtml\SetupGuard`: while
  `smaily_connect/internal/setup_completed` is unset for the current
  `WebsiteContext` target, Dashboard/Settings/Log redirect to the wizard.
  The guard also tracks
  `smaily_connect/internal/last_seen_version` (module version read from
  composer.json via `Model\ModuleVersion`) and posts a one-time admin
  notice after a MAJOR version jump instead of any hard redirect.

## Wire contracts

The authoritative engine contract is
[RECENGINE_API_CONTRACT.md](RECENGINE_API_CONTRACT.md) (v1.8.1, byte-synced
across the Smaily connect repositories). Load-bearing invariants
implemented here:

- Endpoint URLs always come from the stored endpoints map
  (`Engine\Settings`), never concatenated; `{email}` placeholders are
  substituted with `str_replace`.
- Retry: 1/2/4/8/16 s on 429 (honouring `retry_after_seconds` from the
  body) and 5xx; other 4xx never retry (`Engine\Client`).
- Smaily marketing API: HTTP Basic; success envelope `{code:101}`, 203 =
  invalid data, 206 = email not found (`Model\Client\SmailyClient`).
- Engine automations config (§13): every row carries all eight keys;
  validation is all-or-nothing; `per_language` rows saved by other
  platforms survive a Magento save.

## Extension points

- **New queue event type:** implement `Api\Queue\EventHandlerInterface`,
  register in the `HandlerPool` via `di.xml`.
- **New backfill:** implement `Model\Backfill\ProcessorInterface`, register
  under `"{job_type}:{target}"` in `Cron\BackfillTick`'s pool.
- **Payload shape changes:** each payload has exactly one builder class
  (`ContactSync\SubscriberPayloadBuilder`, `AbandonedCart\PayloadBuilder`,
  `Engine\Payload\*`) — the single source of truth used by both live hooks
  and backfills.

## Testing

See [../TESTING.md](../TESTING.md): unit suite + static analysis in CI, a
Docker Magento 2.4.8 sandbox for end-to-end smoke, and a scripted 2.8.x
upgrade-migration verification.
