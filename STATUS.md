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
- **Engine contract v1.4.0 adopted + verified** (commit d35bb96, byte-identical
  with the engine repo); **contract staleness CI added** (commit 5bc3767,
  `.github/workflows/contract-staleness.yaml` + `bin/check-contract-staleness.sh`).
- **Gates green:** 73 unit tests, phpcs clean, phpstan clean. `setup:upgrade` +
  `setup:di:compile` verified in the docker sandbox.

## Open Linear issues

| Issue | What | Priority |
|---|---|---|
| PRO-1198 | Release coordination with Smaily (upstream/Marketplace path) | High — Erkki's decision |
| PRO-1199 | Integration test suite + CI MySQL | — |
| PRO-1200 | i18n: en_US / et_EE translation packs | — |
| PRO-1201 | Hyvä theme work package | — |
| PRO-1231 | Product-delete → engine catalog/remove (§3b) | Low / TBD |

## Known gaps

- **Wizard/UX JS flows not yet browser-tested** — verified only via
  `setup:di:compile` + code review; a manual click-through in the sandbox is
  owed before any release.
- **Hyvä untested** (PRO-1201).
- **GitHub secret `ENGINE_CONTRACT_READ_TOKEN` not yet set** — the contract
  staleness workflow fails with "CANNOT CHECK" until Erkki adds the
  fine-grained PAT (contents:read on `erkkimarkus/smaily-recommendations`).

## Questions / tasks for Erkki

1. PRO-1198 — release coordination with Smaily (High; blocks any public
   release path).
2. Set the `ENGINE_CONTRACT_READ_TOKEN` repo secret (see Known gaps).
