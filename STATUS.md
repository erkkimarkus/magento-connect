# STATUS — Smaily Connect for Magento 2

> **Rule (same as the sibling repos):** this file is updated in the SAME commit
> that changes reality — a finished task, a new blocker, a changed plan. Stale
> status is a defect. If this file and your memory disagree, trust this file
> and fix it.

_Last updated: 2026-07-11_

## Where we are

**All 6 v3 phases implemented** (~110 files) on branch `v3`, version
**3.0.0-alpha1 — unreleased**. Current truth:

- **2.8.x migration** — sandbox-verified end-to-end: a store on the legacy
  2.8.x extension upgrades via plain `composer update` (same package name) and
  its settings carry over seamlessly.
- **Native admin UX round 1 done** — setup wizard, AJAX config with instant
  feedback, engine automations embedded in the unified automations UI.
- **i18n done (PRO-1200)** — `i18n/en_US.csv` (canonical inventory, 242
  phrases) + `i18n/et_EE.csv` (full Estonian pack, Woo-plugin vocabulary);
  covers system.xml, menu/ACL, layout/ui_component XML, phtml `__()` and the
  KO `i18n:` binding. Two previously untranslatable user-facing strings
  wrapped (backfill "already running" notice, wizard unknown-step error).
  Estonian rendering eyeballed in the sandbox (admin wizard/config/grids/menu
  + storefront personalization page); two rendering bugs found and fixed —
  see the browser-validation entry below.
- **Admin UX browser-validated end-to-end** (Playwright vs the sandbox):
  login, menu, wizard all 5 steps (per-step AJAX saves verified in
  `core_config_data`, step gating, non-blocking bad-credential Test
  Connection, backfill start + progress polling, graceful engine-exchange
  failure), config page (Test Connection without save, AJAX workflow
  dropdowns, embedded engine automations block), event/ingest/backfill grids
  with intro blocks, zero module JS console errors, and the et_EE locale
  pass. Two i18n rendering bugs fixed in that pass: (1) grid intro texts
  used layout-XML `translate="true"`, whose evaluation is frozen into the
  locale-agnostic admin layout cache — now translated at render time in
  `intro.phtml`; (2) all `$t()` strings in phtml inline scripts (wizard,
  config assist, engine automations) never reach `js-translation.json`
  (Magento only collects from `.js`/`.html`) so they always rendered
  English — now translated server-side with `__()` + `escapeJs`. Not
  browser-verified: happy paths against real Smaily/engine services, the
  checkout opt-in checkbox rendering (sandbox catalog has no products; its
  label is confirmed present in the storefront `js-translation.json`).
- **Engine contract v1.4.0 adopted + verified** (commit d35bb96, byte-identical
  with the engine repo); **contract staleness CI added** (commit 5bc3767,
  `.github/workflows/contract-staleness.yaml` + `bin/check-contract-staleness.sh`).
- **Integration test suite + CI MySQL done (PRO-1199)** — 35 tests against a
  real MySQL 8.4: 2.8.x settings/schema migration (config mapper on real
  `core_config_data`, password re-encryption, mapping seeding, quote-column
  cleanup), event/ingest queue semantics (idempotent enqueue, claim tokens,
  backoff, parking, stale recovery, janitor retention) and the two flush
  crons with stubbed transports. Harness = standalone `Magento\Framework`
  object graph (`Test/Integration/Support/TestEnvironment.php`), NOT the full
  Magento TestFramework (needs a whole app + search engine — trade-off
  documented in TESTING.md). New CI job `integration` with a MySQL 8.4
  service; unit/static jobs untouched.
- **Product delete → engine §3b done (PRO-1231)** — catalog payloads emit
  `tags.product_id` (parent entity id via a new `ParentProductResolver`;
  `sku` keying untouched per PRO-1267); a
  parent/standalone hard-delete enqueues a `catalog_remove` queue row that
  `FlushIngestQueue` drains through its own non-D6 path to
  `POST /api/v1/ingest/catalog/remove` (endpoints-map key
  `ingest_catalog_remove`, hardcoded-path fallback for pre-v1.4.0
  exchanges; `not_found` = success). Configurable-child delete keeps the
  per-SKU `in_stock=false` soft path; disabled products stay on the
  ProductSaveAfter soft path. Mirrors Woo PRO-1230 (commit 92768d5).
- **Gates green:** 89 unit tests, 38 integration tests, phpcs clean, phpstan
  clean. `setup:upgrade` + `setup:di:compile` verified in the docker sandbox
  (pre-PRO-1231; the new code is observer/DI-only — re-verify with the next
  sandbox pass).

## Open Linear issues

| Issue | What | Priority |
|---|---|---|
| PRO-1198 | Release coordination with Smaily (upstream/Marketplace path) | High — Erkki's decision |
| PRO-1199 | Integration test suite + CI MySQL | Done in repo — close after review |
| PRO-1200 | i18n: en_US / et_EE translation packs | Done in repo — close after review |
| PRO-1201 | Hyvä theme work package | — |
| PRO-1231 | Product-delete → engine catalog/remove (§3b) | Done in repo — close after review |

## Known gaps

- **Happy paths against real services untested** — all admin UX flows were
  browser-validated against failure/empty paths (no real Smaily or engine
  credentials in the sandbox); a click-through with real credentials is
  still owed before release. Checkout opt-in checkbox rendering also
  unverified in-browser (sandbox catalog has no products).
- **Hyvä untested** (PRO-1201).
- **GitHub secret `ENGINE_CONTRACT_READ_TOKEN` not yet set** — the contract
  staleness workflow fails with "CANNOT CHECK" until Erkki adds the
  fine-grained PAT (contents:read on `erkkimarkus/smaily-recommendations`).

## Questions / tasks for Erkki

1. PRO-1198 — release coordination with Smaily (High; blocks any public
   release path).
2. Set the `ENGINE_CONTRACT_READ_TOKEN` repo secret (see Known gaps).
