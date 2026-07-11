# Smaily Connect for Magento 2

[![CI](https://github.com/erkkimarkus/magento-connect/actions/workflows/ci.yaml/badge.svg?branch=v3)](https://github.com/erkkimarkus/magento-connect/actions/workflows/ci.yaml)

[Smaily](https://smaily.com) email marketing, automations and Campaign
Intelligence for Magento 2, Adobe Commerce and Mage-OS. Feature-aligned
with the Smaily Connect plugins for WooCommerce and Shopify.

## Features

- **Subscriber synchronization** — near-real-time, two-way: new subscribers
  flow to Smaily instantly; unsubscribes in Smaily mirror back to Magento.
- **Contact sync modes** — lawful-basis presets: subscribers only (consent,
  default), all customers (legitimate interest), or checkout opt-in only.
- **Marketing automations** — welcome, first order and abandoned cart
  events trigger your Smaily workflows, with per-language routing for
  multilingual stores.
- **Abandoned cart** — configurable cutoff, rich product payloads and a
  secure recovery link that restores the exact cart.
- **Checkout opt-in** — a newsletter checkbox in the checkout payment step
  (guests and customers, double opt-in respected).
- **Product RSS feed** — for the Smaily template editor, with category,
  limit and sort parameters and storefront-accurate pricing.
- **Campaign Intelligence** *(optional)* — catalog, customer, order and
  browse data power personalized recommendations, attribution and
  engine-run automations (replenishment, win-back, …).
- **Operational visibility** — durable delivery queues with automatic
  retries, admin event logs with one-click retry, health notices, and
  chunked historical imports that never block live traffic.
- **Privacy-first** — encrypted credentials, GDPR export/erase tooling and
  a shopper personalization opt-out page.
- **Translated** — ships with English and Estonian (`et_EE`) translation
  packs for the admin and the storefront.

## Requirements

- Magento Open Source / Adobe Commerce **2.4.4+** or Mage-OS
- PHP **8.1 – 8.4**
- A working Magento cron (ideally every minute)

## Installation

```bash
composer require smaily/smailyformagento
bin/magento module:enable Smaily_Connect
bin/magento setup:upgrade
```

Manual install: extract the release ZIP to `app/code/Smaily/Connect` and
run the same commands.

**Upgrading from 2.8.x?** It's seamless — settings migrate automatically.
See [docs/UPGRADING.md](docs/UPGRADING.md).

## Documentation

| | |
|---|---|
| [User Guide](docs/USER_GUIDE.md) | Setup, every setting explained, CLI reference, FAQ |
| [Upgrading](docs/UPGRADING.md) | Migrating from Smaily for Magento 2.8.x |
| [Architecture](docs/ARCHITECTURE.md) | How the module works inside (for developers) |
| [Testing](TESTING.md) | Test suites, sandbox, upgrade verification |
| [Contributing](CONTRIBUTING.md) | Development environment and quality gates |
| [Backlog](BACKLOG.md) | Known deferred work |

## Quick start

1. **Connect:** Stores > Configuration > Smaily > Smaily Connect — enter
   your Smaily API subdomain, username and password.
2. **Choose your audience:** pick a contact sync mode under Subscriber
   Synchronization (the default syncs opted-in subscribers only).
3. **Map automations:** select Smaily workflows for welcome, first order
   and abandoned cart.
4. **Import history:** Marketing > Smaily Connect > Historical Import.

The **Getting Started** page (Marketing > Smaily Connect) walks through
these steps with live status.

## Development

```bash
composer install          # Magento packages via the Mage-OS mirror
vendor/bin/phpunit --testsuite unit
vendor/bin/phpcs
vendor/bin/phpstan analyse
vendor/bin/phpunit -c phpunit.integration.xml.dist  # needs MySQL, see TESTING.md

docker compose up -d      # Magento 2.4.8 sandbox on http://localhost:8080
```

## License

GPL-3.0 — see [LICENSE.txt](LICENSE.txt).

Legacy note: the 2.8.x extension (`Smaily_SmailyForMagento`) lives on the
[`master`](https://github.com/sendsmaily/smaily-magento-extension/tree/master)
branch.
