# STATUS — Smaily Connect for Magento 2

> **Rule (same as the sibling repos):** this file is updated in the SAME commit
> that changes reality — a finished task, a new blocker, a changed plan. Stale
> status is a defect. If this file and your memory disagree, trust this file
> and fix it.

_Last updated: 2026-07-14 (PRO-1391 final-polish pass — Settings > Connection
tab: in-card intro gated to the wizard only, field/button wording aligned to
sibling wording, font rendering corrected)_

## Where we are

**All 6 v3 phases implemented** (~110 files) on branch `v3`, version
**3.0.0-alpha1 — unreleased**. Current truth:

- **PRO-1391 final-polish done — four refinements on Settings > Connection
  after Erkki's side-by-side review of the PRO-1391 visual-fidelity pass.**
  (1) **In-card intro dropped in the Settings context.** The card's
  `Connect your Smaily account` h2 + long credentials paragraph — carried
  over unchanged from the PRO-1379 rebuild's note that removing it "would
  touch the shared markup, out of scope for a CSS-only pass" — is now gated
  behind the shared `panel/connection.phtml` partial's existing
  `$isSettings` flag (`$block->getData('context') === 'settings'`), the
  same mechanism already used for the subdomain suffix chip and the
  in-card status line. Settings now goes straight from its own outer
  "Connection" h3+description (`settings/index.phtml`, already Settings-
  only) to the fields, matching the design pack's return-visit frame and
  both sibling plugins' `inSettings`-gated `Step1Connect`/`CredentialBlock`
  (confirmed by reading Woo's actual TSX, not just the text-map summary).
  The wizard keeps the intro — untouched. (2) **Field/button wording
  aligned to sibling wording.** "API Username"/"API Password"/
  "Test Connection" → "API username"/"API password"/"Test connection"
  (lowercase second word) in `panel/connection.phtml` (both the default-
  account and mode-A per-language blocks) and `settings/index.phtml`'s tab
  footer, plus the one dependent JS-toast string in `panel/panels-js.phtml`
  ("press Test connection first."). Verified against BOTH siblings' actual
  source (Woo `CredentialBlock.tsx`, Shopify `SmailyConnectForm.tsx` —
  word-for-word identical: "Subdomain" / "API username" / "API password" /
  "Test connection") and the design pack's own `Settings.dc.html` button
  markup ("Test connection"). **Deliberate deviation from the design pack:**
  the pack's mockup literally labels the first field "API subdomain", but
  both siblings and our own existing text-map canonical say plain
  "Subdomain" — kept "Subdomain" per this doc's own tie-break rule
  (sibling wording wins on pack/sibling conflict). i18n: `i18n/en_US.csv` +
  `i18n/et_EE.csv` updated (case-only key renames — Estonian translations
  unchanged; case-insensitive sort order and en↔et parity verified
  unaffected by the rename). (3) **Helper text size — verified, not
  changed.** Live-measured the design pack's own field label/hint/
  description sizes (13px/12px/13px) against our rendered page: already an
  exact match (ported in the original PRO-1391 pass). No further reduction
  applied — going smaller would leave the design's own measured values,
  not approach them. The perceived "still bigger" read traces to (1) and
  (4), not to font-size. (4) **Font rendering ("hairier" than the design)
  — root-caused and fixed.** Playwright-measured the live sandbox's
  computed styles: Magento admin's actual body font is the "Open Sans"
  webfont at default (non-antialiased) smoothing — genuinely different
  from the design pack's system-font stack (`-apple-system, BlinkMacSystemFont,
  "Segoe UI", Roboto, Helvetica, Arial, sans-serif`, i.e. this module's own
  `--font` token, which other components in `smaily-admin.css` already
  individually opt into). Fix: `.smaily-settings { font-family: var(--font);
  -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }`
  plus the same `font-family` on `input`/`select`/`button` (form controls
  don't inherit it from the UA stylesheet). No new font asset — this is the
  OS-installed system stack the design pack itself renders with, just
  correctly wired to our own scope. Scoped to `.smaily-settings` only per
  the task's explicit instruction, not `.smaily-wizard` — the wizard's own
  visual-fidelity pass is separate, still-pending work (same PRO-1391 scope
  note as before), so it keeps inheriting Magento's Open Sans for now;
  follow-up noted below. **Verification:** all four PHP gates green
  (unit/phpcs/phpstan; sandbox `setup:upgrade` + `setup:di:compile`);
  Playwright re-rendered the real Connection tab in BOTH en_US and et_EE
  plus the wizard's step 1 (confirming the intro survives there), zero
  module JS console errors in any of the three renders; side-by-side
  screenshots against the design reference under
  `/home/erkki/.claude/jobs/64b0d00d/tmp/fidelity-shots/`
  (`polish-side-by-side-en.png`, `polish-side-by-side-et.png`,
  `polish-en-content.png`, `polish-et-content.png`,
  `polish-wizard-step1-en.png`). `simplify` skill run over the diff (4
  parallel review angles) — no fixes needed; the two borderline notes
  (a second form-control selector group for `font-family` alongside the
  existing width-focused one; the `.smaily-settings`-only smoothing scope
  vs. also covering `.smaily-wizard`) are both deliberate and already
  documented in-line, not oversights.

- **PRO-1391 done — visual-fidelity pass on Settings > Connection (CSS only,
  no markup/save-logic change).** The PRO-1379 rebuild matched the design's
  structure but rendered flatter than the pack; this closes the polish gap by
  porting the design's *measured* CSS (from the pack's `Settings.dc.html`
  "FRAME 1: CONNECTION TAB", rendered in Playwright as the reference) into the
  scoped admin sheet `view/adminhtml/web/css/smaily-admin.css`, all under
  `.smaily-settings` so nothing leaks into Magento admin or the shared wizard
  step-1 partial. Applied: **gray canvas** behind the tab (`.smaily-tab-panel`
  → `var(--s-bg)` + `24px 28px` padding) with the credential **card** now a
  white 6px-radius panel (`20px 22px` padding); **helper text** demoted to the
  muted `--s-text-3`/`--s-text-2` roles at `--fs-12`/`--fs-13` (was the
  admin-default prominent brown/olive); **field labels** to `--fs-13`/600;
  **inputs** to the design's `8px 11px` padding + `--s-border-strong` 1px
  border; **subdomain suffix chip**, **status pill** and the **Test / Save
  Connection** buttons matched to the pack's padding/radius/weight. One real
  fix surfaced en route: the new generic input-radius rule was overriding the
  subdomain input's left-only radius (chip join not flush) — resolved with a
  specificity bump (`input[type=text]`) so the chip stays flush. **Verified
  side-by-side** against the design reference in BOTH en_US and et_EE on the
  live sandbox (Estonian's longer strings wrap without breaking layout), zero
  module JS console errors; screenshots + references under
  `/home/erkki/.claude/jobs/64b0d00d/tmp/fidelity-shots/`
  (`design-connection-content.png`, `ours-final-en.png`, `ours-final-et.png`,
  `side-by-side-final-en.png`, `side-by-side-final-et.png`). Gates green
  (unit/phpcs/phpstan; sandbox `setup:upgrade` + `setup:di:compile`). **Scope
  note:** the design pack only mocks the single-account frame; the
  multilingual mode-A per-language credential cards (`#smaily-w-accounts`)
  are not restyled here — tracked as a follow-up. The card's intro
  `h2`+description (shared with the wizard partial) is kept and styled to the
  system; the design's return-visit frame omits it, but removing it would
  touch the shared markup, out of scope for a CSS-only fidelity pass.

- **PRO-1379 done — Phase B pilot: Settings > Connection tab rebuilt to the
  target spec.** First screen of the admin-UI reconciliation (§2.3.A),
  scoped deliberately tight to the Connection tab + the shared Settings
  chrome it needs — the other four tabs and the full native-config removal
  (§4.2) are untouched, still tracked as later Phase B work. **Layout**
  (from the design pack, structure/sizing only): an H3 "Connection" title +
  one-line description now sit above the panel (new, Settings-only —
  `settings/index.phtml`); the credential card and its fields are scoped to
  the design's 620px/440px widths (`.smaily-settings #smaily-w-default-account`,
  CSS-only, so the wizard's own step-1 rendering of the same shared
  `panel/connection.phtml` partial is untouched); a "Status:" line
  (`.smaily-pill` + "as "&lt;subdomain&gt;"") now renders in-card under the
  fields (gated behind a new `context=settings` block argument so the wizard
  is unaffected), live-updated in `panel/panels-js.phtml#initAll`; a
  tab-scoped footer (Test Connection | Save Connection | one shared
  InlineStatus) is rendered by the owner template `settings/index.phtml`
  (chrome lives with the template that owns the tab seam, not the shared
  partial) and replaces the in-card Test Connection button for this tab. The
  page-wide footer (generic Save + native-config pointer) auto-hides on any
  tab that renders its own `.smaily-tab-footer` (presence-based, no tab-name
  list) — so migrating another tab later opts it out for free. Save
  Connection reuses the exact `collect.connect()`/`saveStep('connect', …)`
  path the wizard and the old global Save button already used (refactored
  into one `saveTab()` helper in `settings/index.phtml`, shared by both
  buttons) — no new save logic, just a second, tab-scoped entry point into
  it. **Config direction (§4 decision 2, Connection-scoped):** the raw
  PRO-1274 "Overridden for X" banner is removed from the Connection tab —
  `ViewModel\Adminhtml\ConfigOverrides::FIELD_ANCHORS` no longer lists the
  four Connection fields (subdomain/username/password/multilingual mode), so
  the awareness JS in `settings/index.phtml` simply never finds them to
  decorate; the "Need advanced fields? Stores > Configuration…" cross-link
  is now hidden while the Connection tab is active (shown for the other four
  tabs, which still have native-only orphan fields per §4.2, not yet built
  onto our own pages). **Not done, and deliberately not attempted here:**
  automatic "clear a shadowing override on save" for Connection's fields —
  investigation found the website/store-view `core_config_data` rows
  `OverrideDetector` would flag as "overrides" are, for subdomain/username/
  password, indistinguishable in storage from the per-language rows
  multilingual mode A *intentionally* writes (`WizardStepSaver::saveConnect`);
  a blind auto-clear-on-save would delete mode A's own working per-language
  credentials. Making that distinction safely is bigger than this pilot —
  presentation only ships now (no banner, single source), the save-through
  behavior is a tracked follow-up (see below). i18n: 3 new phrases in BOTH
  packs ("Save Connection", the tab description, `as "%1"` account
  attribution — en↔et parity, canonical sort, 408 each). **Verification:**
  unit/phpcs/phpstan gates green; sandbox `setup:upgrade` +
  `setup:di:compile` green; Playwright drove the real admin Settings >
  Connection tab in en_US AND et_EE, screenshots under
  `/home/erkki/.claude/jobs/64b0d00d/tmp/pilot-shots/`, zero module JS
  console errors in either locale, rendered layout matches the design pack's
  `Settings.dc.html` Connection frame.
