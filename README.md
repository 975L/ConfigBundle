# c975L ConfigBundle

A Symfony bundle that stores application configuration as key-value pairs in the database, with an EasyAdmin management interface, Twig/PHP accessors, and production deployment tooling.

[![GitHub](https://img.shields.io/github/license/975L/ConfigBundle)](https://github.com/975L/ConfigBundle/blob/master/LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/c975l/config-bundle)](https://packagist.org/packages/c975l/config-bundle)
[![PHP Version](https://img.shields.io/packagist/php-v/c975l/config-bundle)](https://packagist.org/packages/c975l/config-bundle)

## Features

- Key-value config entries stored in the database (`site_config` table)
- EasyAdmin CRUD interface to manage values
- SQL export button for production deployment
- Twig and PHP service to read values anywhere
- 1-hour cache with automatic invalidation on change

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

Supported `kind` values: `text`, `int`, `bool`.
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

The bundle registers a management dashboard at `/management`. Navigate to **Config** to view entries and edit their `value` — `label`, `slug`, `kind`, `group`, and `description` are fixed by the bundle's `configs.json` and shown read-only; there is no manual creation or deletion, entries only come from `configs.json`.

### JS assets loaded on the dashboard

The `/management` dashboard loads a dedicated AssetMapper entry, `@c975l/ui-bundle/admin.js` (not your site's main `app` entry), so that satellite bundles needing Stimulus controllers in the back-office (e.g. `c975l/ui-bundle`'s block editor) don't drag your site's front-end stylesheet into EasyAdmin. See the [UiBundle README](https://github.com/975L/UiBundle#installation) for how to define this entry.

### Deploying to production — Export SQL

On the config list page, click the **Export SQL** button. The browser downloads a `site_config_YYYYMMDD_HHMMSS.sql` file — nothing is written to disk or version control.

Import it on your production server:

```bash
mysql -u user -p dbname < site_config_20260626_120000.sql
```

**Behavior per entry type:**

| `is_sensitive` | SQL statement | Effect on production |
| --- | --- | --- |
| `false` | `INSERT … ON DUPLICATE KEY UPDATE` | Creates or updates label, value, kind, group, description |
| `true` | `INSERT IGNORE INTO` | Creates if missing; **preserves existing production value** |
This means non-sensitive values (labels, descriptions, default content) are kept in sync, while live API keys and secrets already set on production are never overwritten.

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
