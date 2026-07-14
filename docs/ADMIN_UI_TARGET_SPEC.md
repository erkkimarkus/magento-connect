# Admin UI target spec (PRO-1369 — consolidation of A1 + A2)

**Status:** authoritative reconciliation. Supersedes the design pack
(`docs/Magento Connect admin visual system.zip`) and the two standalone
analyses below as the thing Phase B builds against.

**Inputs:**
- `docs/audits/2026-07-14-ADMIN_DESIGN_LAYOUT_EXTRACT.md` (A1) — design pack's
  layout + element visuals per screen.
- `docs/audits/2026-07-14-ADMIN_FUNCTIONALITY_TEXT_MAP.md` (A2) — our real
  functionality + sibling (Woo/Shopify) options and canonical EST+ENG texts.
- PRO-1357 findings (the visual/functional audit that triggered this work).

---

## 1. Purpose + authority model

Two independent, read-only analyses were run in parallel because the design
pack cannot be trusted for anything except pixels: it invents options and
copy that don't exist anywhere in this module or its siblings. This document
merges them into one spec so Phase B has a single target to verify the
running admin against, instead of two documents that might disagree.

**Binding authority — unchanged from the task that produced A1/A2:**

- **Design pack → layout and element visuals ONLY.** Structure, hierarchy,
  spacing, component anatomy/states, design tokens. Never its wording, never
  a feature it merely implies.
- **Functionality, decisions, texts → our real functionality + the sibling
  plugins (Woo `../connect`, Shopify `../shopify-connect`).** Sibling wording
  wins on conflict; where a control exists only in this module, our own
  already-shipped, already-bilingual (`i18n/en_US.csv`/`et_EE.csv`) wording is
  canonical.
- **Design-pack leaks get REMOVED, not restyled.** Two confirmed by A2:
  (1) the "single/double opt-in mode" selector + its reactive
  confirmation-email picker (Subscribers tab); (2) the RSS builder's
  "Store view" dropdown. (A2 reclassified the RSS "named category/collection
  picker" as a real feature both siblings have that we're simply missing —
  it is a **follow-up feature candidate**, not part of this spec's build
  scope, and not a leak.)

**How to use this document (Phase B):**

1. For each screen below, open the real running page in the sandbox
   (`docker compose up -d`, `localhost:8080/admin`) in both `en_US` and
   `et_EE`.
2. Compare the rendered layout against **(a) target layout** — fix visual
   drift.
3. Compare the rendered controls against **(b) exposed options/controls** —
   every control listed must exist and work; nothing else should be there.
4. Compare rendered copy against **(c) EST+ENG text** — fix wording drift;
   where a "harmonize" note is present, that is a deliberate, tracked
   wording change (not urgent, but in scope if you're already touching that
   control).
5. Confirm nothing in **(d) REMOVE** is present. If it is, delete it — do not
   restyle it.
6. Cross-check against **§3 (findings map)** — each of the ten PRO-1357
   findings has an explicit fix location; use it to confirm nothing was
   dropped.
7. Do **not** decide anything in **§4 (open decisions)** unilaterally — those
   are Erkki's calls. Where a decision is pending, the current shipped
   behaviour stays as-is.

---

## 2. Per-screen target spec

### 2.1 Dashboard

**(a) Target layout** (source: A1 §2.1, `Dashboard.dc.html`):

1. Page title row: H3 "Smaily Connect" + subtitle "Newsletter & automation
   sync".
2. Optional top **Banner** (full width, only in non-healthy states).
3. **Verdict hero** — one card: status dot + kicker label + verdict sentence
   + optional trailing CTA button, 5px left bar in level color, soft tint
   background.
4. **Connection strip** — 3-column grid, one card per connection (Smaily /
   Campaign Intelligence / Browse tracking): dot + label + sub-line + a
   **Pill** (automation-mode family: active/off/failed).
5. **Metric tiles** — 4-column grid, `.smaily-tile` component (default +
   `--attention` variant), reflows 4→2→1.
6. Lower two-column area (`1.7fr 1fr`): "Recent activity" panel (title +
   "View full log →" link, then Pill + truncated text + relative time per
   row, or an empty state) + "Quick links" panel (label + "→" rows).

This layout is already implemented per STATUS.md's PRO-1281 Stage B entry —
this section exists as the acceptance baseline for Phase B's visual
verification pass, not a new build.

**(b) Exposed options/controls** (this is a read-only operational page, no
form fields — source: A2 §F, `ViewModel\Adminhtml\DashboardData`):

| Element | Real functionality |
|---|---|
| Verdict hero | priority-ordered from setup completeness, `getFailedLast24h()`, `isEngineDown()` |
| Connection strip | live pills from `isSmailyConnected`, `isEngineConnected`/`isEngineDown`, `isBrowseTrackingEnabled` |
| Metric tiles | Contact syncs delivered 30d, Catalog items delivered 30d (only if engine connected), Queued today, Failed 24h — real local queue queries |
| Recent activity | last 10 queue rows: Source/Type/Entity/Status/Updated |
| Quick links | Settings, Log, Setup Wizard, Stores > Configuration |

