# Smaily Connect for Magento 2

Smaily email marketing, automations and Campaign Intelligence extension for Magento 2 / Adobe Commerce / Mage-OS.

> **Note:** the `v3` branch is a ground-up rewrite of the extension (module `Smaily_Connect`), targeting feature parity with the Smaily Connect plugins for WooCommerce and Shopify. The legacy 2.8.x module (`Smaily_SmailyForMagento`) lives on the `master` branch. Upgrading from 2.8.x is seamless: the composer package name is unchanged and settings are migrated automatically during `setup:upgrade`.

## Features

- **Newsletter subscriber synchronization** — real-time and scheduled sync of Magento newsletter subscribers to Smaily, with two-way consent reconciliation (unsubscribes in Smaily propagate back to Magento).
- **Contact sync modes** — lawful-basis presets: consent (default), legitimate interest, checkout opt-in only.
- **Marketing automations** — trigger Smaily automation workflows for welcome, first order and abandoned cart events, with per-language workflow routing.
- **Abandoned cart** — quote-based abandoned cart detection with configurable cutoff and rich product payloads (up to 10 products).
- **Product RSS feed** — RSS 2.0 feed with Smaily price/discount extensions for the Smaily template editor, filterable by category, with an admin URL builder.
- **Multilingual** — store view based language routing; single account, per-language workflows, or per-language Smaily accounts.
- **Campaign Intelligence** — optional integration with the Smaily recommendation engine: catalog/customer/order/browse ingest, recommendation attribution, engine-run automations, GDPR tooling and shopper profiling opt-out.

## Requirements

- Magento Open Source / Adobe Commerce 2.4.4+ or Mage-OS
- PHP 8.1 – 8.4

## Installation

### Composer (recommended)

```bash
composer require smaily/smailyformagento
bin/magento module:enable Smaily_Connect
bin/magento setup:upgrade
```

### Manual

Download the release ZIP and extract it to `app/code/Smaily/Connect`, then run `bin/magento module:enable Smaily_Connect && bin/magento setup:upgrade`.

## Development

```bash
# Dependencies (Magento packages resolve via the Mage-OS mirror configured in composer.json)
composer install

# Unit tests
vendor/bin/phpunit --testsuite unit

# Static analysis
vendor/bin/phpcs
vendor/bin/phpstan analyse
```

A Docker-based Magento 2.4.8 sandbox is available for manual testing:

```bash
docker compose up -d
# Storefront: http://localhost:8080/  Admin: http://localhost:8080/admin (admin / smailydev1)
```

The module cron jobs run in their own `smaily_connect` group. On production installs, ensure `bin/magento cron:run` is executed every minute for near-real-time queue flushing.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Bug reports and pull requests are welcome on [GitHub](https://github.com/sendsmaily/smaily-magento-extension).

## License

GPL-3.0 — see [LICENSE.txt](LICENSE.txt).
