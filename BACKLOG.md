# Backlog

Deferred work items for the v3 line, in rough priority order.

## Hyvä support (separate work pack)

Needs a real Hyvä environment to develop and verify:

- Framework-free delivery path for the browse tracker and attribution script
  (Hyvä does not load RequireJS; the core logic is already vanilla JS).
- Newsletter form template for Hyvä themes.
- Checkout opt-in checkbox for Hyvä Checkout (commercial product, own
  integration surface — likely a separate `smaily/module-connect-hyva`
  compat package, the usual pattern in the Hyvä ecosystem).

## Admin / UX

- Per-language automation mapping UI (modes A/B: the `smaily_automation_mapping`
  table and Router support it; only the editing UI is missing — currently
  seeded by migration or managed via DB).
- Engine automations form: per-language `automation_map` editing
  (`language_mode: per_language`); MVP ships single-language maps.
- Backfill job cancel button in the admin grid (CLI/DB only for now).
- Setup wizard as a guided multi-step flow (the Getting Started checklist
  covers onboarding for now).

## Sync / data

- MSI (multi-source inventory) stock-change observers; currently product-save
  and legacy stock events cover the common paths.
- Per-store-view catalog i18n uses one representative store per language;
  per-website engine tenants are out of scope (one tenant per installation).
- Subscriber full-sync safety net (daily) — reconcile + live events cover the
  standing flows; evaluate whether a periodic re-baseline
  (`GET contact.php?list=1`) is needed at scale.
- Browse relay rate limiting (the engine rate-limits; a local limiter would
  cut noise from abusive clients).

## Quality

- Magento integration test suite (`Test/Integration`) + CI job with MySQL
  service; the upgrade migration currently has a scripted sandbox procedure
  (see TESTING.md).
- i18n translation files (`i18n/en_US.csv`, `et_EE.csv`).
- Storefront JS tests for tracker/attribution.

## Deliberate decisions to revisit

- **Browse beacon vs profiling opt-out:** the beacon is anonymous
  (visitor-token based), so a per-email consent gate cannot be applied at
  collection time under FPC; enforcement of the §10 profiling opt-out happens
  engine-side (opted-out contacts are excluded from recommendations). If the
  spec's "stop collection too" posture becomes a hard requirement, bind the
  opt-out to the visitor token via customer-data sections.
- **Browse `source: "plugin_magento"`:** not yet in the contract's constant
  list (`web, plugin_woo, plugin_shopify, make, custom`) — add it to
  RECENGINE_API_CONTRACT.md in the connect/re repos before any engine-side
  source-enum tightening.
- **Order item amounts are tax-inclusive** (what the shopper saw); Woo sends
  ex-tax. Both are valid engine inputs; documented in OrderPayloadBuilder.

## Upstream

- Coordinate with Smaily: staged review of the v3 branch, Marketplace
  re-submission (product name "Smaily Connect"), transfer of the release
  pipeline. The composer package name `smaily/smailyformagento` is kept so
  existing installs upgrade via plain `composer update`. The full proposal
  package is drafted in [docs/UPSTREAM_PROPOSAL.md](docs/UPSTREAM_PROPOSAL.md).