The design pack's specific dependency wording ("Intelligence/Browse tracking
→ Off with reason 'Requires Smaily connection'" when Smaily is disconnected)
and the exact metric-tile name set are **design-implied, not validated as
verbatim spec** — A2 confirms the tile set and connection-strip shape exist,
but the pack's literal state-by-state copy (e.g. "Not connected", specific
banner sentences) is not sourced from our shipped strings; use the (c) table
below, not the pack's prose, for copy.

**(c) EST+ENG text** (source: our own `i18n/en_US.csv`/`et_EE.csv`, real +
bilingual):

| EN | ET |
|---|---|
| Recent activity | Viimane tegevus |
| Quick links | Kiirlingid |
| Settings / Log / Setup Wizard | Seaded / Logi / Seadistusviisard |
| Source / Type / Entity / Status | Allikas / Tüüp / Kirje / Olek |
| "Setup is not finished yet — complete the setup wizard to start syncing." | (shipped) |
| "Smaily Connect is running, but %1 delivery(ies) failed in the last 24 hours." | (shipped) |
| "Everything is running — deliveries to Smaily are flowing normally." | (shipped) |

**Wording-harmonization candidates (non-blocking, sibling wins per binding
rule — apply in a future copy pass, not urgent):** Shopify is the only
sibling with a real ongoing dashboard.

| Control | Current (ours) | Target (sibling wins) |
|---|---|---|
| Verdict — failures state | "Needs attention" | "Running — with failures to review" (Shopify) |
| Verdict — all-clear state | "All systems normal" | "Everything's running" (Shopify) |

**(d) REMOVE — design-pack leak:** none. Dashboard is read-only; nothing in
the pack invents a setting here.

---

### 2.2 Setup Wizard

**(a) Target layout** (source: A1 §2.2, `Setup Wizard.dc.html`):

- Two-column shell: left stepper rail (fixed width, `smaily-stepper`-style
  circular index markers — done=accent-filled+check, active=accent-ring,
  upcoming=gray) + right content pane (kicker "Step N of 5" → H3 title →
  intro paragraph → step body → footer with Back (ghost) left, primary/
  secondary buttons right-aligned).
- Saving state: fields collapse to a compact card; button disables (never
  swaps its own label) while an **InlineStatus** (`working`) plays beside it,
  `nowrap`, layout never reflows.
- Error state: top error **Banner** + field-level danger styling and message
  on the offending field — the two-layer error model (banner for the
  transaction, field styling for the specific input) is a real, already
  described pattern (Phase-1 fix, STATUS.md "UI/UX parity phase 1").
- Completed-revisit state: kicker carries a Pill(`active`, "Completed"); body
  becomes a read-only summary card; footer gets "Edit credentials" +
  InlineStatus(`saved`). Rail: **completed steps stay unlocked/clickable
  forward, no re-lock on Back** — this is a real, already-shipped behaviour
  (STATUS.md PRO-1270 "B1").
- Multilingual routing step: intro sentence templated with the store's
  detected language count; a stack of **Radio Choice-Card** instances (18px
  circular indicator, title + optional "Recommended"/"Most common" badge +
  description, selected = accent border + tint + ring); a **live-reactive
  region** (dashed accent-tinted border) appears only for modes that need
  per-language credential blocks, each block = language chip + Subdomain/
  Username fields + InlineStatus.

**(b) Exposed options/controls** (source: A2 §I, confirmed —
`wizard/index.phtml`):

The wizard is 5 steps (**Connect → Subscribers → Automations → Intelligence
→ Done**), each step reusing the exact same partials as the matching
Settings tab (2.3 below) — **not** the design pack's 5-step naming
("Consent & lawful basis", "Multilingual routing", "Review & finish"), which
does not match the real step set. Wizard-only chrome: stepper with done/
active states, Back/Continue/Finish footer, and a step-5 "Done" summary card
with links to Settings/Log/user guide.

Backfill (contacts) appears embedded in the Subscribers step, not as its own
wizard step — see 2.7.

**(c) EST+ENG text** (source: our own i18n, real + bilingual):

| EN | ET |
|---|---|
| Connect / Subscribers / Automations / Intelligence | Ühendus / Tellijad / Automaatikad / Intelligence (brand term, intentionally untranslated) |
| Done (step label) | **[i18n gap — see §5]** |
| You are all set! | Kõik on valmis! |
| Back | (shipped) |
| Continue / Finish | (shipped) |

Step-body controls (Connection fields, sync-mode radios, automation
mappings, Intelligence setup) reuse the Connection/Subscribers/Automations/
Intelligence text tables in 2.3 verbatim — the wizard has no separate copy
for these.

**(d) REMOVE — design-pack leak:**

- The multilingual step's design-pack framing ("3-option model: one account
  all languages / one account per-language automations / separate account
  per language" as if that were the full and only model) — **do not build
  a 3-option chooser.** The real model has **4 modes** (Single language /
  Per-language Smaily accounts / One account, per-language workflows / One
  workflow branching by language) — see Connection (2.3.A) for the real
  set and wording.
