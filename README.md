# c975L ConfigBundle

A Symfony bundle that stores application configuration as key-value pairs in the database, with an EasyAdmin management interface, Twig/PHP accessors, and production deployment tooling.

[![GitHub](https://img.shields.io/github/license/975L/ConfigBundle)](https://github.com/975L/ConfigBundle/blob/master/LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/c975l/config-bundle)](https://packagist.org/packages/c975l/config-bundle)
[![PHP Version](https://img.shields.io/packagist/php-v/c975l/config-bundle)](https://packagist.org/packages/c975l/config-bundle)

## Features

- Key-value config entries stored in the database (`site_config` table)
- EasyAdmin CRUD interface to manage values
- Export button (SQL/CSV/JSON) for production deployment, reusable from any bundle's CRUD controller
- Twig and PHP service to read values anywhere
- 1-hour cache with automatic invalidation on change
- "What's new" dashboard section aggregating release notes declared by every c975L bundle

## Installation

```bash
composer require c975l/config-bundle
```

Run the database migration to create the `site_config` table:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

## Defining config entries for your bundle

Create a `config/configs.json` file in your bundle. Each entry will be inserted into the database on first load (duplicates are skipped):

```json
[
    {
        "label": "Site Name",
        "slug": "site-name",
        "sensitive": false,
        "value": null,
        "kind": "text",
        "group": "general",
        "description": "Name of the website"
    },
    {
        "label": "Maintenance Mode",
        "slug": "site-maintenance",
        "sensitive": true,
        "value": "false",
        "kind": "bool",
        "group": "system",
        "description": "Set to true to enable maintenance mode"
    },
    {
        "label": "Stripe Secret Key",
        "slug": "stripe-secret-key",
        "sensitive": true,
        "value": null,
        "kind": "text",
        "group": "payment",
        "description": "Stripe secret key (sk_live_...)"
    }
]
```

Supported `kind` values: `text`, `int`, `bool`, `date`, `json`.
For `json`, `value` is the raw JSON-encoded string (e.g. `"[\"ROLE_ADMIN\",\"ROLE_EDITOR\"]"`); `ConfigService::get()` returns it already decoded into a PHP array (`[]` if empty/invalid).
Set `sensitive: true` for any entry that holds secrets (API keys, passwords, etc.).

`group` is optional and clusters entries in the EasyAdmin list (filter + default sort). It must be one of the fixed values in `Config::GROUPS`, each backed by a `label.group_*` translation key:

| Value | Meaning |
| --- | --- |
| `system` | Access control, maintenance mode |
| `general` | Site identity (name, logo, favicon, URL...) |
| `legal` | Terms of use, cookies, legal notice, DPO |
| `credits` | Hosted-by / made-by links and logos |
| `analytics` | Matomo and other tracking |
| `backup` | Database backup settings |
| `email` | Sender/recipient addresses |
| `form` | Contact form behavior (anti-spam delay, GDPR consent) |
| `security` | ReCaptcha and similar anti-abuse keys |
| `shop` | Currency, shipping, shop identity |
| `payment` | Payment provider keys (Stripe...) |

This list is closed on purpose so filtering stays useful; if none fits, leave `group` unset rather than inventing a new value (adding one requires extending `Config::GROUPS` and the matching translations in ConfigBundle itself).

`severity` is optional and flags an entry that needs an admin's attention as long as its `value` is empty — it never affects front-end rendering, `ConfigService::get()` still returns `null`/empty as before. It must be one of `Config::SEVERITIES`: `danger`, `warning`, `info`. Any entry with a severity and no value is listed on the `/management` dashboard as a colored alert with a direct link to fill it in; once a value is set, the alert disappears on its own (no flag to unset).

## Loading config entries into the database

Auto-discovers every `vendor/c975l/*/config/configs.json` file and loads them in one shot:

```bash
php bin/console c975l:config:load-all
```

New entries (new `slug`) are inserted with their `value` from the JSON. For entries that already exist, only the metadata fixed by the bundle author — `label`, `kind`, `group`, `description` — is re-synced from the JSON on every run; `value` and `sensitive` carry production state and are never touched, so editing a `configs.json` file (e.g. moving a config to a new group, fixing a typo in a label) and re-running `load-all` is enough to propagate the change, without risking an admin-set value.

## Encrypting sensitive values

Sensitive config values can be encrypted at rest (AES-256-CBC) using a `C975L_VAULT_KEY` defined in `.env.local`. Generate a key:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Add it to `.env.local`:

```dotenv
C975L_VAULT_KEY=<generated_key>
```

Then run the following command to encrypt any sensitive value still stored in plain text — it is idempotent and safe to run multiple times, skipping empty or already-encrypted values:

```bash
php bin/console c975l:config:encrypt-sensitive
```

## EasyAdmin interface

The bundle registers a management dashboard at `/management`. Navigate to **Config** to view entries and edit their `value` — `label`, `slug`, `kind`, `group`, `severity`, and `description` are fixed by the bundle's `configs.json` and shown read-only; there is no manual creation or deletion, entries only come from `configs.json`.

Any entry with a `severity` and an empty `value` shows up as a colored alert (danger/warning/info) right on the `/management` home page, each linking directly to its edit form.

### JS assets loaded on the dashboard

The `/management` dashboard loads a dedicated AssetMapper entry, `@c975l/ui-bundle/admin.js` (not your site's main `app` entry), so that satellite bundles needing Stimulus controllers in the back-office (e.g. `c975l/ui-bundle`'s block editor) don't drag your site's front-end stylesheet into EasyAdmin. See the [UiBundle README](https://github.com/975L/UiBundle#installation) for how to define this entry.

### Deploying to production — Export

On the config list page, click the **Export** dropdown and pick **SQL**, **CSV**, or **JSON**. The browser downloads a `site_config_YYYYMMDD_HHMMSS.{sql,csv,json}` file — nothing is written to disk or version control.

Import the SQL export on your production server:

```bash
mysql -u user -p dbname < site_config_20260626_120000.sql
```

**Behavior per entry type (SQL export only):**

| `is_sensitive` | SQL statement | Effect on production |
| --- | --- | --- |
| `false` | `INSERT … ON DUPLICATE KEY UPDATE` | Creates or updates label, value, kind, group, description, severity |
| `true` | `INSERT IGNORE INTO` | Creates if missing; **preserves existing production value** |
This means non-sensitive values (labels, descriptions, default content) are kept in sync, while live API keys and secrets already set on production are never overwritten.

CSV and JSON exports are a straight dump of the table (no upsert logic) — useful for backups, audits, or feeding another tool.

## Adding an Export button to another bundle's CRUD controller

`c975L\ConfigBundle\Service\Export\TableExporter` is generic: give it a table name and an array
of associative rows (e.g. from `Connection::fetchAllAssociative()`), it returns a ready-to-serve
`Response` encoded as SQL, CSV, or JSON (via Symfony's Serializer — `CsvEncoder`/`JsonEncoder`
plus a custom `SqlEncoder`). Wire it into your own `AbstractCrudController` the same way
`ConfigCrudController` does:

```php
use c975L\ConfigBundle\Service\Export\ExportFormat;
use c975L\ConfigBundle\Service\Export\TableExporter;
use Doctrine\DBAL\Connection;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\ActionGroup;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use Symfony\Component\HttpFoundation\Response;

class MyEntityCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TableExporter $tableExporter,
    ) {}

    public function configureActions(Actions $actions): Actions
    {
        $exportGroup = ActionGroup::new('export', 'Export', 'fa fa-download')
            ->createAsGlobalActionGroup()
            ->addAction(Action::new('exportSql', 'SQL')->linkToCrudAction('exportSql'))
            ->addAction(Action::new('exportCsv', 'CSV')->linkToCrudAction('exportCsv'))
            ->addAction(Action::new('exportJson', 'JSON')->linkToCrudAction('exportJson'))
        ;

        return $actions->add(Crud::PAGE_INDEX, $exportGroup);
    }

    #[AdminRoute]
    public function exportSql(AdminContext $context): Response
    {
        // Set 'primary_key' to enable ON DUPLICATE KEY UPDATE; omit it for a plain INSERT-only dump
        return $this->tableExporter->export(ExportFormat::Sql, 'my_table', $this->fetchRows());
    }

    #[AdminRoute]
    public function exportCsv(AdminContext $context): Response
    {
        return $this->tableExporter->export(ExportFormat::Csv, 'my_table', $this->fetchRows());
    }

    #[AdminRoute]
    public function exportJson(AdminContext $context): Response
    {
        return $this->tableExporter->export(ExportFormat::Json, 'my_table', $this->fetchRows());
    }

    private function fetchRows(): array
    {
        return $this->connection->fetchAllAssociative('SELECT * FROM `my_table`');
    }
}
```

`export()`'s 4th argument is an optional context array, forwarded to the encoder — only `SqlEncoder`
reads it:

| Key | Type | Effect |
| --- | --- | --- |
| `primary_key` | `string` | Unique column; adds `ON DUPLICATE KEY UPDATE` on every other column. Omit for a plain `INSERT INTO` per row. |
| `exclude_from_update` | `string[]` | Columns never rewritten by the `UPDATE` clause (e.g. an immutable `creation` date). |
| `insert_ignore_when` | `callable(array $row): bool` | When true for a row, emits `INSERT IGNORE INTO` instead of the upsert — see `ConfigCrudController::exportSql()` for the sensitive-value use case. |

## Contributing menu items from other bundles

Satellite bundles add entries to the `/management` dashboard by implementing `MenuProviderInterface` — no manual service tagging needed, `MenuProviderPass` auto-detects any class implementing it.

```php
namespace c975L\MyBundle\Management;

use c975L\ConfigBundle\Management\MenuProviderInterface;
use c975L\MyBundle\Controller\Management\MyCrudController;

class MenuProvider implements MenuProviderInterface
{
    public function getMenuSection(): array
    {
        return [
            'label' => 'label.my_section',
            'translation_domain' => 'my_bundle',
        ];
    }

    public function getMenus(): array
    {
        return [
            'my_entity' => [
                'controller' => MyCrudController::class,
                'label' => 'label.my_entity',
                'translation_domain' => 'my_bundle',
                'icon' => 'fas fa-star',
            ],
        ];
    }

    // Links to plain routes (not EasyAdmin CRUD controllers); return [] if none
    public function getLinks(): array
    {
        return [];
    }
}
```

Make sure your bundle's `services.yaml` includes the `Management/` folder in its `src/` resource so the class is registered.

**Section merging:** if several bundles declare the same `getMenuSection()` (identical `label` + `translation_domain`), their menus are merged under a single section header instead of being duplicated.

**Alphabetical ordering:** within a section, menu items are always sorted alphabetically by their translated label.

**Links section:** `getLinks()` exposes links to plain routes (e.g. a public page), each entry shaped like:

```php
public function getLinks(): array
{
    return [
        'shop' => [
            'name' => 'shop_index',
            'label' => 'label.shop',
            'translation_domain' => 'shop',
            'icon' => 'fas fa-shop',
        ],
    ];
}
```

Links from every bundle are merged into a single "Links" section (opened in a new tab), sorted alphabetically.

## Contributing "What's new" entries from other bundles

The `/management` dashboard shows the 5 latest release notes merged from every c975L bundle, with a link to the full list at `/management/whatsnew`.

Declare your bundle's entries in a `config/whatsnew.json` file:

```json
[
    {
        "version": "1.2.0",
        "date": "2026-07-04",
        "description": "Added new XYZ block"
    }
]
```

Expose them via a `WhatsNewProvider` implementing `WhatsNewProviderInterface` — no manual service tagging needed, `WhatsNewProviderPass` auto-detects any class implementing it (same pattern as `MenuProviderInterface`):

```php
namespace c975L\MyBundle\Management;

use c975L\ConfigBundle\Management\WhatsNewJsonReader;
use c975L\ConfigBundle\Management\WhatsNewProviderInterface;

class WhatsNewProvider implements WhatsNewProviderInterface
{
    private const BUNDLE_NAME = 'MyBundle';

    public function getEntries(): array
    {
        return WhatsNewJsonReader::read(\dirname(__DIR__, 2) . '/config/whatsnew.json', self::BUNDLE_NAME);
    }
}
```

Make sure your bundle's `services.yaml` includes the `Management/` folder in its `src/` resource so the class is registered.

**UiBundle exception:** `UiBundle` cannot depend on `c975l/config-bundle` (the dependency already runs the other way, ConfigBundle → UiBundle), so it doesn't implement `WhatsNewProviderInterface`. It contributes entries through its own `WhatsNewRegistry` (same pattern as `ScriptAdminRegistry`) — see the UiBundle README for how to register entries there; `WhatsNewBuilder` merges them in automatically alongside every other bundle's entries.

## Reading config values

### In PHP

```php
use c975L\ConfigBundle\Service\ConfigServiceInterface;

class MyService
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
    ) {}

    public function doSomething(): void
    {
        $siteName  = $this->configService->get('site-name'); // string
        $maxItems  = $this->configService->get('max-items'); // int (auto-cast)
        $isEnabled = $this->configService->get('feature-enabled'); // bool (auto-cast)
        $env       = $this->configService->getContainerParameter('kernel.environment');
    }
}
```

### In Twig

```twig
{# Read from database #}
{{ config('site-name') }}

{# Read from Symfony container parameters #}
{{ configParam('kernel.environment') }}
```

## License

MIT — see [LICENSE](LICENSE).
