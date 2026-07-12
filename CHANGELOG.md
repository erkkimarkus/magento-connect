# Changelog

### 3.0.0 (unreleased)

Ground-up rewrite as module `Smaily_Connect`, targeting feature parity with the Smaily Connect plugins for WooCommerce and Shopify. Upgrading from 2.8.x is seamless: the composer package name is unchanged and all settings (including the previously plaintext API password, now encrypted) migrate automatically during `setup:upgrade`.

**New features**

- Contact-sync lawful-basis modes: subscribers only (consent, default), all customers (legitimate interest), checkout opt-in only.
- Two-way consent sync: unsubscribes/resubscribes in Smaily mirror back onto Magento newsletter subscribers (action-log delta polling).
- Welcome and first-order automations alongside the abandoned cart automation; per-language workflow routing for multilingual stores (store view = language).
- Checkout newsletter opt-in checkbox (guests and customers, double opt-in respected).
- A native admin home under **Marketing > Smaily Connect** with four pages: **Dashboard** (one-sentence health verdict, connection status, truthful operational counters from the local queues, recent activity), a guided five-step **Setup Wizard** (fresh installs land there automatically until setup is completed), a tabbed **Settings** page (Connection / Subscribers / Automations / Intelligence / RSS — the wizard's steps as always-available, deep-linkable tabs with instant per-tab AJAX saves and live field reactivity) and one unified **Log**. Wizard, Settings and Stores > Configuration all edit the same configuration.
- Durable event queues with retries/backoff, one unified admin Log over both delivery queues (a Source column tells Smaily and Campaign Intelligence rows apart) with cross-queue mass retry, and health notices when deliveries keep failing.
- Per-row **Details** drill-down in the Log (slide-out panel): the payload as sent, attempt count, next automatic retry time (or an honest "will not retry on its own"), last error and last API response — secrets are never shown and email addresses are masked. A failed-deliveries banner above the grid and the dashboard's failed tile deep-link to the Log pre-filtered to failed rows.
- Historical import (backfill) of subscribers, catalog, customers and orders — one click with live progress on the Settings page (Subscribers / Intelligence tabs) or CLI. A running import can be cancelled (the worker stops cleanly at the next page boundary; starting again begins a fresh run), and the last outcome + timestamp persist on the panel with honest copy: "Done, X of Y synced", "… N failed" with a Log deep-link (items still pending retry are not counted as failed), "Stopped before an error", or "Cancelled".
- After a major version upgrade a one-time admin notification suggests reviewing the settings or re-running the wizard — settings are never changed or blocked.
- Campaign Intelligence integration: catalog/customer/order/browse ingest, recommendation attribution, identity merge, engine-run automations admin, GDPR export/erase CLI and a shopper personalization opt-out page.
- RSS feed improvements: category **ID** filter, limit/sort/order parameters, cache headers, enable/disable toggle [[#48](https://github.com/sendsmaily/smaily-magento-extension/issues/48), [#49](https://github.com/sendsmaily/smaily-magento-extension/issues/49), [#50](https://github.com/sendsmaily/smaily-magento-extension/issues/50), [#72](https://github.com/sendsmaily/smaily-magento-extension/issues/72)]
- Feed URL Builder in the Product RSS Feed configuration group: pick category/limit/sorting and copy the ready feed URL with one click; the wizard's Done step links to it.
- In-admin documentation: the setup wizard's Done step and the post-install notice link to the full user guide.
- Configurable log verbosity on a dedicated log file [[#113](https://github.com/sendsmaily/smaily-magento-extension/issues/113)]
- Translations: full English (`i18n/en_US.csv`) and Estonian (`i18n/et_EE.csv`) translation packs covering the admin (wizard, configuration, grids) and the storefront (checkout opt-in, personalization page). API and engine error messages surfaced in the admin are translated too, framed in sentences that stay understandable even when the remote service's own message is technical.

**Under the hood**

- New module name `Smaily_Connect` (namespace `Smaily\Connect`); composer package name unchanged.
- Declared PHP (8.1–8.4) and Magento (2.4.4+) requirements in composer.json [[#18](https://github.com/sendsmaily/smaily-magento-extension/issues/18)]
- No more columns on the core `quote` table; legacy `reminder_date`/`is_sent` columns and the unused `smaily_customer_sync` table are cleaned up on upgrade.
- Store-timezone-safe scheduling (the hardcoded Europe/Tallinn timezone is gone); Guzzle-based API clients with timeouts and typed errors.
- Legacy custom captcha replaced by Magento's native reCAPTCHA module (admin notice on upgrade).
- Unit tests, phpcs/phpstan static analysis and CI added [[#51](https://github.com/sendsmaily/smaily-magento-extension/issues/51)]
- Integration test suite against a real MySQL (queue retry/backoff/claim semantics, the 2.8.x → v3 settings and schema migration, queue cron flows with stubbed HTTP transports), run in CI with a MySQL 8.4 service — see [TESTING.md](TESTING.md).
- Ships the Campaign Intelligence engine wire contract (`docs/RECENGINE_API_CONTRACT.md`, v1.4.0, byte-synced across Smaily connect repositories): order amounts gross/tax-inclusive (`row_total_incl_tax` / `grand_total`), browse events tagged `source: plugin_magento`, and the browse beacon degrades to sender-side anonymous mode (identity hint omitted, events keep flowing) when cookie consent is absent.
- Catalog rows carry the platform parent product id as `tags.product_id` (a configurable child resolves to its parent's entity id). A product hard-delete soft-removes the whole product engine-side via `POST /api/v1/ingest/catalog/remove` (contract §3b); a configurable child's deletion keeps the per-SKU out-of-stock path, and disabling a product remains a soft out-of-stock update.
- Contract staleness guard in CI: a dedicated daily "Contract staleness" workflow (`bin/check-contract-staleness.sh`) fails when the vendored `docs/RECENGINE_API_CONTRACT.md` is no longer byte-identical with the engine repo's main branch.
- Hyvä theme compatibility module skeleton (`compat/hyva/`, module `Hyva_SmailyConnect`, to be published as a separate package): framework-free browse tracker and attribution scripts (no RequireJS/jQuery, strict-CSP-safe delivery), a Tailwind-styled personalization page and Tailwind-build registration via `hyva:config:generate`. Not yet verified on a Hyvä store — audit and verification plan in [docs/HYVA_SUPPORT.md](docs/HYVA_SUPPORT.md); excluded from the release ZIP.

**Behavior changes**

- Subscriber sync frequency presets are gone: v3 syncs in near-real-time via observers + a 15-minute consent reconcile.
- The RSS feed lists catalog-visible products only; configurable variants resolve to their parent.

### 2.8.1

Fixes an issue with cron scheduling using wrong interval for daily customer synchronization.

### 2.8.0

> Notice! This version updates the price values in abandoned cart emails and RSS feed items to include taxes. These prices now match what customers see in the storefront. For B2B (business-to-business) stores, where tax-exclusive pricing may be expected, this behavior might not be suitable.

- Abandoned cart `product_price` and `product_base_price` now also include taxes.
- RSS-feed now shows prices including taxes.
- RSS-feed uses parent product URL-s for configurable products that are not visible individually.

### 2.7.7

- Adds `"is_abandoned_cart" = "true"` field to abandoned cart automation payload
- Does not opt-in unsubscribed customers who have received abandoned cart email

### 2.7.6

- fix: RSS feed rendering with missing description value [[#118](https://github.com/sendsmaily/smaily-magento-extension/pull/118)]

### 2.7.5

- fix: Items placement in RSS feed structure [[#114](https://github.com/sendsmaily/smaily-magento-extension/pull/114)]

### 2.7.4

- Fixes an issue where abandoned cart synchronization can fail when unknown payload field is encountered.[[#111](https://github.com/sendsmaily/smaily-magento-extension/pull/111)]

### 2.7.3

- Fixes non-existing array key warning on subscribers synchronization [[#108](https://github.com/sendsmaily/smaily-magento-extension/pull/108)] (thanks @raulikesvatera)

### 2.7.2

- PHP 8.2 compatibility [[#103](https://github.com/sendsmaily/smaily-magento-extension/pull/103)]

### 2.7.1

- Skip abandoned carts receiving "Invalid data submitted" (code: 203) response - [[#99](https://github.com/sendsmaily/smaily-magento-extension/pull/99)]

### 2.7.0

- Add store, store group and website to abandoned cart payload - [[#96](https://github.com/sendsmaily/smaily-magento-extension/pull/96)]

### 2.6.0

- Compare subscriber status change timestamp on newsletter subscriber sync [[#91](https://github.com/sendsmaily/smaily-magento-extension/pull/91)]
- Fix newsletter subscribers sync unsubscribed status value [[#92](https://github.com/sendsmaily/smaily-magento-extension/pull/92)]

### 2.5.0

- Add product image URL to abandoned cart data payload [[#88](https://github.com/sendsmaily/smaily-magento-extension/pull/88)]

### 2.4.0

- Include more context in CRON job logs [[#82](https://github.com/sendsmaily/smaily-magento-extension/pull/82)]
- Fix CRON job logging duplicate lines [[#83](https://github.com/sendsmaily/smaily-magento-extension/pull/83)]
- Optimize abandoned cart CRON job by excluding sent carts [[#84](https://github.com/sendsmaily/smaily-magento-extension/pull/84)]

### 2.3.1

- Test for Magento 2.4.4 compatibility - [[#78](https://github.com/sendsmaily/smaily-magento-extension/pull/78)]
- Convert module schema and data setup to declarative schema - [[#77](https://github.com/sendsmaily/smaily-magento-extension/pull/77)]

### 2.3.0

- Newsletter Subscribers synchronization tracking per website - [[#73](https://github.com/sendsmaily/smaily-magento-extension/pull/73)]
- Make last synchronization datetime configurable in module settings - [[#73](https://github.com/sendsmaily/smaily-magento-extension/pull/73)]

### 2.2.0

- Include store group and website in opt-in form and synchronized data [[#67](https://github.com/sendsmaily/smaily-magento-extension/pull/67)]
- Add automation workflow selection to Newsletter Subscriber settings [[#68](https://github.com/sendsmaily/smaily-magento-extension/pull/68)]

### 2.1.0

- Magento 2.4 compatibility [[#63](https://github.com/sendsmaily/smaily-magento-extension/pull/63)]

### 2.0.0

This is a complete rework of the module. The aim was to make the module configurable by website, i.e. abandoned cart, newsletter subscribers synchronization, opt-in form and Smaily API could be configured for each website. Only reasonable solution was to rebuild the module from ground up, because most (if not all) of the functionality was "Default configuration"-centric.

- Improves efficiency of Newsletter Subscribers and Abandoned Cart CRON jobs [[#36](https://github.com/sendsmaily/smaily-magento-extension/issues/36)]
- Reduces bloatiness of data Helper [[#37](https://github.com/sendsmaily/smaily-magento-extension/issues/37)]
- Fixes double CAPTCHA input fields [[#46](https://github.com/sendsmaily/smaily-magento-extension/issues/46)]

### 1.2.0

- Align synchronization customer first and last name with abandoned cart [[#52](https://github.com/sendsmaily/smaily-magento-extension/pull/52)]

### 1.1.0

- Add new fields `first_name` and `last_name` for abandoned cart export
- Changes `product_qty` field to `product_quantity` to unify template variables across integrations

### 1.0.2

- Fix RSS-feed not rendering with special characters

### 1.0.1

- Fix PHP 5.6 compilation issues

### 1.0.0

- Make using CAPTCHA optional for better integration with pop-up forms

### 0.9.3

- Add Magento CAPTCHA and Google reCAPTCHA option for newsletter sign-up form

### 0.9.2

- Fix compilation issues

### 0.9.1

- Subdomain is now parsed from full URL
- Newsletter signup form uses opt-in autoresponder workflow
- Updated cron frequency values
- Updated abandoned cart timing values
- Customer synchronization is now more efficient as it uses data batching
- Customer unsubscribed status is also updated in store's database
- Uninstall cleans up created tables and columns
- Removed custom newsletter and email template blocks
- Removed subscriber observer as synchronization provides same functionality
- Fixed broken links in settings from

### 0.9.0

- This is the first public release
