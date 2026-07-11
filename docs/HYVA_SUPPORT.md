# Hyvä Theme Support

Status: **compat module skeleton in place (`compat/hyva/`, module
`Hyva_SmailyConnect`), not yet verified on a real Hyvä store.**

[Hyvä](https://www.hyva.io/) replaces Magento's Luma frontend stack
(RequireJS, jQuery, Knockout, `x-magento-init`) with Alpine.js 3 + Tailwind
CSS and is free/open-source since November 2025 (OSL-3.0; free license keys
via the Hyvä portal, source at
[github.com/hyva-themes](https://github.com/hyva-themes)). The admin is
untouched by themes, so only the storefront surfaces matter. Target: Hyvä
1.4+ (Tailwind v4).

This document is the audit of every Smaily Connect storefront surface, what
the compatibility module already covers, and exactly what remains before
Hyvä support can be called done.

## Storefront surface audit

Verdicts: **WORKS-AS-IS** (no change needed), **NEEDS-COMPAT-TEMPLATE**
(delivery/markup swap, logic unchanged), **NEEDS-JS-PORT** (real code
changes), **OUT-OF-SCOPE**.

| Surface | Evidence | Verdict |
|---|---|---|
| Browse tracker | `view/frontend/web/js/tracker.js:16` — AMD `define(['jquery', …])`; `:91` — `$.ajax` beacon fallback; `:148` — listens to Luma-only jQuery event `ajax:addToCart` (Hyvä documents no add-to-cart JS event); `view/frontend/templates/engine/tracker.phtml:16` — `x-magento-init` bootstrap (needs `Magento_Ui/js/core/app` runtime Hyvä doesn't load). Core batching/consent logic is framework-free. | **NEEDS-JS-PORT** |
| Attribution script | `view/frontend/web/js/attribution.js:11` — `define([], …)`: zero dependencies, pure vanilla inside, but AMD-wrapped; `view/frontend/templates/engine/attribution.phtml:16` — `x-magento-init` bootstrap. Logic ports 1:1. | **NEEDS-COMPAT-TEMPLATE** |
| Product/static page-context blocks | `view/frontend/templates/engine/context/product.phtml:21` and `context/static.phtml:23` — one-line inline `window.smailyPageContext` scripts rendered via `SecureHtmlRenderer::renderTag()`. No JS framework involved; CSP whitelisting happens in `Magento_Csp` (DI preference on the renderer), independent of the active theme, so it also holds under Hyvä stores running strict CSP. Blocks attach to `before.body.end`, which Hyvä's `default.xml` keeps. | **WORKS-AS-IS** (verify) |
| Checkout newsletter opt-in | `view/frontend/web/js/view/checkout/newsletter-optin.js:6-10` — `uiComponent`/`ko` component injected via a `LayoutProcessor` plugin (`Plugin/Checkout/AddNewsletterOptinToLayout.php`). Free Hyvä uses the **Luma-fallback checkout**: the checkout route switches to a Luma-based theme where RequireJS/Knockout load normally, so the component runs unchanged. | **WORKS-AS-IS** on Luma-fallback checkout (verify) |
| Checkout opt-in on Hyvä Checkout | Hyvä Checkout is a separate commercial product with its own component system (Magewire), a different integration surface entirely. | **OUT-OF-SCOPE** (see boundary below) |
| My Account personalization page | `view/frontend/templates/privacy/form.phtml` — plain HTML POST form, zero JS; `view/frontend/layout/smaily_privacy_index.xml:13` — standard `content` container; `customer_account.xml:12` — nav entry via core `SortLink`, which Hyvä's account navigation renders. Functional as-is; only the Luma CSS classes (`fieldset`/`legend`/`actions-toolbar`) render unstyled. | **WORKS-AS-IS** functionally; **NEEDS-COMPAT-TEMPLATE** for styling |
| Native newsletter block | No template override in this module; subscription is consumed server-side (`Observer/SubscriberSaveAfter.php` on `newsletter_subscriber_save_after`). Hyvä ships its own newsletter form template posting to the same core controller. | **WORKS-AS-IS** (verify) |
| RSS product feed | `Controller/Rss/Feed.php` — server-rendered XML, no frontend assets. | **WORKS-AS-IS** |
| Abandoned-cart restore link | `Controller/Cart/Restore.php` — server-side redirect, no frontend assets. | **WORKS-AS-IS** |

## What the compat module provides (`compat/hyva/`)

Module `Hyva_SmailyConnect`, composer `hyva-themes/magento2-smaily-connect`
(the standard Hyvä compat-module convention; final vendor namespace is an
open release decision). It lives in this repository and will eventually be
published as its own package. It is excluded from the release ZIP artifact
but kept in the composer package on purpose: it is inert there — nothing
loads `compat/hyva/registration.php` — while enabling installation via a
path repository until the separate package exists (see
`compat/hyva/README.md`).

- `view/frontend/layout/hyva_default.xml` — `hyva_`-prefixed handle (loads
  only when a Hyvä theme is active) swaps the attribution and tracker
  templates on the blocks defined by the base module.
- `view/frontend/templates/engine/{attribution,tracker}.phtml` — bootstrap
  without `x-magento-init`: config in an inert
  `<script type="application/json">` block + a static same-origin JS file
  loaded with `defer`. No inline executable script at all, so nothing needs
  CSP whitelisting even under strict CSP.
- `view/frontend/web/js/smaily-attribution.js` — 1:1 vanilla port of the
  attribution module (AMD wrapper removed; exposes
  `window.smailyAttribution` for the tracker, mirroring the old AMD
  dependency).
- `view/frontend/web/js/smaily-tracker.js` — jQuery-free tracker port:
  `fetch(keepalive)` replaces the `$.ajax` fallback; `cart_add` is captured
  from the `checkout/cart/add` form submit (capture phase + immediate
  beacon flush) because Hyvä's default add-to-cart is a regular form POST
  and no add-to-cart JS event is documented. Semantic difference vs Luma:
  the event fires on the attempt, not on confirmed success — acceptable
  for a loss-tolerant popularity signal. Marked `TODO(hyva-store)`:
  AJAX-add-to-cart compat modules bypass the submit event; fall back to a
  `private-content-loaded` cart diff if real-store testing shows gaps.
- `view/frontend/layout/hyva_smaily_privacy_index.xml` +
  `templates/privacy/form.phtml` — Tailwind-styled personalization form
  (same behaviour and translated phrases; classes only).
- `etc/frontend/events.xml` + `Observer/RegisterModuleForHyvaConfig.php` —
  registers the module for `bin/magento hyva:config:generate`
  (`app/etc/hyva-themes.json`) so the theme's Tailwind content scan picks
  up these templates.

No new user-facing phrases were introduced: the compat templates reuse the
exact strings already present in `i18n/en_US.csv` / `i18n/et_EE.csv`, which
apply globally at runtime.

## Remaining work — needs a Hyvä dev store

Everything above compiles and passes the repo gates, but **none of it has
executed against a Hyvä theme**. Setting up the verification environment:

1. Get Hyvä 1.4+: free license key from the Hyvä portal (adds their private
   Packagist repo), or from source via
   [github.com/hyva-themes](https://github.com/hyva-themes).
2. `composer require hyva-themes/magento2-default-theme`, assign the theme
   to the store view.
3. Install `Hyva_SmailyConnect` (see `compat/hyva/README.md`), run
   `bin/magento hyva:config:generate`, build the theme CSS (Node 20+,
   Tailwind v4 on Hyvä 1.4).
4. Optionally enable `Magento_Csp` enforce mode storefront-wide to cover
   the strict-CSP column.

### Verification matrix

Each cell needs a pass; Luma columns re-run to prove the compat module
changes nothing when Hyvä is absent.

| Surface | Luma (regression) | Hyvä | Hyvä + strict CSP |
|---|---|---|---|
| Attribution: campaign-click landing sets cookies (FPC page) | done (sandbox) | todo | todo |
| Tracker: product_view / category search / checkout_start / checkout_complete reach the relay | done (sandbox) | todo | todo |
| Tracker: cart_add (PDP form submit; with and without AJAX-cart modules) | done (sandbox, `ajax:addToCart`) | todo | todo |
| Tracker: consent (cookie restriction on/off, identity hint dropped) | done (sandbox) | todo | todo |
| Page-context blocks render (SecureHtmlRenderer whitelisting) | done (sandbox) | todo | todo |
| Checkout opt-in checkbox (Luma-fallback checkout) | done (sandbox) | todo | todo |
| Personalization page: nav link, form render/submit, Tailwind styling | done (sandbox) | todo | todo |
| Newsletter form subscribe fires our observers | done (sandbox) | todo | n/a (server-side) |
| RSS feed | done (sandbox) | n/a (no theme surface) | n/a |
| Tailwind build includes compat templates after `hyva:config:generate` | n/a | todo | n/a |

Also to verify on the dev store: `setup:upgrade` + `setup:di:compile` with
`Hyva_SmailyConnect` enabled, and that disabling the module cleanly returns
the Luma templates.

## Hyvä Checkout boundary

**Decision needed before release:** the free Hyvä theme falls back to the
Luma checkout, where our Knockout opt-in component works unchanged — that
is the supported configuration. **Hyvä Checkout** (the separate commercial
product, Magewire-based) does not load Knockout components; supporting it
means writing a Magewire checkout component and testing against a paid
license. Recommendation: declare Hyvä Checkout out of scope for the first
Hyvä-support release, document the boundary ("the opt-in checkbox requires
the default checkout"), and revisit on demand. The server-side opt-in
endpoint (`smaily/checkout/optin`) and order-placement consumers are
checkout-implementation-agnostic, so a future Hyvä Checkout component only
needs the frontend half.

## Open release decisions

1. Publish vendor/name: `hyva-themes/magento2-smaily-connect` (requires
   adoption into the Hyvä compat-module tracker) vs `smaily/…`. The module
   code is name-agnostic apart from composer.json.
2. Hyvä Checkout: confirm the out-of-scope boundary above.
3. Whether the compat module stays in this repo (current setup: developed
   here, excluded from the main package artifacts) or moves to its own
   repository when published.
