# Testing

## Unit tests and static analysis

```bash
composer install            # Magento packages resolve via mirror.mage-os.org
vendor/bin/phpunit --testsuite unit
vendor/bin/phpcs            # Magento2 standard; errors fail, warnings don't
vendor/bin/phpstan analyse  # level 6 with the bitexpert/phpstan-magento extension
```

CI (GitHub Actions) runs the unit suite on PHP 8.1 and 8.3 plus the static
analysis job on every push and pull request.

## Integration tests (real MySQL)

The integration suite exercises the module against a real MySQL database:
queue persistence and claim/backoff/parking semantics (`smaily_event_queue`,
`smaily_ingest_queue`), the 2.8.x → v3 settings migration (real
`core_config_data` rows, password re-encryption, automation mapping seeding),
the legacy schema cleanup patch (real `quote` column drops with mailed-state
carry-over) and the queue cron jobs with the HTTP transports stubbed.

It needs a MySQL 8.x it can own a database on — any throwaway instance works:

```bash
docker run --rm -d --name smaily-it-mysql \
  -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=smaily_connect_it \
  -p 3316:3306 mysql:8.4

SMAILY_IT_DB_PORT=3316 vendor/bin/phpunit -c phpunit.integration.xml.dist
```

Connection parameters come from environment variables (defaults in
parentheses): `SMAILY_IT_DB_HOST` (127.0.0.1), `SMAILY_IT_DB_PORT` (3306),
`SMAILY_IT_DB_USER` (root), `SMAILY_IT_DB_PASSWORD` (root),
`SMAILY_IT_DB_NAME` (smaily_connect_it). The named database is dropped
table-by-table and recreated on every run — never point it at data you care
about. CI runs the suite in a dedicated job with a MySQL 8.4 service.

**Scope note:** the suite boots a standalone `Magento\Framework` object graph
(real DB adapter, model/resource/collection layer, encryptor, config writer)
rather than Magento's full integration TestFramework, which requires a
complete application install plus a search engine and is disproportionately
heavy for a single module's CI. Module tables are installed by translating
`etc/db_schema.xml` directly into DDL; the declarative-schema pipeline itself
(plus DI compilation) is still verified by `setup:upgrade` /
`setup:di:compile` in the sandbox below.

## Sandbox (manual / end-to-end)

A Docker Magento 2.4.8-p4 with sample data, the module mounted at
`app/code/Smaily/Connect`:

```bash
docker compose up -d
# Storefront http://localhost:8080/  Admin http://localhost:8080/admin (admin / smailydev1)

docker exec magento2 bash -c 'cd /var/www/html && bin/magento setup:upgrade && bin/magento setup:di:compile'
docker exec magento2 bash -c 'cd /var/www/html && bin/magento cron:run --group smaily_connect'
```

Manual smoke checklist:

1. Admin > Marketing > Smaily Connect > Dashboard renders the health verdict,
   connection strip, counters and recent activity (fresh installs redirect to
   the Setup Wizard instead until it is completed).
2. Settings: each tab saves via AJAX ("Saved." feedback) and the values land
   in `core_config_data`; invalid Smaily credentials must not block saving.
3. Subscribe on the storefront newsletter form -> a `contact.sync` row appears
   in the Log (source "Smaily") and is delivered on the next cron run.
4. Place an order with the checkout newsletter checkbox ticked -> the email
   becomes a Magento subscriber and syncs to Smaily.
5. Abandon a cart (add items as a logged-in customer, wait past the cutoff,
   run the cron) -> `automation.trigger` event fires once, never twice.
6. `curl http://localhost:8080/smaily/rss/feed?limit=5` returns valid RSS with
   `smly:price` fields.
7. Campaign Intelligence: paste a setup token, run backfills from the
   Settings > Intelligence tab, watch the Log (source "Campaign
   Intelligence") drain.

## Upgrade migration test (2.8.x -> v3)

The migration is covered automatically by the integration suite
(`Test/Integration/Migration/`), which asserts the same outcomes as this
procedure against a real database. The sandbox walkthrough below remains the
full end-to-end check through Magento's real `setup:upgrade` patch pipeline.

Scripted legacy-state simulation inside the sandbox (what the data patch must
handle). Seed the legacy state, then upgrade:

```bash
docker exec magento2_db mysql -uroot -proot magento2 -e "
INSERT INTO core_config_data (scope, scope_id, path, value) VALUES
('default',0,'smaily/general/subdomain','https://demo.sendsmaily.net'),
('default',0,'smaily/general/password','plain-secret'),
('default',0,'smaily/subscribe/workflowId','55'),
('default',0,'smaily/abandoned/autoresponderId','77'),
('default',0,'smaily/abandoned/syncTime','2:hour'),
('default',0,'smaily/abandoned/productfields','name,qty,price');
ALTER TABLE quote ADD COLUMN reminder_date TIMESTAMP NULL, ADD COLUMN is_sent SMALLINT NULL;
CREATE TABLE IF NOT EXISTS smaily_customer_sync (id INT PRIMARY KEY);
DELETE FROM patch_list WHERE patch_name LIKE '%Smaily%';
DELETE FROM core_config_data WHERE path LIKE 'smaily_connect/%';"

docker exec magento2 bash -c 'cd /var/www/html && bin/magento setup:upgrade'
```

Assert afterwards:

- `smaily_connect/*` rows exist for every legacy scope; the password value is
  encrypted (starts with a key-version prefix like `0:3:`), the subdomain is
  normalized, the abandoned cutoff is in minutes, `qty` became `quantity`.
- `smaily_automation_mapping` contains fallback rows for the configured
  welcome/abandoned workflow IDs.
- `quote.reminder_date` / `quote.is_sent` and `smaily_customer_sync` are gone.
- The orphaned `crontab/default/jobs/smaily_subscriber_sync/...` row is gone.
