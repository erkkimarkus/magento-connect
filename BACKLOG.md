# Backlog

Deferred work items for the v3 line, in rough priority order.

## Hyvä support (separate work pack)

Compat module done and **verified on Hyvä 1.5.2** (`compat/hyva/`, module
`Hyva_SmailyConnect`): framework-free tracker/attribution delivery, Tailwind
personalization form, Tailwind-build registration. Full audit and the
executed verification matrix (Luma / Hyvä / strict CSP — all pass) in
[docs/HYVA_SUPPORT.md](docs/HYVA_SUPPORT.md). Remaining:

- Verify the `cart_add` submit-capture path against third-party
  AJAX-add-to-cart compat modules (programmatic `form.submit()` fires no
  submit event); fall back to a `private-content-loaded` cart diff if a
  real store shows gaps.
- Publish the compat module as its own composer package (vendor/name is an
  open release decision — see HYVA_SUPPORT.md).
- Checkout opt-in checkbox for Hyvä Checkout (commercial product, own
  Magewire integration surface) — declared out of scope for the first
  Hyvä-support release; the server-side opt-in endpoint is already
  checkout-agnostic.

## Admin / UX

- Config-scope override honesty on the Settings page: reads resolve the
  store scope, saves land at the default scope — a website-scope override
  (e.g. migrated from a per-website 2.8.x setup) silently shadows a fresh
  save. Surface/edit overrides or warn when one is in effect.
- Per-language automation mapping UI (modes A/B: the `smaily_automation_mapping`
  table and Router support it; only the editing UI is missing — currently
  seeded by migration or managed via DB).
- Engine automations form: per-language `automation_map` editing
  (`language_mode: per_language`); MVP ships single-language maps.
- Backfill job cancel button in the admin grid (CLI/DB only for now).
- Setup wizard as a guided multi-step flow (the Getting Started checklist
  covers onboarding for now).

## Sync / data

- Abandoned-cart coverage for guests who abandon before the payment step:
  fall back to the `quote_address` billing email when
  `quote.customer_email` is still NULL (Magento fills it only at
  payment-info submit). Legacy-parity gap, not a regression.
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
