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
- Dashboard alerts (danger/warning/info) aggregating what needs attention, declared by every c975L bundle

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
        "restricted": true,
        "value": null,
        "kind": "text",
        "group": "payment",
        "description": "Stripe secret key (sk_live_...)"
    }
]
```

Supported `kind` values: `text`, `html`, `int`, `bool`, `date`, `json`.
`text` is edited as a plain textarea (URLs, ids, emails...); `html` is for rare configs needing rich content and is edited with EasyAdmin's own rich text editor (same widget as UiBundle blocks).
For `json`, `value` is the raw JSON-encoded string (e.g. `"[\"ROLE_ADMIN\",\"ROLE_EDITOR\"]"`); `ConfigService::get()` returns it already decoded into a PHP array (`[]` if empty/invalid).
Set `sensitive: true` for any entry that holds secrets (API keys, passwords, etc.) — the value is encrypted at rest and masked in the admin list.
Set `restricted: true` on top of that for secrets shared across the whole install rather than per-site data — see [Restricting configs to ROLE_SUPER_ADMIN](#restricting-configs-to-role_super_admin).

`group` is optional and clusters entries on the "pick a group" screen (see below). It must be one of the fixed values in `Config::GROUPS`, each backed by a `label.group_*` translation key:

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
| `theme` | Theme CSS variables (colors, fonts, light/dark mode) |
| `ai` | AI-related settings (LLM providers, prompts...) |
| `messenger` | Symfony Messenger cleanup settings |

This list is closed on purpose so filtering stays useful; if none fits, leave `group` unset rather than inventing a new value (adding one requires extending `Config::GROUPS` and the matching translations in ConfigBundle itself).

`severity` is optional and flags an entry that needs an admin's attention as long as its `value` is empty — it never affects front-end rendering, `ConfigService::get()` still returns `null`/empty as before. It must be one of `Config::SEVERITIES`: `danger`, `warning`, `info`. Any entry with a severity and no value is listed on the `/management` dashboard as a colored alert with a direct link to fill it in; once a value is set, the alert disappears on its own (no flag to unset).

## Loading config entries into the database

Auto-discovers every `vendor/c975l/*/config/configs*.json` file and loads them in one shot — a bundle can ship several files (e.g. `configs.json` plus `configs-css.json` for theme variables), each loaded independently:

```bash
php bin/console c975l:config:load-all
```

New entries (new `slug`) are inserted with their `value` from the JSON. For entries that already exist, only the metadata fixed by the bundle author — `label`, `kind`, `group`, `severity`, `description`, `restricted` — is re-synced from the JSON on every run; `value` and `sensitive` carry production state and are never touched, so editing a `configs.json` file (e.g. moving a config to a new group, fixing a typo in a label) and re-running `load-all` is enough to propagate the change, without risking an admin-set value.

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

**Config** opens on a "pick a group" screen (one row per distinct `group`, with its entry count) rather than one flat table of every entry — picking a group filters the familiar EasyAdmin grid down to just that group's entries, with a "← Config" action to go back. This keeps the list readable as more bundles/groups accumulate; the entry count shown per group respects both the current sensitive/non-sensitive view and, below `ROLE_SUPER_ADMIN`, excludes restricted entries the viewer wouldn't see anyway.

Theme CSS variables (colors, fonts, light/dark mode, fixed by a bundle's `configs-css.json`) are entries like any other, under the `theme` group — reachable the same way, via **Config**'s "pick a group" screen, at the same `site-role-admin` permission as every other group (no dedicated page, no separate permission tier).

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

The SQL export is also available as a `/management` dashboard shortcut ("Export (SQL) the configuration", `ROLE_SUPER_ADMIN`), downloading the same file without opening **Config** first.

## Restricting configs to ROLE_SUPER_ADMIN

Some configs are secrets shared across the whole install rather than per-site application data —
a database backup user, a payment provider's live API key. Anyone with `site-role-admin` access
to the Config admin can normally see and edit every entry (encrypted `sensitive` values are masked
in the list but still revealed in clear on the detail/edit page). Flagging an entry
`"restricted": true` in its `configs.json` takes it a step further: that config disappears
entirely — from the index list, the detail page, the edit page, and every export (SQL/CSV/JSON) —
for anyone who isn't granted `ROLE_SUPER_ADMIN`, regardless of what `site-role-admin` is set to.

This is opt-in per entry (not per `group`), so a bundle only restricts the specific secrets that
need it, leaving the rest of its configs manageable by a regular site admin. `ROLE_SUPER_ADMIN` is
a plain Symfony role, not declared or granted by ConfigBundle itself — the consuming app (or a
bundle like `c975l/site-bundle`) decides who holds it.

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

Links from every bundle are merged into a single "Links" section, sorted alphabetically. `name` is a route name resolved to its real URL through the app's own router (not EasyAdmin's dashboard routing, so it also works for a route outside the dashboard, e.g. a public page). Use `url` instead for a literal, already-absolute URL — it's used as-is, no route resolution at all, and takes precedence when both are set:

```php
'showcase' => [
    'url' => 'https://example.com/showcase',
    'label' => 'label.showcase',
    'translation_domain' => 'my_bundle',
    'icon' => 'fas fa-shapes',
],
```

Two more optional keys: `role` (e.g. `'ROLE_EDITOR'`) hides the link from users lacking it — omit it for links with no access restriction of their own; `target` (e.g. `'_blank'`) is for a link leaving the admin entirely — it gets an external-link glyph automatically, and (for a `name`-based link) resolves to a full absolute URL instead of a relative path.

## Contributing linkable routes for SiteBundle menus

SiteBundle lets site admins add navbar/footer menu items that link to an existing database `Page`, or to a route contributed by another bundle (e.g. ContactFormBundle's `/contact`). This interface lives here (not in SiteBundle) precisely so that bundles which don't depend on SiteBundle (ContactFormBundle, ShopBundle, BookBundle...) can still expose a route, by implementing `LinkableRouteProviderInterface` — no manual service tagging needed, `LinkableRouteProviderPass` auto-detects any class implementing it, same mechanism as `MenuProviderInterface` above.

```php
namespace c975L\MyBundle\Management;

use c975L\ConfigBundle\Management\LinkableRouteProviderInterface;

class LinkableRouteProvider implements LinkableRouteProviderInterface
{
    // Route name => ['label' => translation key, 'translation_domain' => domain]; return [] if none
    public function getLinkableRoutes(): array
    {
        return [
            'my_bundle_display' => [
                'label' => 'label.my_page',
                'translation_domain' => 'my_bundle',
            ],
        ];
    }
}
```

Make sure your bundle's `services.yaml` includes the `Management/` folder in its `src/` resource so the class is registered.

Routes are checked live: if the contributing bundle is later removed (or its provider stops returning that route), any menu item pointing to it simply disappears from the rendered menu instead of producing a broken link.

## Contributing "What's new" entries from other bundles

The `/management` dashboard shows the 5 latest release notes merged from every c975L bundle, with a link to the full list at `/management/whatsnew`.

This is a marketing-style feed for non-developer back-office users, not a developer changelog (see `ChangeLog.md` for that) — there's no `version` or `bundle` field, and entries should read as user-facing benefits, not technical changes.

Declare your bundle's entries in a `config/whatsnew.json` file:

```json
[
    {
        "date": "2026-07-04",
        "description": [
            {
                "en": "Added a new XYZ block",
                "fr": "Ajout d'un nouveau bloc XYZ",
                "es": "Añadido un nuevo bloque XYZ"
            }
        ]
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
    public function getEntries(): array
    {
        return WhatsNewJsonReader::read(\dirname(__DIR__, 2) . '/config/whatsnew.json');
    }
}
```

Make sure your bundle's `services.yaml` includes the `Management/` folder in its `src/` resource so the class is registered.

**UiBundle exception:** `UiBundle` cannot depend on `c975l/config-bundle` (the dependency already runs the other way, ConfigBundle → UiBundle), so it doesn't implement `WhatsNewProviderInterface`. It contributes entries through its own `WhatsNewRegistry` (same pattern as `ScriptAdminRegistry`) — see the UiBundle README for how to register entries there; `WhatsNewBuilder` merges them in automatically alongside every other bundle's entries.

## Contributing dashboard alerts from other bundles

The `/management` dashboard, and each CRUD's own index page, can show a severity-grouped alert list (danger/warning/info) pointing at whatever needs attention — e.g. configs missing a value.

Satellite bundles contribute alerts by implementing `AlertProviderInterface` — no manual service tagging needed, `AlertProviderPass` auto-detects any class implementing it (same pattern as `MenuProviderInterface`):

```php
namespace c975L\MyBundle\Management;

use c975L\ConfigBundle\Entity\Config;
use c975L\ConfigBundle\Management\AlertProviderInterface;

class MyAlertProvider implements AlertProviderInterface
{
    public function getAlerts(): array
    {
        return [
            [
                'label' => 'My entity label',
                'description' => 'Why it needs attention',
                'severity' => Config::SEVERITY_WARNING,
                'url' => '/management/my-entity/edit/1',
            ],
        ];
    }
}
```

Make sure your bundle's `services.yaml` includes the `Management/` folder in its `src/` resource so the class is registered.

**Dashboard aggregation:** `AlertBuilder::getAlerts()` merges every provider's alerts and groups them by severity for the main `/management` dashboard.

**Own CRUD index:** a controller that only wants its own provider's alerts (not every bundle's) calls `AlertBuilder::groupBySeverity()` directly on that provider's flat list — see `ConfigCrudController` for an example.

**Rendering:** both cases are rendered with the shared `templates/management/_alerts.html.twig` partial, which expects a severity-grouped `alerts` array and a translated `title`.

## Contributing dashboard shortcuts from other bundles

The `/management` dashboard shows a row of quick-action buttons (e.g. clearing a cache, toggling maintenance mode) contributed by any bundle.

Satellite bundles contribute shortcuts by implementing `ShortcutProviderInterface` — no manual service tagging needed, `ShortcutProviderPass` auto-detects any class implementing it (same pattern as `MenuProviderInterface`):

```php
namespace c975L\MyBundle\Management;

use c975L\ConfigBundle\Management\ShortcutProviderInterface;
use c975L\MyBundle\Controller\Management\MyShortcutController;
use Symfony\Contracts\Translation\TranslatorInterface;

class MyShortcutProvider implements ShortcutProviderInterface
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getShortcuts(): array
    {
        return [
            [
                'label' => $this->translator->trans('label.toggle_maintenance', [], 'my_bundle'),
                'icon' => 'fas fa-wrench',
                'route' => MyShortcutController::TOGGLE_MAINTENANCE_ROUTE,
                'active' => $this->isMaintenanceOn(),
            ],
        ];
    }
}
```

Make sure your bundle's `services.yaml` includes the `Management/` folder in its `src/` resource so the class is registered.

**Unlike menus/links, shortcuts trigger an action, not just navigation.** `route` must accept a `POST` request and validate its own CSRF token (`csrf_token(route)` is the token id used by the shared template) — see `ConfigShortcutController::clearCache()` for a one-shot reference implementation that clears the config cache.

**`active`:** styles the button (`btn-danger` when `true`, `btn-outline-secondary` otherwise) to reflect an on/off state. See `MaintenanceShortcutController::toggle()` for a toggle reference implementation flipping the `site-maintenance` config used by `MaintenanceListener`, with `ConfigShortcutProvider::getShortcuts()` reading that same config to decide `active` and pick the right label ("Enable"/"Disable"). One-shot actions with no on/off state can always return `false`.

**Rendering:** shortcuts are merged across every provider by `ShortcutBuilder::getShortcuts()` and rendered with the shared `templates/management/_shortcuts.html.twig` partial, each one as its own small `<form method="post">`.

## Contributing theme presets from other bundles

`ThemePresetProviderInterface` lets a satellite bundle contribute named presets — a vetted set of values (currently just the site's visual shape: rounded corners, shadows, navigation/footer layout...) an admin could switch to in one click, without touching colors/fonts (those stay entirely admin-owned, a preset never overwrites them). There is currently no admin UI applying a preset from the Config screen itself (the former **Theme** page's "Presets" action group was removed along with that dedicated page — theme entries are now just the `theme` group on the regular Config screen, see above); the interface still exists for any bundle-owned feature that reads presets directly (e.g. SiteBundle's own `?preset=<slug>` per-page preview).

Satellite bundles contribute presets by implementing `ThemePresetProviderInterface` — no manual service tagging needed, `TaggedInterfacePass` auto-detects any class implementing it, same mechanism as `MenuProviderInterface` above:

```php
namespace c975L\MyBundle\Management;

use c975L\ConfigBundle\Management\ThemePresetProviderInterface;

class MyThemePresetProvider implements ThemePresetProviderInterface
{
    // id => ['label' => translation key, 'domain' => translation domain, 'stylesheet' => Config::GROUP_THEME's "theme-stylesheet" value, 'previewUrl' => optional callable(): string]
    public function getPresets(): array
    {
        return [
            'my-preset' => [
                'label' => 'label.my_preset',
                'domain' => 'my_bundle',
                'stylesheet' => 'my-preset',
                'previewUrl' => fn () => $this->router->generate('my_bundle_preview', ['preset' => 'my-preset']),
            ],
        ];
    }
}
```

Make sure your bundle's `services.yaml` includes the `Management/` folder in its `src/` resource so the class is registered.

**`domain`** is the translation domain owning `label` — your own bundle's, not necessarily `config` (which is only the fallback for a provider that doesn't declare one).

**`previewUrl`** must be a lazy callable, not an already-generated string: `ThemePresetRegistry` is built as a constructor dependency while EasyAdmin is still enumerating routes, so eagerly calling the router at that point deadlocks.

**`stylesheet`** is meant to be the only config a preset ever writes (the `theme-stylesheet` entry, under the `theme` group) — colors and fonts are never touched, so a preset never overwrites values the admin has deliberately chosen. It's nullable: a preset that sets it to `null` is expected to leave the current stylesheet untouched rather than blanking it.

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

---

> [!TIP]
> If this project **helps you save development time**:
>
> - [**star** it on GitHub](https://github.com/975L/ConfigBundle) — helps others find it
> - [**open an issue**](https://github.com/975L/ConfigBundle/issues/new) to share how you use it — genuinely useful feedback
>
> And if you'd like to support the work directly, the **Sponsor** button at the top of the GitHub page is there for that. Thank you!

## License

MIT — see [LICENSE](LICENSE).