- **PRO-1369 tail done — admin UI target spec's config decisions resolved
  (doc only, no code changed).** Erkki decided all three open §4 calls: (1)
  one source of truth = our own pages, no duplication; (2) scope is handled
  by us and never shown to the merchant (the PRO-1274 "Overridden for X"
  banner is slated for removal in favour of auto-clear-on-save); (3) native
  `Stores > Configuration` shrinks to advanced-only or disappears; (4) the
  three per-entity Intelligence sync toggles (Catalog/Customers/Orders) are
  removed, matching both siblings. A new §4.2 config field inventory
  (`docs/ADMIN_UI_TARGET_SPEC.md`) walks every `system.xml` field against
  `ModuleConfigPaths`/`WizardStepSaver`/`ConfigOverrides` to fix a target home
  per field, folds the consequences into the per-screen sections (Intelligence,
  RSS's now-removed "Advanced RSS options" deep link, Subscribers/Automations
  native-only orphans). **The four fields that pass initially flagged as
  genuinely ambiguous are now also decided (Erkki, 2026-07-14):**
  `include_guests`/`automation_force_opt_in` get real controls on the
  Subscribers tab and `abandoned_fields` on the Automations tab (all three
  are real features, currently hidden with half-dead persistence code);
  `multilingual_mode`'s native per-website scope is dropped in favour of one
  mode per instance, owned by the Connection tab; `rss/enabled` drops its
  native per-store-view granularity for a single store-wide toggle on the
  RSS tab; `logging/verbosity` gets a home on the Log page. **Net outcome:
  native `Stores > Configuration > Smaily` disappears entirely** — no field
  keeps a native-only or native-advanced home; single source of truth is the
  module's own pages. None of this is implemented yet — it's the target for
  a future Phase B pass, which also needs to delete the live
  `ProductSaveAfter`/`CustomerSaveAfter`/`OrderSaveAfter` observer sync-gates
  behind the removed Intelligence toggles (not just hide the checkboxes),
  and must not rename/restructure any config path without an explicit
  value-migration step (2.8.x upgrades carry credentials at the existing
  `smaily_connect/…` paths).
- **PRO-1369 done — admin UI target spec consolidated.** The design pack's
  layout/visual extract (`docs/audits/2026-07-14-ADMIN_DESIGN_LAYOUT_EXTRACT.md`)
  and the real-functionality + sibling text map
  (`docs/audits/2026-07-14-ADMIN_FUNCTIONALITY_TEXT_MAP.md`) are merged into
  `docs/ADMIN_UI_TARGET_SPEC.md` — the single source Phase B verifies the
  running admin against, per screen: target layout, exposed options mapped
  to real functionality, canonical EST+ENG text (sibling wording wins),
  and an explicit REMOVE list for design-pack leaks (opt-in-mode selector,
  RSS "Store view" dropdown). All ten PRO-1357 findings are mapped to a
  fix location. Three product-direction calls are queued for Erkki, not
  auto-decided: (i) keep vs. remove the native `Stores > Configuration`
  surface, (ii) config-scope override UX refinements, (iii) keep vs. drop
  the per-entity Intelligence sync toggles (both siblings dropped theirs).