- The design pack's "switching away from a per-language mode triggers a
  destructive-switch confirm" is **not a leak** — it's real, already
  shipped (STATUS.md PRO-1273: "the destructive confirm appears when
  leaving a saved a/b mode... baseline tracks the SAVED mode").
- The Subscribers-step opt-in-mode selector — see 2.3.B REMOVE.

---

### 2.3 Settings

**(a) Target layout — common shell** (source: A1 §2.3):

- Tab strip (horizontal, top of content): active tab = 2px accent
  bottom-border + 700 weight; inactive = 600 weight, transparent border.
  Scrolls horizontally on narrow widths / long Estonian labels, never wraps.
- **5 tabs, in this order: Connection, Subscribers, Automations,
  Intelligence, RSS** — the pack's tab set and this order line up 1:1 with
  the real panel files (`panel/{connection,subscribers,automations,
  intelligence,rss}.phtml`).
- Below the strip: H3 tab title + one-line description, a white content card
  (border, radius 6px, padding), then a **tab-scoped footer**: action
  button(s) + one InlineStatus per tab. **No global save bar — every tab
  owns its own save + status**, already the shipped idiom (per-tab AJAX
  save, STATUS.md "UI/UX parity phase 2a").

#### 2.3.A Connection tab

**(a) Layout:** 3-field form (Subdomain + fixed `.sendsmaily.net` suffix
chip / API Username / API Password + hint), max-width 440px inside the
620px card; status line below ("Status:" + Pill(`active`,"Connected") + "as
'<account name>'"); when the store has >1 detected language, the
multilingual routing-mode choice cards render above/around the form (see
2.2 layout). Footer: Test Connection (secondary) | Save Connection
(primary) | InlineStatus.

**(b) Exposed options/controls** (source: A2 §A, confirmed):

| Control | Real functionality |
|---|---|
| Subdomain | normalizes a pasted full URL to bare subdomain |
| API Username / API Password | plain text / encrypted |
| Test Connection | AJAX, tests as-typed credentials, no save |
| Multilingual routing mode (4 cards, shown only when store views span >1 detected language) | drives which section renders below; saved as `multilingual_mode` |
| Per-language account blocks (subdomain/username/password/Test per language) | mode "Per-language Smaily accounts" only |
| Default fallback account picker | same mode only — which account's credentials serve unmatched scope + become the default-scope credentials |

**(c) EST+ENG text** (source: our own i18n):

| EN | ET |
|---|---|
| Subdomain | Alamdomeen |
| API Username | API kasutajanimi |
| API Password | API parool |
| Test Connection | Testi ühendust |
| Single language | Üks keel |
| Per-language Smaily accounts | Keelepõhised Smaily kontod |
| One account, per-language workflows | Üks konto, keelepõhised töövood |
| One workflow branching by language | Üks töövoog, mis hargneb keele järgi |
| Most common (badge) | Levinuim |
| "Connect your Smaily account" | "Ühenda oma Smaily konto" |

**Wording-harmonization candidates (non-blocking, sibling wins — both Woo
and Shopify converge word-for-word on all 4 mode names):**

| Mode | Current (ours) | Target (sibling wins, both identical) |
|---|---|---|
| A | Per-language Smaily accounts | Separate Smaily accounts |
| B | One account, per-language workflows | One account, per-language automations |
| C | One workflow branching by language | One account, one automation with branches |

**(d) REMOVE:** none specific to Connection.

#### 2.3.B Subscribers tab

**(a) Layout:** design pack shows one field ("Opt-in mode" select) plus a
reactive confirmation-email picker — **this entire control is a leak, see
(d).** The real layout target is the wizard-step-2 shape reused here: a
sync-mode radio-card group (Radio Choice-Card component per A1 §1.2) + an
"extra fields to sync" checkbox set + the backfill card (2.7) + the two
toggle checkboxes (checkout newsletter checkbox, suppress-Magento-opt-in-
emails). Footer: Save Subscribers | InlineStatus.

**(b) Exposed options/controls** (source: A2 §B, confirmed —
`panel/subscribers.phtml`):

| Control | Where | Real functionality |
|---|---|---|
| Enable subscriber synchronization | Settings tab only | master toggle, `sync_enabled` |
| Contact sync mode (3 lawful-basis radio cards) | wizard step 2 / Settings | consent / legitimate_interest / checkout_optin |
| Extra fields to sync (8 checkboxes) | same | `SyncFields`, empty values omitted |
| Show newsletter checkbox at checkout | wizard/Settings + native config | `Controller/Checkout/Optin.php` |
| Let Smaily send opt-in emails (suppress Magento's own) | wizard/Settings + native config | `Plugin/SuppressNewsletterEmails.php` — suppresses success/unsubscribe mail only, never the double-opt-in confirmation request |
| Import your existing subscribers (backfill) | wizard step 2 / Settings > Subscribers | see 2.7 |

Native-config-only (not on this tab, by design — see §4.i): Include Guest
Order Emails, Automations May Re-Subscribe (Advanced).

**(c) EST+ENG text** (source: our own i18n):

| EN | ET |
|---|---|
| Who should be synced to Smaily? | Keda peaks Smailysse sünkroonima? |
| Enable subscriber synchronization | Luba tellijate sünkroonimine |
| Subscribers only (consent) | Ainult tellijad (nõusolek) |
| All customers (legitimate interest) | Kõik kliendid (õigustatud huvi) |
| Checkout opt-in only | Ainult kassas antud nõusolek |
| Recommended (badge) | Soovitatud |
| Extra fields to sync with each contact | Lisaväljad, mis sünkroonitakse iga kontaktiga |
| Show a newsletter checkbox at checkout | Näita kassas uudiskirja märkeruutu |
| Let Smaily send the opt-in confirmation emails (suppresses Magento's own) | Lase Smailyl saata tellimuse kinnituskirjad (Magento enda kirjad jäetakse ära) |

The three lawful-basis labels are **verbatim-identical across Woo, Shopify
and us already** — strongest positive confirmation in the whole audit, no
harmonization needed.

**(d) REMOVE — design-pack leak:** the "Opt-in mode: Double opt-in
(confirmation email) / Single opt-in" select and its reactive "Confirmation
email (Smaily automation)" picker. Confirmed absent from our code and from
BOTH siblings (A2, design-pack-leaks §1) — Magento's own double-opt-in
setting lives natively under *Stores > Configuration > Customers >
Newsletter*, is untouched by this module, and there is no
"confirmation-email-as-Smaily-automation" concept anywhere in the trigger
catalog. **Do not build. Delete if any trace exists in current templates.**

#### 2.3.C Automations tab

**(a) Layout** (source: A1 §2.4, `Engine Automations.dc.html` — applies to
the engine-automations sub-block; the store-event trigger block above it
follows the same section-header + card-list idiom):

- Section header: title + description on the left, "How triggers work →"
  link on the right (if this link is real/planned — see (d) validation
  note below; not confirmed as an existing feature, do not fabricate the
  target if it doesn't exist).
- **Trigger list as a vertical stack of cards**, not a dense checkbox+
  dropdown table (this is the direct fix for PRO-1357 finding #9). Each
  card: trigger name + a **Pill** (run-mode: active/test/off) + muted
  description, right-aligned control row (Workflow select, disabled when
  Off; per-trigger-appropriate extra fields — see (b)).
- Validation-error state: top error Banner with a count ("N automations
  couldn't be saved..."), field-level danger styling + message on the
  specific offending field, valid rows unaffected.
- Not-connected fallback: single centered empty-state card, "no dead
  controls."
- Catalog-load-failed fallback: warning Banner + saved cards rendered
  dimmed/non-interactive, "existing config is never wiped."
- Footer: one "Save automations" button (whole block, not per-row) +
  InlineStatus.

This card-list-with-pills shape (vs. the cramped checkbox+dropdown list
PRO-1357 flagged) is the layout target; the **field set inside each card
must be our real fields**, not the design pack's illustrative sample set —
see (b).

**(b) Exposed options/controls** (source: A2 §C, confirmed — two
sub-systems on this tab):

*Store-event automations* (welcome / first order / abandoned cart):

| Control | Real functionality |
|---|---|
| Welcome | enable toggle + workflow select |
| First order | enable toggle + workflow select |
| Abandoned cart | enable toggle + workflow select + cutoff minutes (10–1440, default 30) |
| Per-language workflow mapping table (modes A/B only) | language / workflow select / default-fallback radio, per trigger |

Native-config-only, not on this tab (see §4.i): Abandoned Cart Product
Fields (7 checkboxes).

*Engine-run (Campaign Intelligence) automations* — dynamic list from the
engine catalog, each row: Enabled + Test mode toggles, Smaily Workflow
select, Cooldown (days), Daily Cap (blank = no cap), Test Emails
(comma list). Every trigger starts disabled + test mode. Trigger
name/description are locale-aware per trigger (`name_et`/`name_en` from the
engine catalog, PRO-1292 — already shipped).

**Design-pack sample trigger names/defaults (Abandoned cart / Welcome /
Back in stock / Win-back with specific cooldown-hour and daily-cap values)
are illustrative mockup placeholders only — the real trigger catalog is
sourced dynamically from the engine, not a fixed list.** Do not hardcode
the pack's sample set.

**(c) EST+ENG text** (source: our own i18n):

| EN | ET |
|---|---|
| Map store events to Smaily automations | Seo poe sündmused Smaily automaatikatega |
| Refresh workflows | Värskenda töövooge |
| Welcome — fires when someone becomes a subscriber | Tervitus — käivitub, kui keegi saab tellijaks |
| First order — fires on a customer's first purchase | Esimene tellimus — käivitub kliendi esimesel ostul |
| Abandoned cart — fires when a cart is left behind | Hüljatud ostukorv — käivitub, kui ostukorv jäetakse maha |
| Campaign Intelligence Automations | Campaign Intelligence'i automaatikad |
| Connect Smaily to set up automations | Automaatikate seadistamiseks ühenda Smaily |
| Smaily Workflow | Smaily töövoog |
| Cooldown (days) | Puhkeaeg (päevades) |
| Daily Cap | Päevalimiit |
| Test Emails | Test-aadressid |
| Save Intelligence Automations | Salvesta Intelligence'i automaatikad |

**Wording-harmonization candidate (non-blocking):** Shopify's "Test Emails"
equivalent is titled **"Test addresses"** with explicit help text
("Comma-separated, up to 50 addresses.") plus a proactive warning when
enabled + test mode + no addresses are set. The warning is a genuine UX
improvement worth considering (see §5), the rename is cosmetic.

**(d) REMOVE:** none design-pack-invented. The "How triggers work →" link
and per-trigger "cooldown hours"/"daily cap" fields shown in the pack are
**real** (A2 confirms cooldown+cap exist for engine automations), but the
pack's specific hour-based cooldown for store-event triggers vs day-based
for engine automations should not be conflated when implementing — verify
against `ViewModel\Adminhtml\AutomationsForm` field names, not the pack's
prose.

#### 2.3.D Intelligence tab

**(a) Layout:** **the design pack has zero visual detail for this tab** (A1
gap — named only in the tab strip, never mocked up). Use the common
Settings shell (2.3(a)) plus the Backfill card layout (2.7) for the three
historical-import buttons; no other layout guidance exists from the design
pack. Phase B should design this tab's control layout from the common shell
+ existing card/toggle idioms, not invent new visual language.

**(b) Exposed options/controls** (source: A2 §D, confirmed):

| Control | Where | Real functionality |
|---|---|---|
| Setup URL or token | wizard step 4 / Settings / native config | one-time exchange, never stored |
| Connect button | same | AJAX exchange + tenant/version status |
| Storefront browse tracking | wizard step 4 (post-connect) / Settings / native config | off by default, respects cookie restriction mode |
| Sync catalog / Sync customers / Sync orders | **Settings tab only**, not in wizard | per-entity toggle for live sync observers — see §4.iii open decision |
| Historical imports: catalog / customers / orders | **Settings tab only** | `smaily:backfill:start catalog\|customers\|orders`, live progress, Cancel |

**(c) EST+ENG text** (source: our own i18n):

| EN | ET |
|---|---|
| Campaign Intelligence (optional) | Campaign Intelligence (valikuline) |
| Setup URL or token from Smaily | Smailylt saadud seadistus-URL või -võti |
| Connect | Ühenda |
| Enable storefront browse tracking (product views, searches, cart activity) | Luba poe sirvimise jälgimine (tootevaatamised, otsingud, ostukorvitegevus) |
| Sync catalog changes to the engine | Sünkrooni kataloogimuudatused mootorisse |
| Sync customer changes to the engine | Sünkrooni kliendimuudatused mootorisse |
| Sync order changes to the engine | Sünkrooni tellimusemuudatused mootorisse |
| Historical imports to Campaign Intelligence | Ajaloolised impordid Campaign Intelligence'i |
| Import catalog / Import customers / Import orders | Impordi kataloog / Impordi kliendid / Impordi tellimused |

**(d) REMOVE:** none — nothing design-pack-invented here since the pack
never mocked this tab up.

#### 2.3.E RSS tab

**(a) Layout** (source: A1 §2.3.3, `Settings.dc.html:106-107`): 4-field
grid (`1fr 1fr`, max-width 520px) + a read-only "Feed URL" row (monospace
code chip, horizontal-scroll, + Copy button) + an InlineStatus confirming
copy. Footer: Save RSS | InlineStatus.

**(b) Exposed options/controls** (source: A2 §E, confirmed —
`config/rss-builder.phtml`, `Controller/Rss/Feed.php`):

| Control | Real functionality |
|---|---|
| Enable the product RSS feed | per store view |
| Category ID (optional, numeric) | filters feed to one category |
| Number of products (1–250, default 50) | `limit` param |
| Sort by (created_at/updated_at/name/price) | `sort` param |
| Sort order (asc/desc) | `order` param |
| Copy button | clipboard API + `execCommand` fallback |
| "Advanced RSS options in Stores > Configuration" deep link | opens the native RSS config group, auto-expanded (real, shipped, PRO-1281 carry-over) |

The design pack's **4-field grid** ("Store view / Source / Max items /
Sort") maps onto the real fields as: "Max items" → Number of products,
"Sort" → Sort by (+ our separate Sort order field, which the pack doesn't
show), "Source" → Category ID (see follow-up below) — "Store view" has no
real counterpart, see (d).

**(c) EST+ENG text** (source: our own i18n):

| EN | ET |
|---|---|
| Product RSS feed | Toodete RSS-voog |
| Enable the product RSS feed | Luba toodete RSS-voog |
| Category ID (optional) | Kategooria ID (valikuline) |
| Number of products (1-250) | Toodete arv (1-250) |
| Sort by | Sordi |
| Sort order | Sortimise suund |
| Copy | Kopeeri |
| Date created / Date updated / Product name / Price | Loomise kuupäev / Muutmise kuupäev / Toote nimi / Hind |
| Descending / Ascending | Kahanev / Kasvav |

**(d) REMOVE — design-pack leak:** the "Store view" dropdown inside the URL
builder widget. Confirmed pure design-pack invention — no sibling, and no
line of our own code, backs a store/language selector inside the builder;
in all three products the feed is scoped by which page/domain the builder
is rendered on, never an in-widget dropdown. **Do not build. Delete if any
trace exists.**

This is the direct fix for PRO-1357 finding #10's "unstyled" half; the
"punts merchant to the second config surface" half of finding #10 is
addressed by keeping the existing "Advanced RSS options" deep link
(intentional, not a bug — see §4.i) while making sure the RSS tab itself is
properly styled per this section's layout, so the deep link reads as an
intentional "advanced" escape hatch rather than the page failing to render
its own controls.

---

### 2.4 Log

**(a) Target layout** (source: A1 §2.5): native Magento `ui_component` grid
stays untouched. Two additions only:

1. A warning **Banner** above the grid ("N events failed in the last 24
   hours" + reassurance message + "Show failed (24h)" action that applies
   the grid's own native filter preset, status=failed/parked AND
   created≥now-24h — **validate the exact filter combination against real
   code**, A1 flags this as design-implied and A2 confirms only a
   "pre-filtered to `status=failed`" deep link exists today, not
   confirmed to also include parked/24h-window — treat the pack's precise
   filter combination as aspirational until cross-checked against
   `Controller\Adminhtml\Log\*`).
2. A **Details slide-out panel** (right-docked, scrim behind it): header
   (event id + type + status Pill + close), scrollable body (summary
   grid, attempt-history timeline, redacted request payload, redacted
   response), footer (Retry now, Copy payload, InlineStatus).

**(b) Exposed options/controls** (source: A2 §G, confirmed —
`smaily_log_grid.xml`, `log/details.phtml`):

| Element | Real functionality |
|---|---|
| Grid columns | Source, Type, Entity, Status, Attempts, Last Error, Created, Updated, Actions |
| Mass action: Retry | re-queues selected failed rows |
| Failed-24h banner | zero-state hidden; links to grid pre-filtered on status |
| Details slide-out | status pill, attempts (N of MAX), honest retry line (5 states: sent/sending/failed-terminal/scheduled-retry/waiting-for-flush), last error (redacted), payload as-sent/queued (redacted), last response (redacted) |
| PII redaction | `Model\Log\PayloadRedactor` — secrets never shown, emails masked |

The pack's "Copy payload" and "Retry now" footer buttons, and the specific
"Attempt N of 5 · next attempt ~14:26" ETA copy, are **design-implied, not
independently confirmed present** — A2's option table lists a "Retry" mass
action and an "honest retry line" concept but does not separately confirm a
slide-out-footer "Retry now"/"Copy payload" button pair exists today;
Phase B should verify these two buttons against `log/details.phtml` before
treating them as already-shipped vs. a small gap to close.

**(c) EST+ENG text** (source: our own i18n):

| EN | ET |
|---|---|
| Source / Type / Entity / Status / Attempts / Created / Updated / Last Error / Actions | Allikas / Tüüp / Kirje / Olek / Katsed / Loodud / Uuendatud / Viimane viga / Tegevused |
| Retry (mass action) | Proovi uuesti |
| "All %1 automatic attempts are used up — this row will NOT retry on its own. Select it in the log and press Retry to queue it again." | (shipped) |
| "%1 events failed in the last 24 hours" | (shipped) |
| "Sensitive values (passwords, API keys) are never shown here, and email addresses are masked." | (shipped) |

**(d) REMOVE:** none.

---

### 2.5 Backfill (embedded, Subscribers + Intelligence tabs)

**(a) Target layout** (source: A1 §2.6): card shell — header (title +
sub-label + optional status Pill), optional **ProgressBar** block (bar +
"X of Y" count + percentage, shown in **every state except idle**), a
verdict/body line (icon + sentence, color-matched to state), an optional
deep-link to the Log, and a footer (1-2 buttons + optional InlineStatus).

**This is the direct fix for PRO-1357 findings #7 (progress bar stuck on
the import button) and #8 (stuck "Importing… 0/?" with no import
started):** the idle state must render **no Pill and no ProgressBar at
all** — a progress bar (even at 0) must never be visible unless a job is
actually running. If the current implementation shows a progress affordance
before Start is pressed, that is the bug to fix against this target.

**(b) Exposed options/controls, 5 real states** (source: A2 §H, confirmed —
`panels-js.phtml`, `smaily_connect/api/backfillstate`):

Two independent contexts share the same mechanics: (1) Subscribers →
Smaily (Settings > Subscribers / wizard step 2, one job: `contacts`);
(2) Catalog / Customers / Orders → Campaign Intelligence (Settings >
Intelligence tab only, three jobs). Import button, Cancel import button
(shown only while running), progress bar, one of the 5 terminal/live
states below.

**(c) EST+ENG text — the 5 states are the load-bearing copy** (source: our
own i18n):

| EN | ET |
|---|---|
| Importing… %1 / %2 | (shipped) |
| Done, %1 of %2 synced. | (shipped) |
| Done, %1 of %2 synced — %3 failed. | (shipped) |
| Stopped before an error — %1 of %2 synced so far. Press the import button to run it again. | (shipped) |
| Cancelled — %1 of %2 synced. Starting again begins a fresh import. | (shipped) |
| Import your existing subscribers | Impordi oma olemasolevad tellijad |
| Import subscribers to Smaily | Impordi tellijad Smailysse |
| Cancel import | Katkesta import |
| View them in the Log | (shipped — links to Log pre-filtered to failed rows) |

A1's 6-state model (idle/running/done-clean/done-with-failures/stopped/
cancelled) maps 1:1 onto these 5 copy states plus the idle state (which has
its own CTA copy, "Import your existing subscribers", and no progress
affordance per (a) above) — no gap between A1's states and A2's real copy.

**(d) REMOVE:** none. "Resume import" (continuing from the last committed
record) and "Discard & restart"/"Start over" as distinct destructive
actions from the pack are **not separately confirmed in A2's option
table** — our shipped copy's Stopped/Cancelled strings ("Press the import
button to run it again" / "Starting again begins a fresh import") describe
a **restart, not a resume** at the byte level of confirmed wording. Treat
"Resume import" as a design-implied feature not validated by this pass —
do not build a resume-from-last-record UI unless/until confirmed against
the real job model; the current "run again = fresh run" framing is what's
canonical per (c).

---

## 3. Findings #1–#10 → resolution map

| # | PRO-1357 finding | Screen/section | Target-spec item that fixes it |
|---|---|---|---|
| 1 | Stray glyph before Dashboard nav label | Admin menu (not a screen covered by A1/A2 — menu chrome is out of scope for both analyses) | Not covered by this spec — trivial standalone bug fix, listed in §5 Follow-ups. |
| 2 | Two config surfaces, partial/duplicated | Settings (all tabs) vs native `Stores > Configuration` | §4(i) open decision — A2's recommendation (keep both, tighten the "advanced options in Stores > Configuration" pointer on every tab that has a native-only counterpart field) is not self-executing; Erkki decides remove-native vs keep-both vs keep-native-advanced-only. |
| 3 | Opaque "overridden" scope banner | Settings (all tabs) | Already fixed by PRO-1274 (OverrideDetector + "Overridden for X" + Use Default). §4(ii) open decision on the remaining scope-UX policy (legacy website-row cleanup, mode-A special-casing) — A2 recommends keeping the PRO-1274 approach, treating legacy rows as cleanup debt. |
| 4 | Settings page diverges from design layout + wrong wording | Settings (all 5 tabs) | §2.3 (a) layout target + (c) EST+ENG text tables, per tab. |
| 5 | Setup Wizard choice-cards broken | Setup Wizard | §2.2 (a) Radio Choice-Card layout target (anatomy, states, reactive region) + §2.3.A (a)/(b) for the underlying 4-mode data it must render. |
| 6 | Single/double opt-in is a design-pack leak | Settings > Subscribers | §2.3.B (d) REMOVE — confirmed leak, delete rather than build. |
| 7 | Unstyled checkboxes, ugly spacing, progress bar stuck on import button | Settings > Subscribers (checkboxes) + Backfill (progress bar) | §2.3.B (a) layout (extra-fields checkbox set, Radio Choice-Card) + §2.5 (a)/(b) — idle state must show no progress affordance. |
| 8 | Stuck "Importing… 0/?" with no import started | Backfill (embedded, Subscribers + Intelligence) | §2.5 (a) — explicit rule: idle = no Pill, no ProgressBar at all. |
| 9 | Automations: cramped checkbox+dropdown vs design's per-automation controls + status pills | Settings > Automations | §2.3.C (a) card-list-with-Pill layout target + (b) real field set (do not adopt the pack's illustrative trigger names/defaults). |
| 10 | RSS view unstyled + punts to second config surface | Settings > RSS | §2.3.E (a) layout target + (d) REMOVE (Store view dropdown) + note that the "Advanced RSS options" deep link is intentional, not the bug — the bug is the tab's own styling. |

---

## 4. OPEN DECISIONS FOR ERKKI

These are genuine product-direction calls the two analyses surfaced. **Not
decided here** — Phase B implements against the current shipped behaviour
for each until Erkki rules.

### (i) Two config surfaces (finding #2)

**Tension:** Erkki's instinct was to REMOVE the native Magento config
surface (`Stores > Configuration > Smaily`) — one surface, like both
siblings. A2's analysis recommends **keeping both**, because Magento's
native scoped-config tree (per-website / per-store-view overrides,
"Use Default") is real, useful functionality neither sibling's platform has
an equivalent of, and several fields (guest-order-email inclusion,
force-resubscribe, abandoned-cart product-field selection, log verbosity)
currently exist **only** on the native page.

**Options laid out by A2:**

- **Remove native entirely** — matches sibling architecture (Woo actively
  deleted its second surface; Shopify never had one). Consequence: loses
  real per-website/per-store-view scope override capability that Magento
  admins/agencies already expect under `Stores > Configuration`, and the
  handful of native-only fields would need a new home on the module's own
  pages (net new UI work, not zero-cost).
- **Keep both, tighten division of labour (A2's recommendation)** — module
  pages stay the primary/recommended surface (day-to-day fields, default
  scope, live JS affordances); native config becomes the explicit
  advanced/scope-override surface (rare fields + per-website/per-store-view
  overrides). Requires closing a documentation/UX gap: every module page
  with a native-config-only counterpart field should carry an "…advanced
  options in Stores > Configuration" pointer — Automations and Log tabs
  currently don't have one (RSS already does, PRO-1281 carry-over).
- **Keep native as advanced-only, formalize it** — same as above but more
  explicit: native config page's field set is trimmed/reordered to visibly
  read as "advanced," with a banner pointing back to the module's own pages
  for the common path.

**Consequences to weigh:** removing native = simpler mental model, matches
sibling precedent, but loses Magento-native scope-override capability and
requires rehoming ~5 fields. Keeping both = richer capability for
multi-site installs, but is the literal "two config surfaces, partial/
duplicated" thing PRO-1357 flagged as confusing — mitigated, not eliminated,
by better cross-pointers.

### (ii) Scope-override UX (finding #3)

**Tension:** how should our own pages treat Magento's config-scope
mechanics? A2 recommends **keeping the PRO-1274 approach as-is**: the
module's own pages always save at default scope; `OverrideDetector` +
"Overridden for X" + Use Default already solves *visibility* of existing
overrides. Two refinements A2 flags as **candidate follow-up tickets, not
decided here**:

1. Legacy per-website rows seeded by the 2.8.x→v3 migration are **cleanup
   debt, not configuration** — a one-click "clean up carried-over
   per-website settings from your old install" action (distinct from the
   existing manual "Use default") would let merchants who never intended a
   website override get rid of it without having to understand what one is.
2. Multilingual mode-A's own store-view credential rows are **app-managed,
   not merchant-managed** — the current generic "Overridden for X" banner
   technically applies to them too, which is pedagogically noisy for a
   value the app itself put there on purpose; special-casing them out of
   the generic banner is a candidate refinement.

Erkki's call: accept A2's "keep current approach + these as follow-up
tickets" framing, or want something different done now.

### (iii) Intelligence sync toggles

**Tension:** keep our per-entity **Catalog / Customers / Orders** sync
toggles (Settings > Intelligence) vs. both siblings' simplification —
**Woo explicitly removed** per-domain sync toggles in v3.9 ("connecting the
rec-engine syncs all domains unconditionally... only the browse-tracking
preference is persisted"); **Shopify never had them** ("all three sync
automatically," no toggle). Both siblings independently converged on
"connect = sync everything, no per-entity opt-out." Our module still
exposes three separate real, wired toggles.

**Consequences to weigh:** keeping toggles = more granular control (a
merchant who wants catalog-only sync can have it) but is a product-shape
divergence from both sibling platforms and is more UI surface to maintain
and explain. Removing toggles = simpler mental model, matches sibling
precedent, but removes a real capability merchants may already be using
(no data on whether anyone relies on partial sync).

---

## 5. Follow-ups / minor bugs (list, don't fix)

- **`__('Done')` wizard step-5 label — i18n gap.** `wizard/index.phtml:32`
  has no entry in either `i18n/en_US.csv` or `i18n/et_EE.csv`; falls back to
  English "Done" under et_EE. Trivial one-line fix (A2 §I).
- **RSS named-category/collection picker** — both siblings (Woo: "Product
  category" live dropdown; Shopify: "Collection" live dropdown) have this;
  our builder only takes a raw numeric Category ID. Real feature gap, not a
  leak — candidate for a follow-up ticket, live AJAX category-tree dropdown
  replacing the numeric-ID input (A2, design-pack-leaks §2).
- **Shopify-style "nothing will send" safety warning** on engine-automation
  Test Emails/addresses when enabled + test mode + no addresses are set —
  UX nicety, not a functionality gap (A2 §C).
- **Wizard "What's active" live summary** (Shopify's richer final-step
  recap: connection status, sync/backfill counts, per-automation mapped-
  workflow counts) vs. our current static links list on the Done step — UX
  idea, not required (A2 §I).
- **Stray glyph before the Dashboard nav label** (PRO-1357 finding #1) —
  standalone menu-label bug, not covered by either analysis; fix directly
  against the admin menu XML/text, no design-spec dependency.
- **Log slide-out "Retry now"/"Copy payload" buttons and the exact
  failed-24h filter combination** (status=failed/parked AND created≥
  now-24h) — flagged in §2.4(b) as design-implied but not independently
  confirmed present in current code; verify against `Controller\Adminhtml\
  Log\*` before assuming they're already shipped.
- **Backfill "Resume import" (resume-from-last-record)** — not confirmed by
  A2's option table; our shipped copy currently frames Stopped/Cancelled
  recovery as "run again = fresh start," not a resume. If a genuine resume
  capability is wanted, that's new engineering, not a copy fix (§2.5(d)).
- **Wording-harmonization candidates** (all non-blocking, sibling wins per
  binding rule, future copy pass — full list assembled from A2, repeated
  here for one place to track): multilingual mode A/B/C labels (§2.3.A),
  "Automations May Re-Subscribe (Advanced)" → "Force opt-in on automation
  triggers" (A2 design-pack-leaks §1), Dashboard verdict failure/all-clear
  wording (§2.1), "Test Emails" → "Test addresses" (§2.3.C).
- **Design-pack visual inconsistencies — resolve when CSS is finalized, not
  now** (A1 §3, purely cosmetic seams in the pack itself, not functional
  claims): (a) Metric Tile value font-size specified as `--fs-28` on the
  tokens/Components pages but rendered as a literal `30px` in the
  standalone `Tile.dc.html`; (b) the `off` Pill variant's neutral color
  triple (`#6b6b6b`/`#efefef`/`#cfcfcf`) doesn't exactly match the token
  sheet's `--s-neutral`/`--s-neutral-soft`/`--s-neutral-border`
  (`#5a5a5a`/`#eeeeee`/`#cccccc`); (c) ProgressBar's 4-state enum
  (`running/done/failed/stopped`) is overloaded by the Backfill screen to
  represent 6 conceptual states (done-with-failures and stopped-mid-run
  both render via the `failed` visual). Pick one canonical value for each
  when the token/component CSS is next touched.
