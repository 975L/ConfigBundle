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
        "description": "Name of the website"
    },
    {
        "label": "Stripe Secret Key",
        "slug": "stripe-secret-key",
        "sensitive": true,
        "value": null,
        "kind": "text",
        "description": "Stripe secret key (sk_live_...)"
    }
]
```

Valid `kind` values: `text`, `html`, `image`, `code`, `bool`, `int`.
Set `sensitive: true` for any entry that holds secrets (API keys, passwords, etc.).

## Loading config entries into the database

### All c975L bundles at once

Auto-discovers every `vendor/c975l/*/config/configs.json` file and loads them in one shot:

```bash
php bin/console c975l:config:load-all
```

## EasyAdmin interface

The bundle registers a management dashboard at `/management`. Navigate to **Config** to view, create, edit, or delete entries.

### Deploying to production — Export SQL

On the config list page, click the **Export SQL** button. The browser downloads a `site_config_YYYYMMDD_HHMMSS.sql` file — nothing is written to disk or version control.

Import it on your production server:

```bash
mysql -u user -p dbname < site_config_20260626_120000.sql
```

**Behavior per entry type:**

| `is_sensitive` | SQL statement | Effect on production |
| --- | --- | --- |
| `false` | `INSERT … ON DUPLICATE KEY UPDATE` | Creates or updates label, value, kind, description |
| `true` | `INSERT IGNORE INTO` | Creates if missing; **preserves existing production value** |

This means non-sensitive values (labels, descriptions, default content) are kept in sync, while live API keys and secrets already set on production are never overwritten.

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
        $siteName  = $this->configService->get('site-name');
        $isEnabled = $this->configService->getBool($this->configService->get('feature-enabled'));
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