- **PRO-1353 done — explicit, consistent store scope for catalog ingest
  (price/URL/language).** Investigation (PRO-1352/1353, 2026-07-14) found
  the backfill collection (`EngineCatalogProcessor::loadPage()`) never set a
  store scope at all (falling back to Magento's implicit current-store
  resolver, undocumented and CLI/cron-context-dependent), while the live
  save/delete path read price off whatever scope the admin's Save controller
  happened to resolve (store 0 unless the merchant picked a store view) —
  the two paths could disagree, and neither was website-aware. Per Erkki's
  binding PRO-1352 decision (no currency field is coming to the wire
  contract; one tenant = one base currency), both paths now resolve through
  ONE canonical store: `CatalogPayloadBuilder::canonicalStoreId()` (the
  default store view of the default website — matching the "default scope"
  concept `Engine\Client` and `Multilingual\AccountResolver` already use).
  The backfill collection calls `setStoreId()` with it explicitly before
  `addUrlRewrite()`/`addPriceData()` (both read the collection's store id at
  call time); the live path (`ProductSaveAfter`/`ProductDeleteBefore` via
  `CatalogPayloadBuilder::build()`) re-scopes the product to it via
  `ProductRepository::getById($id, false, $canonicalStoreId)` before reading
  price whenever the product wasn't already loaded at that scope — a
  deliberate single-pinned-scope simplification, not a per-website fan-out
  (a multi-website install with divergent prices/currencies still ingests
  only the canonical website's price). Every catalog `smaily_ingest_queue`
  row now also carries the resolved `store_id` for audit (the queue's
  existing but previously-unused column). Documented in
  `docs/ARCHITECTURE.md` under "Engine ingest". **Verification:** 2 new unit
  tests pin the price-scope behavior on both paths (`EngineCatalogProcessorTest`
  asserts the collection's `setStoreId()` call; `CatalogPayloadBuilderTest`
  asserts price comes from the canonical-scoped product, and that no reload
  happens when already at that scope) — 148 unit tests green, phpcs 0
  errors, phpstan clean; sandbox `setup:upgrade` + `setup:di:compile` green,
  and a live bootstrap check against the real sandbox product confirmed
  `canonicalStoreId()` resolves to the real default store and the backfill
  collection loads products at that exact scope.

- **PRO-1281 Stage B done — the visual system applied to the screens (PRO-1281
  complete).** Consumed the Stage A tokens + six component classes across the
  real admin templates (a Stage B application section was appended to
  `smaily-admin.css` — screen glue only, tokens/components untouched); no
  functional/behavioral changes. Per screen: **Dashboard** — verdict hero
  (dot + role kicker + text, healthy/degraded/incomplete states via the
  existing verdict logic), connection strip now carries `.smaily-pill`
  (active/off/failed/pending) + sub-lines, metric tiles rebuilt on the
  `.smaily-tile` BEM component (`__label`/`__value`/`__caption`/`__badge`,
  `--attention` on failures), activity status as pills; the legacy single-dash
  tile CSS was removed so the component wins. **Setup Wizard** — stepper gained
  accent circle indices (done = accent check, active = accent ring, via
  tokens); the lawful-basis + multilingual mode cards migrated to the
  `.smaily-choice`/`.is-selected` BEM component (native radio visually hidden
  but focusable, `__radio` indicator, Recommended/Most-common `__badge`); the
  JS `.selected`→`.is-selected` rename is scoped so the two card groups don't
  clear each other. **Settings** — tab strip active-underline recolored to
  accent; per-tab save result rendered through the shared inline-status. The
  shared `result()` helper now renders the Stage A **inline-status** vocabulary
  (CSS-only spinner/check/error glyphs) with state inferred from the message
  (ellipsis = working, ok = saved, else error) — one change covers wizard,
  settings, per-account tests and backfill with zero call-site edits.
  **Engine Automations** — the dense table became `.smaily-engine-trigger`
  cards with a computed run-mode pill (active/test/off from enabled+test_mode);
  the not-connected state is a centered empty-state, the catalog-load-failed
  state a `.smaily-banner--warning` over dimmed saved rows; all `[data-field]`
  hooks preserved (save JS selector updated to `.smaily-engine-trigger`).
  **Log** — native grid untouched; failed-24h banner rebuilt as
  `.smaily-banner--warning`; Details slide-out gained a status pill, an
  info/terminal retry line and PII-redaction tags. **Backfill** — the native
  `<progress>` replaced by the `.smaily-progress` component (track + fill),
  driven by a new `setProgress()` that maps running→striped-accent /
  done→success / done-with-failures & stopped→danger / cancelled→neutral.
  **Carry-over (item 7)** — a deep link to a Smaily config group
  (`#smaily_connect_rss`, reachable from a new "Advanced RSS options" link on
  the RSS tab) lands with the group open; the config assist now opens a
  genuinely-collapsed group defensively (works with either Magento collapsible
  pattern) — in this 2.4.8 build the groups render expanded, so it lands open
  natively. i18n +32 phrases in BOTH packs (en↔et parity, canonical casefold
  sort; 395 each). **Verification:** phpcs 0 errors, phpstan clean, 124 unit
  tests; sandbox `setup:upgrade` + `setup:di:compile` green on the merged
  tree; Playwright drove all six screens in **en_US AND et_EE** — 22/22 checks
  pass (stepper + choice-cards, tab switch + save inline-status, choice-card
  reactivity, backfill progress component, engine empty-state, log Details
  pill + redaction, dashboard tiles/pills/verdict, RSS deep-link opens the
  group, wizard step nav) with **zero module JS console errors** in both
  locales; sandbox admin locale restored to en_US. The engine
  trigger-card active/test/off + validation-error states are now driven live too
  — see the PRO-1288 entry below.
- **PRO-1274 done — Settings page surfaces + clears config-scope overrides
  (option c, Erkki-approved).** The Settings page and wizard always save at the
  DEFAULT scope, but a more-specific `core_config_data` row (the website-scope
  subdomain the 2.8.x migration seeds; the per-store-view credentials
  multilingual mode A writes) shadows it at runtime — so a merchant "saved" a
  value and saw different effective behaviour with no signal. A lightweight
  awareness+clear layer now mirrors Magento's native "Use Default" semantics on
  the module's OWN Settings surface (the system.xml scope switcher under Stores >
  Configuration is untouched). **Detection:** `Model\Config\OverrideDetector`
  reads the config-value collection directly (real stored rows, not merged/cached
  ScopeConfig) for the module's overridable paths and reports every website /
  store-view row that shadows the default, labelled with the website/store name
  (global website-0 rows are skipped — they don't shadow a specific scope).
  **Indicator:** the Settings page (`ViewModel\Adminhtml\ConfigOverrides` maps
  each field's DOM anchor → config path) injects, next to exactly the shadowed
  fields, a `.smaily-banner--warning` with a `.smaily-pill--neutral`
  "Overridden for <scope>" per shadowing scope — reusing the Stage-A component
  classes (only 3 lines of layout glue added, no new component CSS). **Clear
  (= Use Default):** a per-scope button → `window.confirm` →
  `Controller\Adminhtml\Config\ClearOverride` (ACL `Smaily_Connect::config`,
  admin form-key via the shared `AbstractJsonAction`/`?form_key=` contract) →
  `Model\Config\OverrideClearer`, which validates the path against the module
  allowlist (`Model\Config\ModuleConfigPaths`, built from the Config /
  EngineSettings constants so it can't drift) and the scope (websites/stores
  only, never the default the page owns) BEFORE calling
  `WriterInterface::delete($path, $scope, $scopeId)` on that one row, then
  flushes the config cache and returns an honest per-scope result rendered via
  the shared inline-status. Reversible (re-set the override at its scope);
  confirm is the guardrail. New tests: `OverrideDetectorTest` (3: absent /
  single website / two scopes + global-row skip) and `OverrideClearerTest`
  (5: rejects a non-module path with NO delete, deletes exactly the requested
  website + store-view path/scope, rejects default scope + invalid scope id) —
  plus a `ConfigDataCollectionFactory` unit stub. i18n +10 in both packs (en↔et
  parity, 405 each). Gates: 145 unit tests, phpcs 0 errors, phpstan clean;
  sandbox `setup:upgrade` + `setup:di:compile` green (the four new autowired
  classes resolve with no di.xml — and PRO-1292's `AutomationsForm`
  ResolverInterface DI compiled clean on the same tree). Playwright en_US AND
  et_EE against the sandbox: seeded website + store-view overrides render the
  "Overridden for X" markers on the right fields (subdomain showed both the
  website `demo2` and a store-view row; username its store-view row); Use
  default → confirm removed exactly that `core_config_data` row (verified gone
  in the DB, baseline website rows preserved) and the indicator; the Estonian
  pack rendered every string ("Siin salvestatud, kuid alistatud…", "Alistatud:
  …", "Kasuta vaikeväärtust"); zero module JS console errors in both locales.
  Sandbox restored (seeded overrides cleared, admin locale en_US).
- **PRO-1292 fixed — engine-automations trigger title/description are now
  admin-locale-aware.** `ViewModel\Adminhtml\AutomationsForm` read the engine
  catalog's `name_en`/`description_en` only, so trigger titles/descriptions
  stayed English under et_EE while the surrounding chrome localized (the latent
  i18n gap flagged at the end of the PRO-1288 entry below). A new `localized()`
  helper now picks `<field>_<lang>` where `<lang>` is the 2-letter code of the
  resolved admin locale (`et` for et_EE), falling back to the `_en` field when
  the localized field is absent or blank; the locale is resolved via the
  standard `Magento\Framework\Locale\ResolverInterface` (adminhtml-bound to the
  backend resolver — no other module block used a locale resolver before, so
  this introduces the canonical mechanism). Defensive: unknown/unparseable
  locale or a missing/whitespace-only localized field → `_en`. New unit test
  `Test/Unit/ViewModel/AutomationsFormTest.php` (5 cases: et→`_et`, et with
  `_et` missing→`_en`, et with `_et` blank→`_en`, en→`_en`, unknown→`_en`).
  Gates: 137 unit tests green, phpcs 0 errors, phpstan clean. (The `recipe`
  field already had an en/et fallback and is unchanged.)
- **PRO-1288 done — engine-automations connected-states validated live
  (verification only, no code change).** The four states PRO-1281 Stage B left
  un-driven (the sandbox engine was disconnected) were exercised against a
  CONNECTED engine (the shopify-connect `packages/mock-engine`, served on the
  docker bridge at `172.20.0.1:9876`; connected via the real Settings >
  Intelligence setup-exchange). Playwright drove all four in **en_US AND
  et_EE**: **off** — both triggers render `.smaily-pill--off` ("Off"/"Väljas")
  with no stored config (fail-closed default); **active** — a seeded
  enabled+non-test config renders `.smaily-pill--active` ("Active"/"Aktiivne")
  with the green card accent; **test** — enabled+test renders
  `.smaily-pill--test` ("Test mode"/"Testrežiim") with the blue accent;
  **per-field validation error** — an invalid Test Emails value posts to the
  engine, which 422s, and the card's inline-status shows the field-level
  message "winback_risk / test_emails.0: Invalid email …" (Estonian: "… Midagi
  ei salvestatud …") — a named trigger/field, not a raw exception fragment. The
  not-connected empty-state and the catalog-load-failed `.smaily-banner--warning`
  (+ `is-degraded` dimming) were spot-checked and still render (banner driven
  with a revoked-key 401 — a transient 500 is retried and would not surface it).
  **Zero module JS console errors** in either locale. Card markup, computed
  run-mode pill and inline-status all render as designed — no visual fix needed.
  Sandbox restored afterwards: engine disconnected (only
  `smaily_connect/intelligence/browse_tracking=0` remains, as before), admin
  locale back to en_US. Known follow-up (pre-existing, not a PRO-1288
  regression): the engine trigger **name/description** come from the catalog's
  `name_en`/`description_en` only, so they stay English under et_EE while all
  surrounding chrome localizes — a latent i18n gap in the catalog-driven
  content, out of scope here.
- **PRO-1281 Stage A done — Phase 3 design foundation (CSS only, no screens
  touched).** Landed the Design agent's visual system into
  `view/adminhtml/web/css/smaily-admin.css` (already loaded on all four admin
  pages): (1) the `:root` **token sheet** — surfaces/borders, text/link,
  Smaily accent `#e91e63` (selection/focus/progress) kept distinct from
  Magento action-orange `#eb5202` (primary buttons stay native), status role
  trios (success/warning/danger/info/neutral/parked = fg + soft-bg + border),
  banner left-bar colors, 4px-base spacing, radius, type scale, system font
  stacks, elevation — token names copied byte-for-byte from the spec so Stage
  B's per-screen annotations line up. (2) Six **reusable component classes**,
  BEM-ish, matching every documented variant/state: `.smaily-choice`
  (`.is-selected` accent border+ring+tint, radio/title-row/desc/Recommended
  badge — distinct from the legacy orange `.smaily-ui .smaily-choice.selected`,
  which is untouched so current screens don't change), `.smaily-pill`
  (`--active/test/off/sent/pending/failed/parked/neutral` + `__dot`),
  `.smaily-banner` (`--success/info/warning/error`, 4px left bar +
  icon/title/message/action slots), `.smaily-inline-status`
  (`.is-idle/working/saved/error` + CSS-only spinner/check/error glyphs),
  `.smaily-tile` (`--attention` + label-row/badge/value/caption),
  `.smaily-progress` (`.is-running` striped accent → `is-done/failed/stopped`
  recolor). Light adminhtml only (token sheet defines no dark mode). No
  external assets. **Nothing is wired into a template** — that is Stage B.
  Verification: CSS self-consistency checked (every `var(--x)` referenced is
  defined in `:root`; braces balanced); gates green (124 unit, phpcs 0 errors,
  phpstan clean — phpcs lints php/phtml only, and no PHP changed). Browser/
  Playwright visual proof is deferred to Stage B (screens applied), en+et.
- **PRO-1280 done — contract v1.4.1 synced + catalog/order-line identity
  fallback made symmetric.** (1) `docs/RECENGINE_API_CONTRACT.md` overwritten
  byte-identical from engine `945b7ad` (version header now **1.4.1**, Appendix E
  has the `v1.4.1` block; staleness check green). The v1.4.1 changes are
  documentation-only (no wire/schema change): §3 catalog `tags` example gains
  `"product_id": "7620134"`, the cross-variant-grouping bullet is now "live",
  and the §3 identity rule now states Magento's catalog `sku` field IS the
  platform-canonical key (mandatory + store-unique) with `mag-<entity_id>` as a
  fallback ONLY when the SKU field is empty — the "never the merchant SKU field"
  rule is Shopify/Woo-specific. No fixture drift: catalog already emits
  `tags.product_id` as a string (PRO-1231), matching the documented example
  shape. (2) Symmetric-fallback fix: `CatalogPayloadBuilder::sku()` keys an
  empty-SKU product on `mag-<entity_id>`, but `OrderPayloadBuilder` emitted the
  raw `getSku()` (i.e. `""`) with no equivalent — so a pathological empty-SKU
  product's catalog row and order line would key on `mag-<id>` vs `""`, never
  join (broken attribution + cadence), and `""` is a cross-product collision
  magnet. `OrderPayloadBuilder` now routes the line `sku` through a `sku()`
  helper that falls back to `mag-<product_id>` when `getSku()` is empty;
  `sales_order_item.product_id` IS the catalog `entity_id`, so the two paths
  now emit the identical key (contract §3 "Same key from every path"). Pure
  defensive plugin-side change, no engine change. 2 new unit tests
  (empty SKU + whitespace-only SKU → `mag-<product_id>`). Gates: 124 unit
  tests green, phpcs 0 errors, phpstan clean.
- **PRO-1269 fixed — catalog `product_url` is always the clean storefront
  URL, in any execution context.** `getProductUrl()` resolves against the
  *current* app environment, so when the catalog payload is built under
  CLI/cron (the `EngineCatalogProcessor` backfill job, cron flushers) the URL
  could embed the invoking PHP entry script path (observed during the
  2026-07-11 mock-engine walk: `.../run-job.php/smaily-walk-tee.html`) — a
  link that 404s in a recommendation email. `CatalogPayloadBuilder` now routes
  every `product_url` through a `productUrl()` helper that wraps the URL
  generation in forced frontend store emulation (`Store\Model\App\Emulation`,
  `Area::AREA_FRONTEND`, force=true) — the same idiom `Cron\AbandonedCart`
  already uses — with emulation always stopped in a `finally`. The
  multi-language branch emulates each language's representative store; the
  single-language branch emulates the product's own store view, falling back
  to the default store view when the product carries only the admin scope
  (`store_id` 0, the usual CLI/cron/backfill case — not a storefront). New
  unit test `testProductUrlIsBuiltUnderFrontendStoreEmulation` asserts the
  forced-frontend start + always-stop and the clean URL out; the constructor
  gained the `Emulation` collaborator (autowired, no di.xml). Gates: 122 unit
  tests green, phpcs 0 errors, phpstan clean.
- **PRO-1275 fixed — pre-payment guest abandoned carts are now reminded.**
  Magento fills `quote.customer_email` only once payment info is submitted,
  so a guest who typed an email and abandoned at/before the shipping step
  carried it only on the `quote_address` (billing, then shipping). The cron
  keyed the selection AND the recipient on `customer_email`, so those carts
  were never found. Two coordinated changes: (1) `Cron\AbandonedCart` no
  longer filters on `customer_email` alone — a new `requireAnyEmail()` LEFT
  JOINs the billing and shipping `quote_address` rows and widens the WHERE to
  any quote carrying an email in EITHER place (at most one billing + one
  shipping row per quote, so page-size batching is preserved); (2)
  `Model\AbandonedCart\PayloadBuilder` resolves the recipient with the same
  fallback order — `customer_email` → billing address email → shipping
  address email (lowercased/trimmed) — via a new `resolveEmail()`. Consent
  guardrails are untouched: this changes only WHO is found, not the opt-in
  logic (`force_opt_in` still follows the configured lawful basis; the
  already-mailed side table still dedupes; the `is_active`/`items_count`/idle/
  24 h-backlog guards are unchanged). New unit test
  `Test/Unit/Model/AbandonedCart/PayloadBuilderTest.php` (4 cases: customer
  email used when present; NULL customer email → billing address email; empty
  customer + empty billing → shipping address email; no source → empty). New
  unit-test stub `Test/Unit/Support/Stub/ProductCollectionFactory.php`
  (code-generated factory, mirrors the existing ImageFactory stub). Gates:
  121 unit tests green, phpcs 0 errors, phpstan clean. USER_GUIDE
  abandoned-cart section clarified (email can come from the checkout address,
  not just the payment step).
- **PRO-1286 fixed — the missing-workflow-id preserve rule now covers all
  four save surfaces (PRO-1268 follow-up).** The "keep a saved workflow id
  when the posted value is empty AND that id is not in the freshly loaded
  Smaily list (empty/unloadable list = every saved id counts as missing =
  kept)" decision was extracted to one shared method
  `ConfigRowNormalizer::isMissingFromList()` and reused — no copy-paste — on
  the three surfaces PRO-1268 left exposed: (1) **per_language mapping
  fallback** in `ConfigRowNormalizer::normalize` — the old
  `workflowId === fallback` check wiped a per-language map when a missing
  fallback posted empty; the per_language branch now also preserves the whole
  map when the empty post's saved fallback id is missing from the list (a
  present fallback cleared to "-- Not Selected --" still collapses to an empty
  single map — honest clear kept); (2) **Wizard/Settings single-mode selects**
  (`.smaily-w-workflow`) via `WizardStepSaver::saveAutomations` — it now reads
  each saved workflow id from `Config`, resolves the live list once
  (`SmailyClientProvider::forStore(null)`, lazily, only when a workflow key is
  posted), and skips the `configWriter->save` (preserving the stored binding)
  when the posted value is empty and the saved id is missing; a present id
  cleared still writes `0`; (3) **Per-language mapping editor**
  (`MappingSaver`, full-desired-state sync) — `save()` gained an optional
  `array<accountKey, string[]> $availableByAccount`; a stale existing row
  (absent from the desired state) whose workflow id is NOT confirmed in its
  account's live list — including an unresolved account key or an empty/failed
  list — is preserved instead of deleted; `WizardStepSaver` builds the
  per-account lists ('default' + each detected language, so mode A's
  per-language accounts and mode B's shared account are both covered). Callers
  that pass no lists (the 2.8.x migration, plain resaves, tests) keep the plain
  full-sync delete (the param is nullable/opt-in). UI parity: the shared panel
  selects (`fillWorkflows`/`fillMappingSelects` → new `fillOptions`) now render
  a retained-but-missing saved id as a labelled selected option (reusing the
  existing PRO-1268 phrase "Workflow #%1 (not in your Smaily list — kept)", no
  new i18n) so the binding stays visible and re-posts. New tests: 5 unit cases
  on `ConfigRowNormalizerTest` (per_language missing-fallback preserved,
  list-load-failure preserved, present-fallback clear honored, plus the shared
  `isMissingFromList` decision table), a new `Test/Unit/Model/Adminhtml/
  WizardStepSaverTest` (4 cases: missing preserved, list-load-failure
  preserved, present clear honored, new selection stored) and 3 new
  `MappingSaverTest` integration cases (missing-id preserved / present-id clear
  deleted in one full sync, empty-or-unresolved-account-list preserved, opt-in
  behaviour — no lists = plain full-sync delete). Gates: 132 unit + 56
  integration tests green, phpcs 0 errors, phpstan clean, sandbox
  `setup:di:compile` green (the two new autowired constructor args on
  `WizardStepSaver` — `SmailyClientProvider` + `ConfigRowNormalizer` — resolve
  with no new di.xml).
- **PRO-1268 fixed — engine-automations save preserves a binding whose
  workflow id is missing from the Smaily list.** The Campaign Intelligence
  automations block (`config/engine-automations.phtml` → `Automations\Save`)
  rendered its workflow `<select>` only from the freshly loaded Smaily
  workflow list; when a previously saved workflow id was absent (deleted in
  Smaily, or the list failed to load) the option was gone, so an empty post
  silently dropped the binding on save. The `per_language` branch already
  reconstructed its map from the `original_map` hidden field — the
  single-mode branch did not. The per-row normalization was extracted to a
  pure, unit-tested `Model\Automation\ConfigRowNormalizer`; its single-mode
  branch now keeps the saved id (from `original_map`) whenever the posted
  workflow id is empty and the saved id is NOT in the current Smaily list
  (`Automations\Save` fetches that list once via `SmailyClientProvider`; an
  unloadable list = every saved id treated as missing = kept). A saved id
  that IS in the list but was cleared to "-- Not Selected --" is still an
  honest clear. The template also renders the missing id as a visible
  selected option ("Workflow #123 (not in your Smaily list — kept)", new
  i18n phrase in both packs, 363 each). New unit test
  `Test/Unit/Model/Automation/ConfigRowNormalizerTest.php` (8 cases: missing
  id preserved, list-load-failure preserved, deliberate clear honored, new
  selection stored, per_language preserve/collapse, numeric clamps).

- **Real-Smaily-credentials walk done — the last unverified pre-release
  surface is green.** All Smaily campaign-API happy paths exercised against
  a live Smaily test account (Playwright + queue/DB checks + server-side
  API verification; engine stayed disconnected on purpose): Test Connection
  success path (typed creds + saved-credentials state render), workflow
  dropdowns populating with the account's real automation workflows
  (Settings > Automations, wizard step 3, refresh button), storefront
  newsletter subscribe → contact.sync + welcome automation.trigger queue
  rows flushed SENT by cron and the contact verified present/subscribed in
  Smaily via API, contacts backfill from wizard step 2 ("Done, 4 of 4
  synced.", live progress bar, outcome persisted across reload, contacts
  spot-checked server-side), guest checkout with the opt-in checkbox →
  subscriber → contact subscribed in Smaily end-to-end, abandoned-cart cron
  → autoresponder enroll accepted by the real workflow (cart product
  fields verified upserted onto the Smaily contact; force_opt_in=false in
  consent mode per the API guardrail), suppress-opt-in-emails toggle
  round-trip, and the Settings-tabs regression (Connection shows connected,
  Automations loads with no error line, dashboard verdict healthy, unified
  Log all-Sent). **Two real bugs found and fixed:** (1) the workflow list
  used `GET autoresponder.php?status=ACTIVE`, which returns ALL active
  automations — the dropdown offered workflows whose trigger type is not
  "form submitted", and POST autoresponder.php rejects those with the
  misleading 221 "Invalid autoresponder ID" (live-reproduced; the welcome
  trigger failed). Now `GET workflows.php?trigger_type=form_submitted`
  (Woo-client parity — NB: the Shopify repo's "workflows.php is not a real
  route" lesson is wrong, it responds fine on production Smaily) with
  disabled workflows filtered out (enrolling one returns 101 but silently
  sends nothing). (2) `store_group` was always empty in contact +
  abandoned-cart payloads (`getStoreGroup()` magic getter; the real method
  is `getGroup()`) — fix verified live in Smaily contact data. Real creds
  removed from the sandbox afterwards (fake-creds state restored); test
  contacts erased from the Smaily account via `POST contact/forget.php`.
  Hyvä 1.5.2 installed into the sandbox from the public GitHub sources (no
  portal key needed; the exact reproducible recipe — 10 VCS repos incl. the
  non-obvious `magento2-compat-module-fallback` + the Mollie chain,
  `"no-api": true` to dodge the GitHub API rate limit, in-repo module
  registered via an `app/code/Hyva/SmailyConnect` symlink, Node 20 Tailwind
  builds in the vendor theme dirs, `hyva_theme_fallback` config for the
  Luma-fallback checkout — is in `docs/HYVA_SUPPORT.md`). Default store
  view runs `Hyva/default`, the `et` store view stayed on Luma as the
  regression control. Every matrix cell passed (Playwright, mock engine as
  the receiving end): compat tracker/attribution on Hyvä (product_view /
  search / cart_add-via-form-submit with sku, campaign-click cookies,
  consent matrix off/on-without/on-with — identity hint dropped and
  restored), page-context blocks execute, Luma-fallback checkout renders
  Luma with the opt-in checkbox working (toggle persists, order placed,
  checkout_start + checkout_complete fire), personalization page fully
  Tailwind-styled (computed styles prove the `hyva:config:generate` content
  scan), Hyvä's own newsletter form feeds our observers, and the **strict
  CSP column is done for real**: `Hyva/default-csp` + enforced storefront
  CSP with `unsafe-inline` removed — zero CSP violations, context blocks
  hash-whitelisted via SecureHtmlRenderer, tracker/attribution need no
  whitelisting at all (static files only). Luma regression green (base
  tracker via `ajax:addToCart`, et_EE opt-in label). Zero module console
  errors everywhere; one third-party artifact documented (Hyvä's toast
  auto-dismiss throws a benign `Transition was skipped` pageerror — it
  reproduces with all Smaily assets blocked). **One real base-module bug
  found and fixed:** the personalization page was FPC-cacheable, and
  Magento's depersonalization made `PrivacyForm` always render the default
  checked state (a saved opt-out never showed; the cached page would be
  shared within an FPC vary group) — `cacheable="false"` on the form block
  now, the core My Account pattern. Both `TODO(hyva-store)` markers
  resolved (cart_add verified on stock Hyvä; precise note kept for
  third-party AJAX-cart modules that bypass the submit event). Remaining on
  the work pack: compat-package publication (name decided:
  `smaily/module-connect-hyva`) and the Hyvä Checkout boundary
  confirmation. Sandbox left with
  Hyvä on the default store view + Luma on `et`; engine restored to
  disconnected.
- **UI/UX parity phase 2b done (PRO-1272) — observability depth.**
  (1) Per-row **Details** drill-down in the unified Log: an actions column
  whose custom JS component (`js/grid/columns/log-actions`, overriding
  `isHandlerRequired` — the stock actions column attaches NO click handler
  to plain-href actions) loads `Controller\Adminhtml\Log\Details` into a
  native slide-out modal; the template shows attempt count, honest retry
  state (next retry time / "will NOT retry on its own"), last error,
  payload-as-sent and last response, all through the new
  `Model\Log\PayloadRedactor` (secret-looking keys → `[redacted]`, emails
  masked `e***@g***.com` — last_error is masked too, it routinely quotes
  the contact). Mass retry unaffected. (2) Failed-24h banner above the Log
  (reuses `QueueHealth`, hidden at 0) deep-linking to the grid pre-filtered
  via the core `Magento_Ui/js/grid/url-filter-applier` mechanism
  (`?filters[status]=failed`); the dashboard verdict action + failed tile
  link there too. (3) Backfill cancel + outcome honesty: `JobManager`
  transitions are now race-safe conditional UPDATEs (progress writes never
  touch status, terminal transitions only move active rows, so an admin
  cancel always wins; no schema change — `cancelled` already existed), all
  4 processors stop at the next page boundary via a fresh `isCancelled`
  read, `BackfillState` gained `action=cancel` + `finished_at`/`error` in
  the aggregate, and the shared panels render persisted outcomes on load:
  "Done, X of Y synced" / "Done … — N failed" with a pre-filtered Log link
  (parked/pending-retry rows are NOT counted failed) / "Stopped before an
  error" / "Cancelled" (+ timestamp); cancel = fresh start on restart.
  28 new phrases in both i18n packs (invariants held; the follow-up
  hygiene sweep dropped the 2 phrases this pass obsoleted and restored
  the canonical sort — both packs now at 362). Verified:
  99 unit + 46 integration tests (8 new `JobManagerTest` cases covering the
  cancel races), phpcs/phpstan clean, `setup:upgrade` in the sandbox, and
  Playwright en + et_EE (banner + pre-filtered grid, redacted modal, mass
  retry, live cancel of a pending job + fresh restart + completed-with-
  failures outcome + persistence across reload, wizard step 2 shares the
  same controls, zero module JS console errors). Container
  `setup:di:compile` was deferred past this pass (the concurrent phase-2c
  worktree broke the compiler) and has since PASSED on the merged 2b+2c
  tree. Sandbox restored (seeded rows removed, admin locale back to
  en_US).
- **Multilingual UX done (PRO-1273, phase 2c) — browser-validated.**
  Built in the shared-panel idiom (live re-render,
  no save round-trips): (1) routing-mode choice cards on the Connection
  panel (wizard step 1 + Settings > Connection via the shared partial;
  radio-card idiom from the step-2 lawful-basis cards; rendered only when
  `Multilingual\AccountResolver` detects >1 store-view language, else
  locked to `single`; destructive mode-switch guarded by a native
  confirm); (2) mode A UI — per-language credential blocks (per-block
  Test Connection; saved accounts re-test via a new `store_id` fallback
  on the testsmaily endpoint) + default-fallback account picker whose
  account's credentials double as the default scope (new config path
  `smaily_connect/connection/fallback_language`); feeds the existing
  `WizardStepSaver::saveConnect` `accounts[]` handler (extended only
  with `fallback_language` + store-view credential cleanup when leaving
  mode A); (3) mode B/A per-language workflow mapping editor on the
  Automations panel (per trigger: workflow select per language, loaded
  live per account in mode A, + default-fallback radio); writes
  `smaily_automation_mapping` through the new
  `Model\Automation\MappingSaver` (full-desired-state sync: unique-key
  upsert, cleared rows deleted, other websites' rows untouched) behind
  the existing savestep endpoint; (4) **account_key alignment with Woo**
  — `Automation\Router` now returns a `WorkflowMatch` (workflow +
  account_key) and `AutomationHandler` posts through the account the
  mapping row names (fallback rows included; config-default resolutions
  keep following the store view; single/c unchanged); Router lookups now
  also see global rows (`website_id 0` — the scope the editor writes and
  the default-scope migration seeds; website-specific rows win), fixing
  the latent "default-scope migration seeds never matched" gap.
  `Model\Adminhtml\AccountResolver` moved to
  `Model\Multilingual\AccountResolver` (it now serves runtime routing
  too). New tests: `RouterTest` (10 unit — all four modes, fallback,
  account_key, website preference, terminal skips) and
  `MappingSaverTest` (7 integration — upsert idempotency, cleared-row
  deletion, fallback normalization, scope discipline, Router reading
  real SQL back). i18n +32/-1 phrases in both packs (invariants held).
  Docs: USER_GUIDE multilingual section rewritten for the new UI,
  ARCHITECTURE routing + panel sections, CHANGELOG. Verified: 109 unit +
  45 integration tests, phpcs (0 errors) + phpstan clean,
  `setup:upgrade` + `setup:di:compile` in the sandbox on the merged
  2b+2c tree, and the post-merge Playwright pass in en_US AND et_EE:
  mode cards render on the 2-language sandbox with live section
  re-render (no save round-trips); mode-A save lands per-language
  credentials in the right store-view scopes (`stores/1` en, `stores/2`
  et) with the fallback account doubling as the default scope +
  `fallback_language`; per-block Test Connection posts typed creds or
  the saved-account `{store_id}` fallback (fake creds fail
  non-blocking); the client-side guards (incomplete block, missing
  fallback) fire; the destructive confirm appears when leaving a saved
  a/b mode, dismiss reverts, accept switches live, and leaving mode A
  on save removes the store-view credential overrides; the mode-B
  mapping editor renders 3 trigger tables × 2 languages + None row,
  save writes `smaily_automation_mapping` (full-desired-state: the
  migration-seeded `default` rows are replaced, re-save is id-stable
  idempotent, a cleared select deletes its row) and prefills on
  reload; wizard steps 1/3 share the same partials and step gating
  still works; regression sweep green (dashboard verdict, unified Log
  + Details modal + failed-24h banner deep link, backfill cancel
  button, zero module JS console errors); et_EE renders every new
  surface in Estonian. One small UX bug found and fixed in that pass:
  the destructive-mode-switch confirm fired when leaving an a/b mode
  that was merely selected, never saved — the confirm baseline now
  tracks the SAVED mode, so unsaved exploration between the cards
  never asks. Sandbox config/mapping table restored byte-identical to
  pre-test state afterwards.
- **UI/UX parity phase 2a done (PRO-1271) — IA consolidation + full
  dashboard.** The admin is now four pages under Marketing > Smaily
  Connect: **Dashboard** (new landing page: one-sentence health verdict
  reusing the HealthCheck cron's state/query via the extracted
  `Model\Health\QueueHealth`, connection strip, truthful queue-backed
  tiles — catalog tile omitted while the engine is disconnected —
  recent-activity feed, quick links + contextual CTAs), **Setup Wizard**
  (moved to `smaily_connect/wizard`), **Settings** (new tabbed page:
  Connection / Subscribers / Automations incl. the embedded
  engine-automations block / Intelligence incl. engine backfills /
  RSS incl. the URL builder; deep-linkable `?tab=`, per-tab AJAX save via
  the same WizardStepSaver + new `rss` step, live reactivity incl.
  credential-edit → workflow-dropdown refresh) and **Log** (ONE unified
  grid: `Model\ResourceModel\Log\Collection` UNION ALL over both queue
  tables keyed by synthetic `log_id`, source filter, cross-queue mass
  retry). Historical Import and the two old log pages/grids are gone
  (jobs/queues untouched); wizard step content was extracted into shared
  partials (`view/adminhtml/templates/panel/`, shared JS in
  `panel/panels-js.phtml`) that both wizard and Settings render — still
  one config source of truth. Wizard-first gating: setup-incomplete
  installs redirect every Smaily page to the wizard
  (`Model\Adminhtml\SetupGuard`); after a MAJOR version jump (tracked via
  `smaily_connect/internal/last_seen_version`, version read from
  composer.json) a one-time review notice is posted instead of a
  redirect. i18n regenerated (304 phrases, invariants held), docs
  (README/USER_GUIDE/ARCHITECTURE/TESTING/CHANGELOG) updated. Verified:
  93 unit + 38 integration tests, phpcs/phpstan clean, setup:upgrade +
  di:compile in the sandbox, and Playwright en + et_EE (menu = exactly 4
  items, dashboard in degraded/ok states + incomplete-redirect, per-tab
  saves land in `core_config_data` and restore, reactivity without saves,
  unified log filter + 3-row cross-queue mass retry, zero module JS
  console errors); sandbox config/queues restored to pre-test state.
- **2.8.x migration** — sandbox-verified end-to-end: a store on the legacy
  2.8.x extension upgrades via plain `composer update` (same package name) and
  its settings carry over seamlessly.
- **Native admin UX round 1 done** — setup wizard, AJAX config with instant
  feedback, engine automations embedded in the unified automations UI.
- **UI/UX parity phase 1 done (PRO-1270)** — six quick wins from the parity
  analysis: (B1) wizard stepper no longer re-locks completed steps on Back
  and supports forward-clicks through reached steps (session `maxStep`,
  seeded from saved connection/setup-completed state); (B2) all our own
  exception messages that surface in the admin (engine client, Smaily API
  client, provider) are wrapped in `__()`, and the raw passthroughs
  (EngineExchange, Workflows, EnginePing) got translated framing sentences —
  37 new phrases in both CSV packs (invariants held: unique sources, en↔et
  1:1, placeholder parity); (A4) live Feed URL Builder frontend_model on the
  Product RSS Feed config group (category/limit/sort/order → URL +
  copy-to-clipboard with execCommand fallback), linked from the wizard Done
  step; (A5) user-guide links on the wizard Done step, in the post-install
  admin notice (Read Details URL) and as a system.xml section-header comment
  (GitHub URL for now, marked to move to a hosted docs site); (B5) step-2
  sync-field checkboxes are server-rendered instead of jQuery string-built
  HTML; (B6) wizard inline `<style>` extracted to
  `view/adminhtml/web/css/wizard.css` loaded via layout XML. Browser
  re-validated with Playwright in en_US AND et_EE (36+15 checks green:
  stepper behavior, server-rendered labels, builder URL correctness + a live
  200 RSS response for the built URL, clipboard copy, docs links, no module
  JS console errors; before/after screenshot diff of wizard steps 1-2 shows
  only the intended stepper unlock). One pre-existing noise finding: the
  sandbox's bundled `paypal/module-braintree-core` `system.js` throws
  `locations.each is not a function` on the system-config page — third-party,
  not ours.
- **i18n done (PRO-1200)** — `i18n/en_US.csv` (canonical inventory, 242
  phrases) + `i18n/et_EE.csv` (full Estonian pack, Woo-plugin vocabulary);
  covers system.xml, menu/ACL, layout/ui_component XML, phtml `__()` and the
  KO `i18n:` binding. Two previously untranslatable user-facing strings
  wrapped (backfill "already running" notice, wizard unknown-step error).
  Estonian rendering eyeballed in the sandbox (admin wizard/config/grids/menu
  + storefront personalization page); two rendering bugs found and fixed —
  see the browser-validation entry below. The checkout opt-in checkbox is
  now also verified in-browser on the et_EE storefront ("Liitu meie
  uudiskirjaga") — see the engine happy-path entry.
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
  English — now translated server-side with `__()` + `escapeJs`.
- **Engine happy paths sandbox-verified against the mock engine** (Playwright
  + the shopify-connect `packages/mock-engine` served on the docker bridge):
  wizard step-4 setup exchange (credentials/endpoints map/config stored,
  ping ok, health-check flags clear), embedded engine-automations form
  (catalog §11 loads, PUT §13 all-8-keys upsert lands with
  `configured_via=plugin`, state persists across reload), storefront
  browse tracking end-to-end (product_view / cart_add / checkout_start /
  checkout_complete through the `smaily/relay` proxy to engine browse
  ingest; a campaign-click landing carries `smaily_visitor_token` + rec id
  + ctx; cookie-restriction-ON sender-side anonymous mode verified both
  without and with the consent cookie), live catalog/order ingest + engine
  catalog/customers/orders backfills (queue rows drain to `sent`,
  `tags.product_id` present in catalog payloads), §3b hard-delete →
  `catalog_remove` row → `POST ingest/catalog/remove` (PRO-1231), and
  guest checkout opt-in checkbox rendering + toggle persistence
  (`smaily/checkout/optin` → `smaily_abandoned_cart.newsletter_optin`),
  incl. the et_EE label "Liitu meie uudiskirjaga". Four real bugs found
  and fixed in that pass: (1) `parseSetupInput` forced https and DROPPED
  the port from a pasted setup URL — any engine not on 443 was
  unreachable; now preserves the pasted scheme+port like Woo's
  `parse_setup_url`; (2) the tracker's `consentRequired` flag serialized
  as string `"0"` (truthy in JS), so with cookie restriction OFF the
  identity hint was dropped from every browse event — cast to bool
  (Magento's cookie helper lies about `@return bool`); (3) the
  page-context inline `<script>` was blocked by CSP on checkout (Magento
  enforces CSP there by default), silently losing every `checkout_start`
  event — now rendered via `SecureHtmlRenderer`; (4) all three admin
  grids rendered colliding/duplicated rows because Magento's client-side
  grid storage keys rows by `entity_id` (our PK is `id`, and the queue
  tables carry an unrelated `entity_id` payload column) — fixed with
  `storageConfig.indexField=id` in the three listing XMLs. Not covered by
  the mock: the Smaily campaign-API side (workflow lists, contact sync —
  the sandbox keeps deliberately fake Smaily credentials), so those flows
  still ended in their previously validated failure paths. Sandbox now
  has 2 seeded products (SMAILY-TEE/SMAILY-MUG), 2 guest test orders and
  completed backfill/queue history; engine config was restored to
  disconnected (`browse_tracking=0`) after the pass.
- **Multilingual routing sandbox-verified end-to-end (spike for the Phase 2
  wizard choice-cards)** — all four modes exercised against the sandbox with
  a second store view (`et`, locale et_EE, kept in the sandbox for future
  passes): store-view→language resolution (`Multilingual\LanguageResolver`),
  mode A per-store-view credential selection (`SmailyClientProvider`), and
  the `Automation\Router` matrix (single/c → config defaults; a/b →
  per-language `smaily_automation_mapping` row, then `is_default_fallback`
  row, then config default; unmapped trigger → terminal skip). Live event
  path proven: a real subscriber save on the et store view enqueued
  contact.sync (store_id-scoped credentials, `contact.language=et`) and an
  automation.trigger whose payload routed to the per-language workflow via
  the et account. Verdicts: single/C WORK, A works (credentials via
  store-view config scope; wizard `accounts` save path exists server-side
  but no UI feeds it), B routing works but per-language mapping rows have
  NO admin write path (only the 2.8.x migration seeds `default` fallback
  rows) — the mapping UI is the Phase 2 build. One user-facing defect fixed
  in this pass: the wizard step-3 note falsely claimed per-language routing
  is configured under Configuration > Automations — now points at the real
  Multilingual Mode field (phtml + both i18n packs). The `account_key`
  divergence from Woo found here (mapping rows' account ignored, mode-A
  fallback rows firing through the wrong account) is FIXED in phase 2c —
  see the PRO-1273 entry above.
- **Engine contract v1.4.1 adopted + verified** (engine commit `945b7ad`,
  byte-identical with the engine repo; PRO-1280 — documentation-only bump over
  v1.4.0/d35bb96); **contract staleness CI added** (commit 5bc3767,
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
- **Gates green:** 132 unit tests, 56 integration tests, phpcs 0 errors,
  phpstan clean. `setup:di:compile` re-verified in the docker sandbox after
  the PRO-1286 change (two new DI-autowired constructor args on
  `WizardStepSaver`, no new di.xml).
- **Hyvä compat skeleton + work package done (PRO-1201)** — full storefront
  audit with file:line evidence in `docs/HYVA_SUPPORT.md`. Compat
  module `Hyva_SmailyConnect` under `compat/hyva/` (standard Hyvä pattern:
  `hyva_` layout handles, composer `smaily/module-connect-hyva`,
  Tailwind registration observer for `hyva:config:generate`): vanilla-JS
  ports of tracker + attribution delivered as static files + inert JSON
  config blocks (no inline executable script — strict-CSP-safe; no
  RequireJS/jQuery; `cart_add` captured from the `checkout/cart/add` form
  submit since Hyvä has no `ajax:addToCart`), plus a Tailwind-styled
  personalization form. Audit verdicts: tracker NEEDS-JS-PORT (done),
  attribution NEEDS-COMPAT-TEMPLATE (done), context blocks / checkout
  opt-in (Luma-fallback checkout) / newsletter / RSS / privacy form
  WORKS-AS-IS, Hyvä Checkout OUT-OF-SCOPE. The compat dir is inert in the
  main package (nothing loads its registration.php) and excluded from the
  release ZIP. Since verified on a real Hyvä store — see the matrix entry
  at the top of this list.
- **Upstream proposal package drafted (PRO-1198)** —
  `docs/UPSTREAM_PROPOSAL.md`: executive summary, 2.8.x compatibility story,
  staged review plan, Marketplace re-submission as "Smaily Connect",
  pipeline/secret hand-over, honest open items, and the one-way-door decision
  checklist. Awaiting Erkki's review; NOTHING sent or published — the release
  decision and all contact with Smaily are Erkki's alone.

## Open Linear issues

| Issue | What | Priority |
|---|---|---|
| PRO-1198 | Release coordination with Smaily (upstream/Marketplace path) | High — Erkki's decision |
| PRO-1201 | Hyvä theme work package | — |
| PRO-1281 | Phase 3 design-led polish — DONE (Stage A tokens/components + Stage B screens; Playwright en/et green) | — |
| PRO-1288 | Engine-automations connected-states validation — DONE (off/active/test/validation-error driven live en/et; no code change) | Low |
| PRO-1292 | Engine-automations trigger title/description i18n — DONE (locale-aware `name_<lang>`/`description_<lang>` with `_en` fallback; unit-tested) | Low |
| PRO-1274 | Settings vs config-scope overrides — DONE (option c: detect + "Overridden for X" indicator + Use-Default clear with path allowlist; Playwright en/et green) | Medium |

Closed 2026-07-11: PRO-1199 (integration suite), PRO-1200 (i18n), PRO-1202 /
PRO-1242 (contract v1.4.0), PRO-1231 (product-delete §3b), PRO-1252
(staleness CI). Cross-repo asks filed: PRO-1266 (Shopify contract sync),
PRO-1267 (engine: Magento product-identity contract note).

## Known gaps

- **Real-engine-tenant click-through still owed** — the Smaily
  campaign-API side is now verified against a live Smaily account (see the
  walk entry above) and the engine side against the mock engine; one pass
  with a real engine tenant remains a nice-to-have before release.
- **Hyvä third-party AJAX-add-to-cart modules unverified** — the compat
  `cart_add` capture is verified on stock Hyvä 1.5.2 (form POST), but
  modules that submit programmatically (`form.submit()` fires no submit
  event) would bypass it; the documented fallback is a
  `private-content-loaded` cart-diff listener, to be added if a real store
  shows gaps.

## Questions / tasks for Erkki

1. PRO-1198 — release coordination with Smaily (High; blocks any public
   release path). The proposal package is drafted
   (`docs/UPSTREAM_PROPOSAL.md`) and ready for your review; the decision
   checklist at its end lists the one-way doors in recommended order.
2. PRO-1201 — Hyvä boundary decisions (see "Open release decisions" in
   `docs/HYVA_SUPPORT.md`; the verification matrix itself is now fully
   executed and green): (a) confirm Hyvä Checkout (commercial, Magewire)
   stays out of scope for the first Hyvä release — free Hyvä's
   Luma-fallback checkout is the supported path and is verified working.
   The compat package vendor/name is decided: `smaily/module-connect-hyva`
   (module PHP name stays `Hyva_SmailyConnect` per the Hyvä convention);
   actual publication remains part of the release train.
