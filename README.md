# c975L ConfigBundle

Symfony bundle providing the EasyAdmin dashboard and database-backed configuration at the root of the c975L ecosystem — the shared hub every satellite bundle plugs into for menus, exports/imports, alerts, and other cross-bundle dashboard contributions.

[![GitHub](https://img.shields.io/github/license/975L/ConfigBundle)](https://github.com/975L/ConfigBundle/blob/master/LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/c975l/config-bundle)](https://packagist.org/packages/c975l/config-bundle)
[![PHP Version](https://img.shields.io/packagist/php-v/c975l/config-bundle)](https://packagist.org/packages/c975l/config-bundle)
[![Codacy Grade](https://app.codacy.com/project/badge/Grade/42533e7972fc42f980e93048225f3f31)](https://app.codacy.com/gh/975L/ConfigBundle/dashboard)

## Why ConfigBundle

![ConfigBundle](./.github/images/ConfigBundle.svg)

The root of the c975L ecosystem — every other bundle ([UiBundle](https://github.com/975L/UiBundle), [SiteBundle](https://github.com/975L/SiteBundle), [ShopBundle](https://github.com/975L/ShopBundle), [BookBundle](https://github.com/975L/BookBundle), [GalleryBundle](https://github.com/975L/GalleryBundle), [SocialBundle](https://github.com/975L/SocialBundle)...) depends on it, directly or through UiBundle. It's the single place for application configuration: no per-app `.env` for business config, no duplicated dashboard entry mechanism — a satellite bundle just implements `MenuProviderInterface` (or one of its siblings) and gets an EasyAdmin entry for free.

See it in action at [975l.com/pages/config-bundle](https://975l.com/pages/config-bundle).

---

> **TL;DR** — Application configuration lives in the database (`site_config`), not in `.env`: a bundle declares its entries in `config/configs.json`, `c975l:config:load-all` inserts them, the site owner edits the values in EasyAdmin, and any code reads them back through `ConfigServiceInterface` or the `config()` Twig function. ConfigBundle also owns the `/management` dashboard the whole c975L ecosystem plugs into — menus, alerts, shortcuts, health check, backup — which is why most of this README is extension points for other bundles.

## Contents

- **Config entries** — [declare](#defining-config-entries-for-your-bundle) · [load](#loading-config-entries-into-the-database) · [prune](#pruning-entries-no-longer-declared) · [set from the CLI](#setting-values-from-the-command-line) · [encrypt](#encrypting-sensitive-values) · [read in PHP/Twig](#reading-config-values)
- **Dashboard** — [EasyAdmin interface](#easyadmin-interface) · [export for deployment](#deploying-to-production--export) · [ROLE_SUPER_ADMIN-only entries](#restricting-configs-to-role_super_admin) · [Export button in another CRUD](#adding-an-export-button-to-another-bundles-crud-controller)
- **Site maintenance** — [Maintenance mode](#maintenance-mode) · [Health check](#health-check) · [Backup](#backup) · [Dev profile](#dev-profile--automating-what-the-dev-toolbar-shows)
- **Extension points for other bundles** — [menu items](#contributing-menu-items-from-other-bundles) · [dashboard alerts](#contributing-dashboard-alerts-from-other-bundles) · [shortcuts](#contributing-dashboard-shortcuts-from-other-bundles) · [essential actions](#contributing-essential-actions-from-other-bundles) · [widgets](#contributing-dashboard-widgets-from-other-bundles) · [guided projects](#contributing-guided-projects-from-other-bundles) · [health check providers](#contributing-health-check-providers-from-other-bundles) and [advice](#contributing-health-check-advice-from-other-bundles) · [sitemaps](#contributing-a-sitemap-from-other-bundles) · [importmap entries](#contributing-importmap-entries-from-other-bundles) · [import](#contributing-import-providers-from-other-bundles) and [export providers](#contributing-export-providers-from-other-bundles) · ["What's new" entries](#contributing-whats-new-entries-from-other-bundles) · [linkable routes](#contributing-linkable-routes-for-sitebundle-menus) · [dev profile paths](#contributing-dev-profile-paths-from-other-bundles) · [AI assistant procedures](#contributing-procedures-for-the-dashboard-ai-assistant)

## Features

- Key-value config entries stored in the database (`site_config` table)
- EasyAdmin CRUD interface to manage values
- `c975l:config:set` to fill values from the command line or a JSON file, for provisioning, deployment and tests
- "Obsolete configs" dashboard page and `c975l:config:prune` to delete entries no `configs*.json` declares anymore
- Export button (SQL/CSV/JSON/Sync-zip) for production deployment, reusable from any bundle's CRUD controller
- Zip-based content import/export for syncing nested bundle content across environments, extensible via `ImportProviderInterface`/`ExportProviderInterface`
- Twig and PHP service to read values anywhere
- 1-hour cache with automatic invalidation on change
- "What's new" dashboard section aggregating release notes declared by every c975L bundle
- Dashboard alerts (danger/warning/info) aggregating what needs attention, declared by every c975L bundle
- Dashboard "Essential actions" checklist, a permanent quick-access entry point to the handful of settings every site needs
- Dashboard widgets contributed by other bundles (e.g. UiBundle's Donovan card)
- Dashboard "Guided tour" walking through every sidebar item that declares a `description`
- Dashboard "Guided projects" walking through a whole task across the admin screens it spans, extensible via `GuidedProjectProviderInterface`
- "Health check" dashboard page (Lighthouse scores, security headers, W3C/accessibility checks...) with history, a trend chart, and CSV export, extensible via `HealthCheckProviderInterface`/`HealthCheckAdviceProviderInterface`
- `c975l:config:backup`, dumping the database table by table and archiving `public/`+`private/`, with archive integrity verification, a retention window on the server, a dashboard alert when a backup stops running, and a weekly digest email for the sites whose dashboard you don't open daily
- Maintenance mode closing the site to its visitors, answering the search-engine-friendly 503 they expect from a temporary outage, with a dashboard alert turning to danger once it has lasted long enough to cost indexing
- Sitemap generation (one sub-sitemap per bundle plus the sitemap index), extensible via `SitemapProviderInterface`
- `c975l:dev-profile:run`, a dev-only command listing what the Symfony dev toolbar would flag on every page (n+1 queries, deprecations, missing translations...), extensible via `DevProfilePathProviderInterface`

## Installation

Requires PHP 8.4 and Symfony 8.

```bash
composer require c975l/config-bundle
```

Make your user entity implement `Contract\UserInterface` — that's the interface the c975L bundles relate to instead of `App\Entity\User`, which they cannot reference. It extends Symfony's own `UserInterface` and only adds `getId(): int|string|null`, the getter your entity already has:

```php
// src/Entity/User.php
use c975L\ConfigBundle\Contract\UserInterface;

class User implements UserInterface
{
    // ...
}
```

Doctrine resolves the interface to your entity on its own — `c975LConfigBundle::prependExtension()` declares it through `resolve_target_entities`, there is nothing to add to your configuration.

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

Supported `kind` values: `text`, `html`, `int`, `bool`, `date`, `json`, `font`.
`text` is edited as a plain textarea (URLs, ids, emails...); `html` is for rare configs needing rich content and is edited with EasyAdmin's own rich text editor (same widget as UiBundle blocks).
`font` renders a `<select>` (UiBundle's `FontChoiceType`/`FontRegistry`) combining `Config::GENERIC_FONT_FAMILIES` (`serif`, `sans-serif`, `monospace`, always offered) with whatever custom font-family names a registered `FontProviderInterface` knows about (e.g. SiteBundle's `FontService`, parsed from a CSS file's `@font-face` declarations) — falls back to only the 3 generics when no provider is registered. A value no longer offered by either source (e.g. removed from `@font-face`) is kept selectable instead of being silently dropped on the next save.
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

Auto-discovers every `vendor/c975l/*/config/configs*.json` file **plus the application's own `config/configs*.json`**, and loads them in one shot — a bundle can ship several files (e.g. `configs.json` plus `configs-css.json` for theme variables), each loaded independently:

```bash
php bin/console c975l:config:load-all
```

The application file is loaded exactly like a bundle's one, so an app needing a setting no bundle declares (its own API keys, feature flags...) just drops a `config/configs.json` at its root and gets it in the dashboard, with no command of its own to write.

New entries (new `slug`) are inserted with their `value` from the JSON. For entries that already exist, only the metadata fixed by the bundle author — `label`, `kind`, `group`, `severity`, `description`, `restricted`, `sensitive` — is re-synced from the JSON on every run; the `value` carries production state and is never overwritten, so editing a `configs.json` file (e.g. moving a config to a new group, fixing a typo in a label) and re-running `load-all` is enough to propagate the change, without risking an admin-set value.

`sensitive` is the one flag whose change also touches the value, because the two can't be separated: an entry that becomes sensitive gets its value encrypted, one that stops being sensitive gets it decrypted. Without that, dropping `"sensitive": true` from a declaration would leave a `C975L:…` string sitting in what is now a plain-text setting. When the conversion can't be done — no `C975L_VAULT_KEY`, or a value encrypted with a different one — the flag is left as it was rather than storing something unusable, and the next run picks it up once the key is in place.

`site-role-admin` is the one entry read before it can exist: every `/management` permission derives from it, so a database missing that row — a fresh install where `load-all` hasn't run yet, an entry deleted by mistake — would deny the dashboard to everyone, including whoever would fix it. `ConfigService::loadAll()` therefore falls back on its declared default, `ROLE_ADMIN`, as long as the row is absent.

## Pruning entries no longer declared

An entry dropped from a `configs*.json` (a setting replaced by a proper entity, a bundle uninstalled) stays in database forever: `load-all` only ever inserts and syncs metadata, it never deletes — and it says nothing about those leftovers either, being a deployment step whose output nobody reads. Removing them is an explicit step of its own. From the dashboard, the **Obsolete configs** shortcut (`ROLE_SUPER_ADMIN`) lists them with the value each deletion would take with it, and deletes the ones ticked. Or, without a browser:

```bash
php bin/console c975l:config:prune            # lists them, deletes nothing
php bin/console c975l:config:prune --force    # deletes them, after confirmation
```

Both share the same safeguard, because "undeclared" is only meaningful when the declarations are all there: neither reports a single orphan when no `configs*.json` is found at all, an unfinished `composer install` otherwise making every entry look orphaned, nor when one exists but can't be parsed, a single misplaced comma otherwise turning everything that file declares into an orphan. The command adds a confirmation prompt in interactive mode, the page its list of what is about to go. Deletion takes the stored value with it — export your configs first if a bundle is only temporarily uninstalled.

## Setting values from the command line

`load-all` declares the entries, the EasyAdmin interface fills them in. To fill them in without a browser — provisioning a fresh environment, a deployment pipeline, a test fixture, restoring a site — use:

```bash
php bin/console c975l:config:set site-name "My Site"
```

Several entries at once, from a JSON file holding a `{"slug": "value"}` object:

```bash
php bin/console c975l:config:set --file=values.json
```

```json
{
    "site-name": "My Site",
    "site-form-delay": 3,
    "user-roles-available": ["ROLE_ADMIN", "ROLE_EDITOR"],
    "stripe-secret": "sk_live_..."
}
```

Booleans, numbers and arrays are converted to the string stored in database, and each value is checked against its entry `kind` (`bool` only accepts `true`/`false`, `int` an integer, `json` valid JSON, `date` a parsable date).

| Option | Effect |
| --- | --- |
| `--if-empty` | Only fills entries whose value is still empty, never overwrites one already set |
| `--dry-run` | Lists what would change without writing anything |

The command is meant to be re-run: an empty value is always skipped (an incomplete file never blanks out a live setting), an unchanged value is skipped too (no pointless `modification` date), and `--if-empty` makes a whole file idempotent — which is what a deployment pipeline wants, filling in whatever new entry the last `composer update` brought in while leaving production values alone.

Entries are never created here: an unknown slug is reported and the command exits non-zero, so a typo doesn't pass silently. Sensitive entries are encrypted with `C975L_VAULT_KEY` exactly as the back-office does, are masked in the output so no secret lands in a CI log, and are refused rather than stored in plain text when no key is defined.

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

The `/management` dashboard loads dedicated AssetMapper entries (not your site's main `app` entry), so that satellite bundles needing Stimulus controllers in the back-office don't drag your site's front-end stylesheet into EasyAdmin. `c975l/ui-bundle` contributes one for its block editor — see the [UiBundle README](https://github.com/975L/UiBundle#installation) for how to define that entry.

ConfigBundle contributes its own, `@c975l/config-bundle/controllers-admin.js`, for the dashboard's guided tour (see [Contributing menu items from other bundles](#contributing-menu-items-from-other-bundles) below for how a bundle's own menu entries feed into it) and its Health check trend chart (see below). This entry (and any other c975L bundle's own admin JS) is added to your `importmap.php` automatically — see [Contributing importmap entries from other bundles](#contributing-importmap-entries-from-other-bundles) below, nothing to add by hand.

**`symfony/ux-chartjs`** is a regular Composer dependency (not something to add manually) - Symfony Flex registers `ChartjsBundle` and its own `importmap.php`/`chart.js` entries automatically the first time you `composer update` after installing/upgrading ConfigBundle.

That same Flex recipe also writes an **eager** entry into your app's `assets/controllers.json`, which you should turn off:

```json
{
    "controllers": {
        "@symfony/ux-chartjs": {
            "chart": {
                "enabled": false,
                "fetch": "eager"
            }
        }
    },
    "entrypoints": []
}
```

`startStimulusApp()` statically imports every `enabled`+`eager` controller listed there, so leaving it on has two costs. On the **front-end**, your `app.js` pulls `chart.js` (~66 KiB transferred) onto every public page, where no chart is ever rendered. On the **`/management` dashboard**, each admin entry starts its own independent Stimulus app (see `DashboardController::configureAssets()`) and each one registers the chart controller again — with four c975L bundles installed, four applications call `new Chart()` on the same `<canvas>`, which Chart.js rejects with *"Canvas is already in use"*.

On the dashboard, disabling it costs nothing: `controllers-admin.js` registers the chart controller explicitly, once. Use `"enabled": false` rather than `"fetch": "lazy"` — lazy fixes the front-end bytes but still lets every admin Stimulus app register the controller on its own. `c975l:config:check-importmap` warns when it finds the entry still enabled — the warning is about the dashboard, so ignore it if your app calls `render_chart()` on a public page too (that page does need the front-end controller, and `"fetch": "lazy"` is then the right trade-off).

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

A fifth **SQL + secrets** export (`ROLE_SUPER_ADMIN`) drops that last safeguard and upserts the sensitive rows too, so an environment where the secrets are already filled in can hand them over instead of having them typed again. The exported value stays encrypted — it is therefore only usable on a target sharing the **same `C975L_VAULT_KEY`**, and on any other target it would replace working secrets with strings that environment cannot decrypt. Use the plain **SQL** export whenever the keys differ. A sensitive entry left empty on the source keeps its `INSERT IGNORE` even there: it has nothing to hand over, and an upsert would only empty the secret filled on the target.

CSV and JSON exports are a straight dump of the table (no upsert logic) — useful for backups, audits, or feeding another tool.

The SQL export is also available as a `/management` dashboard shortcut ("Export (SQL) the configuration", `site-role-admin`), downloading the same file without opening **Config** first.

A fourth **Sync** export produces a zip (`manifest.json` plus any referenced files) instead of a flat table dump — re-upload it on another environment via **Import content** to upsert the same rows there, matched by `slug` rather than by database id. See [Contributing import providers from other bundles](#contributing-import-providers-from-other-bundles) below.

On import, sensitive entries follow the same safeguard as the SQL export: one already holding a value on the target keeps it, since it is encrypted with that environment's own key. One sitting there empty — the blank row `load-all` creates from a declaration — takes the export's value instead, otherwise a secret could never be handed over to an environment that had run `load-all` first.

The `/management` dashboard also has an **Export sync (everything)** shortcut (`site-role-admin`), bundling every registered `ExportProviderInterface`'s whole content (Config plus whatever other bundles contribute, e.g. pages, fonts) into a single zip — the "sync everything to prod in one click" counterpart to the per-bundle **Sync** export above, re-uploaded via **Import content** the same way. See [Contributing export providers from other bundles](#contributing-export-providers-from-other-bundles) below.

## Restricting configs to ROLE_SUPER_ADMIN

Some configs are secrets shared across the whole install rather than per-site application data —
a database backup user, a payment provider's live API key. Anyone with `site-role-admin` access
to the Config admin can normally see and edit every entry (encrypted `sensitive` values are masked
in the list but still revealed in clear on the edit page). Flagging an entry
`"restricted": true` in its `configs.json` takes it a step further: that config disappears
entirely — from the index list, the edit page, and every export (SQL/CSV/JSON) —
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

## Contributing import providers from other bundles

`c975L\ConfigBundle\Service\Export\ContentExporter` is the counterpart to `TableExporter` above, for content that doesn't fit a flat table dump — nested structures (e.g. a Page with its Blocks) and real files (e.g. a Block's Media), shipped as a zip (`manifest.json` plus any referenced files) instead of a single SQL/CSV/JSON payload. `ConfigCrudController::exportContent()` is the reference caller, producing the **Sync** export mentioned above.

To accept that zip back on another environment, implement `ImportProviderInterface` — no manual service tagging needed, `TaggedInterfacePass` auto-detects any class implementing it, same mechanism as `MenuProviderInterface` above:

```php
namespace c975L\MyBundle\Management;

use c975L\ConfigBundle\Management\ImportProviderInterface;

class MyImportProvider implements ImportProviderInterface
{
    // $kind is the string embedded in the export payload (see ContentExporter::export()), stable across dev/prod (e.g. "site_page")
    public function supportsImport(string $kind): bool
    {
        return 'my_entity' === $kind;
    }

    // $items are the payload's raw "items" array, one entry per exported entity. $filesDir is the directory the export's zip was extracted into — any 'file' reference inside $items is relative to it, null for a kind that never carries files. Match by a natural key (slug/name...), never a raw id: dev and prod ids never need to match. Returns ['created' => int, 'updated' => int]
    public function import(array $items, ?string $filesDir = null): array
    {
        // ...
    }
}
```

Make sure your bundle's `services.yaml` includes the `Management/` folder in its `src/` resource so the class is registered.

Uploaded zips are accepted at the **Import content** dashboard link (`ROLE_SUPER_ADMIN` — it writes arbitrary content straight into the database, unlike the export side which stays at `site-role-admin`), which extracts the zip, reads `manifest.json`'s `kind`, and dispatches to whichever registered provider's `supportsImport()` matches it.

## Contributing export providers from other bundles

`ExportProviderInterface` is the export-side mirror of `ImportProviderInterface` above — same "kind" values, same natural-key philosophy. Implementing it makes your bundle's content part of the **Export sync (everything)** dashboard shortcut, without touching that shortcut's own code:

```php
namespace c975L\MyBundle\Management;

use c975L\ConfigBundle\Management\ExportProviderInterface;

class MyExportProvider implements ExportProviderInterface
{
    // The string embedded in the export payload for this provider's items (see ContentExporter), stable across dev/prod (e.g. "my_entity")
    public function getKind(): string
    {
        return 'my_entity';
    }

    // Same shapes ContentExporter::export() expects: 'items' (JSON-able array, one entry per exported entity) and 'files' (archive-relative path => disk path, empty for a kind that never carries files)
    public function exportAll(): array
    {
        return ['items' => $this->fetchItems(), 'files' => []];
    }
}
```

Make sure your bundle's `services.yaml` includes the `Management/` folder in its `src/` resource so the class is registered — no manual service tagging needed, `TaggedInterfacePass` auto-detects any class implementing it, same mechanism as `MenuProviderInterface` below.

`SyncAllExporter` collects every registered `ExportProviderInterface` into a single zip (same `manifest.json`-plus-files shape as a single-kind **Sync** export, just with several `{kind, items}` blocks under `exports`) — a bundle that isn't installed simply doesn't contribute a section, no configuration needed on either side. On import, `ContentImportController` detects that multi-section shape automatically and dispatches each section to its own `ImportProviderInterface`, same as a single-kind zip.

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

**Advanced tier:** both `getMenuSection()` and each entry in `getMenus()` accept an optional `'tier' => 'advanced'` key (default `'essential'`). Items opting into it are pulled out of their section and collected into one collapsed "Advanced" submenu at the bottom of the sidebar, instead of staying under their own section header — set it on `getMenuSection()` to move every item of that provider's section, or on an individual entry in `getMenus()` to move just that one (its section keeps its other items at the top level). Several providers commonly share one section (e.g. Config/Site/UiBundle all merge into "management"), so an item's own `tier` never drags along another provider's items sharing that same section.

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

A few more optional keys: `role` (e.g. `'ROLE_EDITOR'`) hides the link from users lacking it — omit it for links with no access restriction of their own; `target` (e.g. `'_blank'`) is for a link leaving the admin entirely — it gets an external-link glyph automatically, and (for a `name`-based link) resolves to a full absolute URL instead of a relative path; `pinned` (bool) sorts the link after every non-pinned one regardless of its label — ConfigBundle's own "Visit the site" link (using the `site-url`/`site-name` configs) uses it to always stay at the very bottom of the links section; `label_parameters` (array) is passed through to the translator alongside `label`, for a translated label embedding a runtime value (e.g. `['%name%' => $siteName]`) — omit it for a plain translation key with no placeholder, the usual case.

**Guided tour:** any entry in `getMenus()`/`getLinks()` can add an optional `'description'` key — a one-line "what is this for" sentence, same `translation_domain` — to feed the `/management` dashboard's "Guided tour" button. It highlights every described item in turn with a short explanation, matched against the sidebar's own rendered link (see `OnboardingStepBuilder`), so there's nothing else to wire up. It's entirely optional and can be filled in bundle by bundle: an entry without a `description` is simply skipped, it never breaks anything.

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

## Contributing importmap entries from other bundles

If your bundle ships its own Stimulus controller for the `/management` dashboard (or any other AssetMapper entry the consuming app needs in its `importmap.php`), implement `ImportmapProviderInterface` — no manual service tagging needed, same `TaggedInterfacePass` mechanism as `MenuProviderInterface` above.

The interface has two methods, mirroring `c975l/ui-bundle`'s own `BundleScriptAdminProviderInterface`/`BundleScriptProviderInterface` admin/non-admin split: `getAdminImportmapEntries()` for scripts loaded on the `/management` dashboard only, `getImportmapEntries()` for anything else (a front-end Stimulus controller, or any other AssetMapper dependency). Both end up in the same `importmap.php` — the split only matters to keep each entry's purpose explicit at the declaration site. Return `[]` from whichever one doesn't apply.

```php
namespace c975L\MyBundle\Management;

use c975L\ConfigBundle\Management\ImportmapProviderInterface;

class ImportmapProvider implements ImportmapProviderInterface
{
    // Import name => ['path' => string, 'entrypoint' => bool]. 'path' is relative to the project root, exactly as it should appear in importmap.php
    public function getAdminImportmapEntries(): array
    {
        return [
            '@c975l/my-bundle/controllers-admin.js' => [
                'path' => './vendor/c975l/my-bundle/assets/controllers-admin.js',
                'entrypoint' => true,
            ],
        ];
    }

    public function getImportmapEntries(): array
    {
        return [];
    }
}
```

Make sure your bundle's `services.yaml` includes the `Management/` folder in its `src/` resource so the class is registered.

Entries contributed this way aren't written to `importmap.php` on their own — nothing hooks into Composer from inside a bundle. Wire the collecting command into each consuming app's `composer.json`, in the same `auto-scripts` block that already runs `importmap:install`:

```json
"auto-scripts": {
    "cache:clear": "symfony-cmd",
    "assets:install %PUBLIC_DIR%": "symfony-cmd",
    "importmap:install": "symfony-cmd",
    "c975l:config:check-importmap": "symfony-cmd"
}
```

`c975l:config:check-importmap` then runs on every `composer install`/`composer update`: it adds any entry contributed by an `ImportmapProviderInterface` that's missing from `importmap.php`, and never touches one that's already there (so a manually customized `path` survives). This is a one-time addition per app — after that, a new bundle (or a new provider in an existing one) picks up its `importmap.php` entry on the next `composer update` with no further action.

It also covers the **third-party packages the c975L bundles' own JS imports by bare specifier** — `@symfony/ux-chartjs`, imported by this bundle's `controllers-admin.js` for the health check trend chart, being the one that actually bites. That entry is normally written by the package's own Flex recipe, which doesn't always run; when it's missing, the browser can't resolve the specifier, the **whole module fails**, and every Stimulus controller it was going to register is silently lost — back-office block drag-and-drop and duplication included, with nothing but a console error to show for it. The command scans each installed c975L bundle's `assets/**/*.js`, and for any bare specifier with no entry it resolves the path from the package's own `assets/package.json` (`name` + `main`, the Symfony UX convention) and adds it as a non-entrypoint. A specifier it can't find under `vendor/` is reported instead of guessed at — install the package, or add the entry by hand.

## Contributing a sitemap from other bundles

If your bundle has public urls of its own (a book catalogue, a shop, a gallery…), implement `SitemapProviderInterface` — no manual service tagging needed, same `TaggedInterfacePass` mechanism as `MenuProviderInterface` above.

`SitemapWriter` then writes one `public/sitemap-<getSitemapName()>.xml` per provider **and** the `public/sitemap-index.xml` declaring them all, so a bundle never renders or writes a sitemap itself, and the consuming app has nothing to list by hand. It runs from the `c975l:sitemaps:create` command (schedule it, see `c975l/site-bundle`'s scheduler section) and from the "Create sitemaps" dashboard shortcut. Both the writer and the two Twig templates live here rather than in SiteBundle, so any combination of bundles gets its sitemaps and its index, SiteBundle installed or not.

> [!TIP]
> Implementing this interface also gets your urls **health-checked**, at no extra cost: with `c975l/site-bundle` installed, its `DeclaredUrlsHealthCheckPass` registers one health check provider per `SitemapProviderInterface`, under its own `urls-<getSitemapName()>` kind (see [Health check](#health-check) and SiteBundle's own README). Nothing else to implement, and each bundle's urls stay schedulable on their own.

```php
namespace c975L\MyBundle\Management;

use c975L\ConfigBundle\Management\SitemapProviderInterface;

class MySitemapProvider implements SitemapProviderInterface
{
    // Gives public/sitemap-my-bundle.xml - keep it short and stable, it ends up in a public url
    public function getSitemapName(): string
    {
        return 'my-bundle';
    }

    public function getUrls(): array
    {
        return [[
            'loc' => 'https://example.com/my-thing/some-slug',
            'lastmod' => '2026-07-26',
            'changefreq' => 'monthly',
            'priority' => 8,
        ]];
    }
}
```

Make sure your bundle's `services.yaml` includes the `Management/` folder in its `src/` resource so the class is registered.

`priority` is an integer on the admin's own `0`-`10` scale (the same one as a page's priority), converted by `SitemapWriter` to the `0.0`-`1.0` the sitemap protocol accepts — so a provider never does that conversion itself. A value outside the scale is bounded, and a missing `lastmod`/`changefreq`/`priority` is defaulted (today, `weekly`, `5`), so an incomplete url degrades instead of producing an invalid sitemap. `getSitemapName()` has to be unique across every installed bundle: two providers sharing it would overwrite each other's file, so it throws a `LogicException` instead.

Return `[]` when there's nothing to declare (a bundle installed but with nothing published yet): no file is written and nothing is added to the index — an indexed empty `urlset` is just a crawl error, and any file left by a previous run is removed so nothing stale keeps being served. Same when `site-url` isn't configured, since a sitemap only accepts absolute urls: no provider can build one, so no index is written either.

Point Google Search Console at `sitemap-index.xml` only, never at the sub-sitemaps — installing or removing a bundle then changes what's crawled with nothing to update on Google's side. Both templates are overridable: `@c975LConfig/sitemaps/sitemap.xml.twig` (a sub-sitemap, gets `urls`) and `@c975LConfig/sitemaps/sitemap-index.xml.twig` (the index, gets `sitemaps`).

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

**Restricting an alert:** add an optional `'role' => 'ROLE_SUPER_ADMIN'` to an entry and `AlertBuilder` drops it for anyone lacking that role, same key as a shortcut tile's. Use it when the configs behind the alert are themselves `restricted` (see [Restricting configs to ROLE_SUPER_ADMIN](#restricting-configs-to-role_super_admin)) — `BackupAlertProvider` does exactly that: an admin who can't even read the backup settings can do nothing about a backup that stopped running, so the alert would be noise on their dashboard rather than information. Omit the key for an alert every admin should act on.

**Own CRUD index:** a controller that only wants its own provider's alerts (not every bundle's) calls `AlertBuilder::groupBySeverity()` directly on that provider's flat list — see `ConfigCrudController` for an example. That path does no role filtering, the controller having already gated its own page.

**Rendering:** both cases are rendered with the shared `templates/management/_alerts.html.twig` partial, which expects a severity-grouped `alerts` array and a translated `title`.

## Contributing dashboard shortcuts from other bundles

The `/management` dashboard shows a grid of quick-action tiles (e.g. clearing a cache, toggling maintenance mode) contributed by any bundle.

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
                'role' => 'ROLE_SUPER_ADMIN',
                'category' => ShortcutProviderInterface::CATEGORY_MAINTENANCE,
            ],
        ];
    }
}
```

Make sure your bundle's `services.yaml` includes the `Management/` folder in its `src/` resource so the class is registered.

**Unlike menus/links, shortcuts trigger an action, not just navigation.** `route` must accept a `POST` request and validate its own CSRF token (`csrf_token(route)` is the token id used by the shared template) — see `ConfigShortcutController::clearCache()` for a one-shot reference implementation that clears the config cache.

**`active`:** reflects an on/off state (e.g. a toggled maintenance mode) — one-shot actions with no on/off state can always return `false`. See `MaintenanceShortcutController::toggle()` for a toggle reference implementation flipping the `site-maintenance` config used by `MaintenanceListener`, with `ConfigShortcutProvider::getShortcuts()` reading that same config to decide `active` and pick the right label ("Enable"/"Disable"). It carries no styling of its own — every tile looks the same regardless of state, so a tile never reads as "currently pressed".

**`role`:** optional — omit it for a shortcut with no access restriction of its own, set it (e.g. `'ROLE_SUPER_ADMIN'`) to hide the tile from users lacking it.

**`category`:** optional too — one of `ShortcutProviderInterface`'s `CATEGORY_EXPORT`/`CATEGORY_MAINTENANCE`/`CATEGORY_SITE` constants, or a custom `['label' => string, 'translation_domain' => string]` pair. Shortcuts sharing the same category (across bundles) are ordered next to each other in the grid — e.g. every export-related shortcut ends up adjacent — though the grid itself stays a single flat panel with no heading per category. Omit it to fall into the generic "Other" category.

**`method`:** optional, `'POST'` by default. Set it to `'GET'` for the rare tile that opens a page instead of acting — it is then rendered as a plain link, with no form and no CSRF token, and its route must be a regular `GET` page. See `ConfigPruneController::index()`, the "Obsolete configs" listing, for the reference implementation. Anything that changes state stays `POST`.

**Rendering:** shortcuts are merged across every provider and ordered by category then by label by `ShortcutBuilder::getShortcuts()`, then rendered with the shared `templates/management/_shortcuts.html.twig` partial as one flat grid, each tile its own small `<form method="post">` (or an `<a>` for a `GET` one).

## Contributing essential actions from other bundles

The `/management` dashboard shows an "Essential actions" checklist — not a one-time onboarding wizard, but a permanent quick-access entry point to the handful of settings every site needs, always linking straight to the relevant Config screen so a value can be reviewed or redone at any time.

Satellite bundles contribute their own actions by implementing `EssentialActionProviderInterface` — no manual service tagging needed, `TaggedInterfacePass` auto-detects any class implementing it, same mechanism as `MenuProviderInterface` above:

```php
namespace c975L\MyBundle\Management;

use c975L\ConfigBundle\Management\EssentialActionProviderInterface;

class MyEssentialActionProvider implements EssentialActionProviderInterface
{
    public function getEssentialActions(): array
    {
        return [
            [
                'slug' => 'my-action',
                'label' => 'label.my_essential_action',
                'description' => 'description.my_essential_action',
                'translation_domain' => 'my_bundle',
                'url' => '/management/my-entity',
                'isDone' => $this->isConfigured(),
                'order' => 50,
            ],
        ];
    }
}
```

Make sure your bundle's `services.yaml` includes the `Management/` folder in its `src/` resource so the class is registered.

`isDone` only drives the status icon (a checkmark once true) — the link itself is always shown, even once done. `order` decides the checklist's display order across every provider (low to high), unlike menus/alerts which sort alphabetically. `EssentialActionBuilder::getProgress()` (`{done, total}`) drives the panel's "X/Y configured" subtitle.

## Contributing guided projects from other bundles

The `/management` dashboard shows a "Guided projects" button next to the guided tour. Where the tour *shows* the back office, a project puts the user to work in it: a real task to carry out — create a page, add a block to it, put it in a menu — with a panel following them from screen to screen.

`ConfigGuidedProjectProvider` ships this bundle's own three, opening the order sequence the satellite bundles continue (SiteBundle picks up at 50, UiBundle at 90): **"Régler la configuration du site"** (find a setting, change it, and see what the dashboard makes of it), **"Lancer un bilan de santé"** (run it, read what is reported, know where to start) and **"Mettre le site en maintenance"** (rehearse the switch on a quiet day rather than discover it on the day it is needed).

A project is a **replayable exercise**, not a wizard to get through once. Nothing is derived from the site's own data, so a project is still worth following on a site already full of pages, and still worth replaying once done. Consequently it carries no `isDone`: nothing is ever detected server-side, the user says when a step is done. Whatever they create along the way stays on the site — deleting the practice page is their call.

Satellite bundles contribute their own projects by implementing `GuidedProjectProviderInterface` — no manual service tagging needed, `TaggedInterfacePass` auto-detects any class implementing it, same mechanism as `MenuProviderInterface` above:

```php
namespace c975L\MyBundle\Management;

use c975L\ConfigBundle\Management\GuidedProjectProviderInterface;

class MyGuidedProjectProvider implements GuidedProjectProviderInterface
{
    public function getGuidedProjects(): array
    {
        return [
            [
                'slug' => 'creer-page',
                'label' => 'label.guided_project_creer_page',
                'description' => 'description.guided_project_creer_page',
                'translation_domain' => 'my_bundle',
                'order' => 10,
                'steps' => [
                    ['label' => 'label.step_open_pages', 'url' => '/management/page'],
                    ['label' => 'label.step_click_new', 'description' => 'description.step_click_new', 'highlight' => '.action-new'],
                    ['label' => 'label.step_done'],
                ],
            ],
        ];
    }
}
```

Make sure your bundle's `services.yaml` includes the `Management/` folder in its `src/` resource so the class is registered.

**`order`** decides the display order across every provider (low to high) — a deliberate sequence, the one the user is meant to follow, unlike menus/alerts which sort alphabetically. **`role`** is optional: a project needing a role the current user lacks is dropped, the screens it walks through being out of their reach anyway. **`slug`** must be unique across every bundle contributing projects.

**Steps** set either `url` or `highlight`, never both:

- **`url`** sends the user to another screen. The panel stores the next step before leaving, and picks the parcours back up there once the page has loaded — that store-then-navigate is the whole cross-page mechanism, there is no arrival to detect.
- **`highlight`** is a CSS selector pointing at what to look at on the screen already open. A selector matching nothing — EasyAdmin renamed a class on an upgrade, the user reached the step from elsewhere — costs the highlight and nothing else: the step still reads and the parcours still runs.

**Rendering:** the list lives in `templates/management/_guided_projects.html.twig`, but the panel driving a project is `assets/js/guided-project.js`, mounted on *every* admin page through EasyAdmin's own `Assets::addHtmlContentToBody()` (see `GuidedProjectMountBuilder`) — a project spans several screens, so the panel has to survive each page load, and this reaches all of them without overriding EasyAdmin's layout. It fetches the steps from `management_guided_project_steps` only while a parcours is running, so the mount element costs no request in normal use.

**Progress is stored in the browser**, in `localStorage`, never in the database — a replayable exercise isn't a record worth a table. It is scoped per user (see `GuidedProjectKeyGenerator`) so two admins sharing one browser profile don't share one parcours, through an HMAC of the user identifier rather than the identifier itself: a `localStorage` key outlives the session, and that identifier is usually an email. The dashboard says as much to the user — progress won't follow them to another computer.

## Contributing dashboard widgets from other bundles

Any bundle can render an arbitrary block on the `/management` dashboard (e.g. UiBundle's Donovan card) by implementing `DashboardWidgetProviderInterface` — no manual service tagging needed, `TaggedInterfacePass` auto-detects any class implementing it, same mechanism as `MenuProviderInterface` above:

```php
namespace c975L\MyBundle\Management;

use c975L\ConfigBundle\Management\DashboardWidgetProviderInterface;

class MyDashboardWidgetProvider implements DashboardWidgetProviderInterface
{
    public function getDashboardWidgets(): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        return [
            ['template' => '@MyBundle/management/_my_widget.html.twig', 'context' => ['foo' => 'bar']],
        ];
    }
}
```

Make sure your bundle's `services.yaml` includes the `Management/` folder in its `src/` resource so the class is registered.

The dashboard template only loops and includes each widget's own `template` with its own `context` — it never contains business logic about what a widget is. Return `[]` when there's nothing to show (e.g. an unconfigured feature) so it stays entirely absent rather than showing a disabled placeholder.

## Maintenance mode

Setting the `site-maintenance` config to `true` — from the config list, or from the dashboard's own toggle tile — closes the site to its visitors: `MaintenanceListener` answers every public request with the `@c975LConfig/maintenance/index.html.twig` page. `/management` and `/login` stay reachable so an admin can always get back in and lift it, as does anyone already authenticated with the `site-role-admin` role, or holding the `site-maintenance-hash` token (`?t=…`, which opens a 6-hour session).

The dashboard toggle generates that token when it closes the site and the entry is still empty — an empty one grants nobody anything, so a site closed without it could only be visited by logging into the back-office. The dashboard then shows the ready-made url alongside the maintenance alert: hand it over to a client signing off on the work or to whoever has to see the site as its visitors will, without giving them an account.

That page is served with **HTTP 503** and a `Retry-After` header, which is what search engines expect from a temporary outage — a `200` would get the maintenance page indexed in place of the real ones, a `404`/`410` would drop them from the index, and a `noindex` on a 503 risks the same. `Retry-After` is deliberately short (one hour, whatever the real length of the outage): it's only a hint, so a crawler coming back too early just meets another 503 and applies its own backoff, whereas too long a delay keeps it away after the site is back up. A `Cache-Control: no-store` keeps any proxy or CDN from serving the maintenance page once it's over.

`robots.txt` and the sitemaps are static files under `public/`, served by the web server without going through the listener — they keep answering `200` during maintenance, which matters: a `robots.txt` answering 503 stops crawling on the whole site.

**Don't leave it on for more than a day or two.** Past that, search engines stop reading the 503 as temporary and start dropping the pages from their index. `MaintenanceAlertProvider` puts that on the dashboard: an `info` alert while the site is closed, turning to `danger` past two days, both dated from the moment the mode was switched on. For a closure that has to last, publishing a real home page answering `200` ("closed until…", contact details) keeps the site indexed where maintenance mode wouldn't.

## Health check

`/management/health-check` gives a per-page technical health snapshot of the site — Lighthouse scores, security headers, W3C markup validation, WCAG accessibility issues (whichever `HealthCheckProviderInterface` implementations are installed; `c975l/site-bundle` contributes eleven, see its own README) — without needing Node/Lighthouse-CLI or any other JS tooling: everything runs server-side over plain HTTP calls.

**Reading the table**: the page lists one row per url *and* per kind, its rows grouped by url. The row opening each group carries that page's name and is tinted with the page's own verdict — the worst status among the rows currently listed for it, so a page reads as ok/warning/error at a glance without adding up its rows' own status pills. The verdict follows the table's filters: filtering on a single kind repaints each group with what that kind alone found.

**Refreshing results**: `php bin/console c975l:health-check:run` runs every registered provider and appends their results (never triggers a live check from a page load). It accepts a repeatable `--kind=` option to run only specific providers — e.g. `--kind=wave` on its own, less frequent cron entry for a paid/credit-based provider, separately from the free ones:

```bash
php bin/console c975l:health-check:run                                    # every provider
php bin/console c975l:health-check:run --kind=pagespeed --kind=w3c        # only these two
```

There's also a **"Run health check now"** button directly on the page. It doesn't run the check in your request: it dispatches one `RunCommandMessage` per registered kind (`c975l:health-check:run --kind=…`, the very command above) and returns immediately. A single provider can hold thousands of urls — a gallery declares one per photo — and a run that times out mid-way persists nothing at all.

This needs `RunCommandMessage` routed to an asynchronous transport, and a worker consuming it:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        routing:
            Symfony\Component\Console\Messenger\RunCommandMessage: async
```

```bash
php bin/console messenger:consume async scheduler_site
```

If it isn't routed, Messenger handles the message synchronously — the button then behaves as it did before, blocking the request. Results appear on the page as each job completes, and `HealthCheckAlertProvider` raises what needs attention (errors, then warnings, with the date of the last run) on the dashboard and on this page, which is what tells you a queued run is done.

**History, not just a snapshot**: every run appends new `HealthCheckResult` rows rather than overwriting — the page itself only shows the latest one per (url, kind), but the full history feeds a trend chart (ok/warning/error counts over time, via `symfony/ux-chartjs` — a regular Composer dependency, Flex wires it up automatically) and an **Export (CSV)** button producing a dated snapshot, useful as an audit-trail artefact (e.g. accessibility declarations). No pruning is done automatically — weekly/monthly runs across a site's pages stay a modest row count for years; add your own cleanup if that assumption stops holding for a particular site.

The table itself can be sorted (click a column) and filtered (free-text search, status, kind) client-side — hand-rolled (`assets/js/health-check-table.js`), no DataTables/jQuery dependency.

The page also shows the same dashboard-wide alerts as `/management` (e.g. a health check provider's own missing API key, flagged via its config's `severity`), so anything blocking a full check is visible without leaving the page.

## Backup

`c975l:config:backup` dumps the database table by table and archives the site's own files, replacing the shell scripts this used to need. It lives here rather than in `c975l/site-bundle`, where it started: backing up is what every install needs whichever satellite bundles it happens to have, and none of ShopBundle, GalleryBundle, BookBundle or CrowdfundingBundle depends on SiteBundle — a shop-only or gallery-only install used to have no backup at all. The former name `c975l:site:backup` is kept as an alias, so schedulers and crontabs already deployed keep working.

```bash
php bin/console c975l:config:backup                  # dump + archive, silent unless something fails
php bin/console c975l:config:backup --report         # same, plus a summary email of that run
php bin/console c975l:config:backup:digest           # no backup: emails a digest of the last 7 days
php bin/console c975l:config:backup:digest --days=30 # any other window
php bin/console c975l:config:backup:digest --dry-run # print the digest, send nothing
```

Everything is configured through the `backup` config group (all `restricted`, see [Restricting configs to ROLE_SUPER_ADMIN](#restricting-configs-to-role_super_admin)): `site-backup-database`, `site-backup-db-host`/`-db-user`/`-db-password`, `site-backup-mailto`, `site-backup-full-interval-months`, `site-backup-retention-days` and `site-backup-max-age-hours`. The emails also read `email-from` — declared here rather than in SiteBundle, an install having backups to report whichever satellite bundles it happens to have — and fall back to `site-backup-mailto` when it's empty, rather than failing to send at all.

**What is archived**: the database, one `.sql` file per table (so a single table can be restored on its own) compressed into one archive; then `public/` and `private/` — the latter being where a bundle keeps what the document root must never expose, ShopBundle's invoices being the typical case. Each root gets its own archive, both in the same mode on the same run, so a restore never has to pair a complete archive with a partial one. Files go complete on the first run and every `site-backup-full-interval-months` calendar months, modified-since-last-run in between. `config/backup_exclude.cnf`, if present, adds your own `tar --exclude-from` patterns.

**Verifying rather than assuming**: every archive is read back and checked (`bzip2 --test`) before being counted, its size recorded, and the number of tables actually dumped compared against `INFORMATION_SCHEMA`. A table is reported only once its dump exists, with its size — a table listed in the report used to prove nothing about it having been saved. Anything discarded as empty is named in the report instead of vanishing silently.

**Retention on the server**: each run purges the dated `var/backup/YYYY/YYYY-MM/YYYY-MM-DD` folders older than `site-backup-retention-days` (15 by default). Whoever copies the archives offsite should keep a *longer* window than this one — otherwise the next copy downloads again what it has just purged locally. The point is that production always holds a rolling set of restorable archives: deleting them as soon as they were copied off left a gap where the only surviving copy was the offsite one.

### Seeing that it actually ran

Every run — not only the one carrying `--report` — records a `HealthCheckResult` row of kind `backup`, so it shows up in the site-wide section of [the Health check page](#health-check), in its trend chart and in the CSV export, with no extra screen to maintain. Those same rows are what [the weekly digest](#the-weekly-digest) reads back. The row's summary carries the numbers a "backup ok" message never gives: tables dumped, archive sizes, duration.

`BackupAlertProvider` then reads that row live at every dashboard load and raises, for `ROLE_SUPER_ADMIN` only (its alerts carry `'role' => 'ROLE_SUPER_ADMIN'`, every config behind them being `restricted` too — an admin who can't read the backup settings can't act on them either):

| Situation | Severity |
| --- | --- |
| No backup recorded for longer than `site-backup-max-age-hours` (30 by default) | danger |
| The last run failed | danger |
| The last run has warnings, or its SQL archive lost more than half its size since the previous run | warning |
| Backup configured but never run | warning |

The first line is the one no report email can ever cover: an email only exists when the command ran far enough to send one, so a dead scheduler consumer, a crontab lost on a server move or a PHP fatal mid-dump all produce the same signal — nothing at all — and a missing email is precisely what nobody notices. Staleness is checked whatever the last run's own status was, a backup that succeeded a fortnight ago being a worse problem than one that failed this morning.

The size-drop check compares against the previous run rather than a fixed threshold: what a healthy archive weighs is entirely site-specific, and a dump holding half of last week's is the failure mode no per-table error ever reports — every table having dumped "successfully" into a truncated result.

None of this proves a *restore* works. Only restoring does, and that stays a manual exercise worth doing on the offsite copy once in a while.

### The weekly digest

The dashboard covers the site you happen to be looking at. `c975l:config:backup:digest` covers the week you didn't look at it, on every site at once: scheduled weekly, it mails what the last 7 days of `backup` rows say, one mail per site, its verdict in the subject line so a mailbox full of them is read without opening any:

```text
[OK] Backups over the last 7 days - example.com

Site example.com - last 7 days (since 22/07/2026 03:07)

28 run(s): 28 ok, 0 warning(s), 0 error(s)
Last run on 29/07/2026 06:07: 42 tables · SQL 18.4 MB · Files: partial 12 (3.1 MB) · 1 min 44 s
SQL archive over the period: 17.9 MB -> 18.4 MB
Retention (15 days): 15 run(s) kept on the server, oldest 2026-07-14
```

It **runs no backup of its own** — it reads the rows back — and that's the whole point of it being a separate command rather than a bigger `--report`: `--report` rides on a backup run and only exists if that run reaches its last line, so a dead consumer, a lost crontab or a fatal mid-dump send nothing at all. The digest goes out either way, and when nothing ran it says exactly that (`[ALERT] No backup over the last 7 days`) and exits non-zero, so the scheduler's own logs carry the verdict too. On an install where `site-backup-database` is empty nothing is sent: a site that deliberately doesn't back up from here shouldn't get a weekly false alarm.

Beyond the counts, it reports what a single run can't see:

| Reported | Why a per-run report misses it |
| --- | --- |
| The longest stretch without a backup, once past `site-backup-max-age-hours` | A scheduler that stopped on Wednesday and restarted Friday leaves every row saying "ok" |
| The SQL archive at both ends of the window | The shrink warning only compares a run against the one before it, so a slow drift never trips it |
| Errors and warnings, deduplicated with their count | The same table failing every 6 hours is one problem, and listing it 28 times is how a report stops being read |

The stretch *before* the first run of the window is deliberately not counted: a site whose backups started three days ago hasn't skipped the four days before that.

### Scheduling it

`c975l:config:backup` is a plain command; schedule it with [Symfony Scheduler](https://symfony.com/doc/current/scheduler.html) alongside `c975l:sitemaps:create`/`c975l:health-check:run`, or from a crontab. `c975l/site-bundle` ships a ready-made `MaintenanceSchedule` in its scaffold; on an install without it:

```php
->add(RecurringMessage::cron('7 */6 * * *', new RunCommandMessage('c975l:config:backup')))
->add(RecurringMessage::cron('7 3 * * 1', new RunCommandMessage('c975l:config:backup:digest')))
```

Keep `site-backup-max-age-hours` comfortably above the interval you pick, so an ordinary late run doesn't alert. The digest is scheduled on its own line rather than as `c975l:config:backup --report` on the Monday run, so the week's summary doesn't depend on that particular run getting through.

## Contributing health check providers from other bundles

Any bundle can contribute a check by implementing `HealthCheckProviderInterface` — no manual service tagging needed, `TaggedInterfacePass` auto-detects any class implementing it, same mechanism as `MenuProviderInterface` above:

```php
namespace c975L\MyBundle\Management;

use c975L\ConfigBundle\Entity\HealthCheckResult;
use c975L\ConfigBundle\Management\HealthCheckProviderInterface;

class MyHealthCheckProvider implements HealthCheckProviderInterface
{
    // Stable identifier for this provider's rows (eg. "my-check") - used for --kind= filtering and stored on every HealthCheckResult
    public function getKind(): string
    {
        return 'my-check';
    }

    // One entry per checked url: ['url', 'label', 'status' => HealthCheckResult::STATUS_*, 'summary', 'details' => array, 'editUrl']
    public function runChecks(): array
    {
        return [
            [
                'url' => 'https://example.com/pages/home/',
                'label' => 'Home',
                'status' => HealthCheckResult::STATUS_OK,
                'summary' => 'Everything checks out',
                'details' => null,
                'editUrl' => '/management/my-entity/1/edit',
            ],
        ];
    }
}
```

Make sure your bundle's `services.yaml` includes the `Management/` folder in its `src/` resource so the class is registered.

**Never call a slow/paid API from a controller** — `runChecks()` is only ever invoked from `c975l:health-check:run` (via `HealthCheckRunner`), so a page load never blocks on it. If your check needs an API key, read it via `ConfigServiceInterface` like any other config (see [Defining config entries for your bundle](#defining-config-entries-for-your-bundle) above) and degrade gracefully without one — either skip entirely (return `[]`) or, if the check is otherwise expected to be configured (see `c975l/site-bundle`'s own PageSpeed/WAVE providers), return a single explanatory row instead of one per page.

`editUrl` is optional (omit or `null` for a row with no admin CRUD counterpart, e.g. a site-wide check) — the admin edit screen for the entity behind that row (e.g. SiteBundle's Page edit screen), shown on the Health check table as a pencil link next to the tested url.

## Contributing health check advice from other bundles

Any bundle can attach actionable advice under a Health check table row (e.g. "this page is missing an H1" linking to its edit screen) by implementing `HealthCheckAdviceProviderInterface` — no manual service tagging needed, `TaggedInterfacePass` auto-detects any class implementing it, same mechanism as `MenuProviderInterface` above:

```php
namespace c975L\MyBundle\Management;

use c975L\ConfigBundle\Entity\HealthCheckResult;
use c975L\ConfigBundle\Management\HealthCheckAdviceBuilder;
use c975L\ConfigBundle\Management\HealthCheckAdviceProviderInterface;

class MyHealthCheckAdviceProvider implements HealthCheckAdviceProviderInterface
{
    // Keyed per result, via HealthCheckAdviceBuilder::key() (only the results this provider actually has something to say about) - $results is the same HealthCheckResult[] the current screen renders (dashboard "Health check" page or a CRUD's own scoped tab)
    public function buildAdvice(array $results): array
    {
        $advice = [];

        foreach ($results as $result) {
            if ('my-check' !== $result->getKind()) {
                continue;
            }

            $advice[HealthCheckAdviceBuilder::key($result)] = [
                [
                    'text' => '3 images are missing an alt text',
                    'url' => '/management/my-entity/1/edit',
                    // Optional - the individual offenders behind that line, rendered as a collapsed list under it
                    'items' => [
                        ['text' => 'banner.jpg', 'url' => '/management/my-entity/1/edit#block-4', 'label' => 'Edit the block'],
                    ],
                ],
            ];
        }

        return $advice;
    }
}
```

Make sure your bundle's `services.yaml` includes the `Management/` folder in its `src/` resource so the class is registered.

Always build the key with `HealthCheckAdviceBuilder::key()` rather than concatenating it yourself — the table looks each row's advice up under that exact key, and a mismatch shows no advice at all rather than raising an error. Keying by `kind` alone isn't enough: the Health check page lists one row per url *and* per kind.

Each line needs a `text`, and may carry a `url` (rendered as a link next to the text) and an `items` list. `items` is for a line that summarizes several offenders ("3 images are missing an alt text") — each entry needs its own `text`, and may carry a `url` plus the `label` for that link (falling back to a pencil icon alone), so a dozen offenders stay collapsed instead of pushing the following rows off screen.

`HealthCheckAdviceBuilder::build()` merges every registered provider's advice; two providers with something to say about the same result have their lines appended, neither overwrites the other. It's shared by the dashboard "Health check" page and any CRUD's own "Health check" tab (both render through the same `health_check/_table.html.twig`), so advice reads identically everywhere.

## Dev profile — automating what the dev toolbar shows

`php bin/console c975l:dev-profile:run` renders every page your bundles declare **through the local kernel**, with the profiler on, and prints the list of what the Symfony dev toolbar would flag on each: n+1 queries, deprecations, missing translations, external HTTP calls made while rendering, and so on. It's the automation of "open every page in dev and look at the toolbar".

Everything about it is dev-only: the command, the runner, the collector and every path provider are marked `#[When('dev')]`, so none of those services even exist in prod (where the `profiler` service doesn't either). Nothing is persisted — no entity, no dashboard page, no trend chart. The output *is* the deliverable: a list to fix.

It reads its numbers from the profiler, so `symfony/profiler-pack` has to be installed (it is by default in a `symfony/skeleton` dev environment); without it the command says so on every page rather than reporting them clean.

**Why it doesn't reuse the health check**: [Health check](#health-check) fetches the *live* site over HTTP at `site-url`, which points at production even when run from a dev machine — exactly what you want to judge a deployed site, and exactly what you don't want when profiling the code you're editing. This command never builds a URL at all: providers declare local paths (`/`, `/pages/contact`), each is handed straight to the kernel like a functional test does, so what's measured is your local code against your local database.

```bash
php bin/console c975l:dev-profile:run                        # every declared page, problems only
php bin/console c975l:dev-profile:run --path=/pages/contact  # one page, repeatable
php bin/console c975l:dev-profile:run --all                  # also list the clean pages, with their numbers
```

Sample output:

```text
/ — Accueil
  HTTP 200 · 47 requêtes (31.2 ms) · 68 templates (44.1 ms) · 2 dépréciations · cache 12/40 · 240 ms · 14.2 Mo
  ERREUR Doctrine       31 requêtes identiques répétées (n+1), dont 32 fois : SELECT t0.id FROM site_block t0 WHERE t0.page_id = ?
  ALERTE Dépréciations  2 dépréciation(s) : Since symfony/framework-bundle 7.3: ...
```

The command exits non-zero as soon as one page has an **error**-level offence, so it can gate a pre-push hook the same way `c975l:site:smoke-test` gates a deployment — or your app's `composer test`, as the last entry so it runs once the test suite is green:

```json
"scripts": {
    "test": [
        "@php bin/console cache:warmup --env=test",
        "phpunit",
        "@php bin/console cache:pool:clear cache.app --env=dev",
        "@php bin/console c975l:dev-profile:run --env=dev"
    ]
}
```

`--env=dev` is not optional there: the command is `#[When('dev')]`, so it doesn't exist in the `test` environment — and the dev database is the one holding the pages you actually want profiled, where a test database would only hold fixtures.

### What's measured, and what counts as an offence

| Area | Read from | Reported when |
| --- | --- | --- |
| Doctrine | `db` collector | more than `MAX_QUERIES` (30) queries — error past 60 — or more than `MAX_DUPLICATE_QUERIES` (2) identical queries repeated, error past 9. The worst offender's SQL is quoted |
| Deprecations | `logger` collector | any deprecation (warning) — the cheapest way to see what a Symfony major bump will require |
| Logs | `logger` collector | any error-level log written while rendering |
| Translations | `translation` collector | any key with no translation (error, the keys are listed) or served from the fallback locale (warning) |
| HttpClient | `http_client` collector | **any** call to an external API while rendering (error): that belongs in a command writing to the database, or at worst behind a cache |
| Twig | `twig` collector | more than `MAX_TEMPLATES` (150) templates rendered — deliberately high, a block-based theme legitimately renders dozens of small templates per page |
| Response | status code | a non-200: a redirect is a warning (usually the firewall, nothing was profiled), anything else an error |

Timings, memory and cache hits/misses are printed as context but are **never** an offence: `APP_DEBUG`, no opcache and no preloading make a dev machine's milliseconds say nothing about production, and the misses only say how warm the pools happened to be when the run started — whereas the counts above are the same numbers production would produce. The thresholds are constants on `DevProfileAnalyzer` — a site needing different ones overrides that service.

**Clear the app cache pool first** (`php bin/console cache:pool:clear cache.app`). Anything a cached block hides — a missing translation inside it, a Twig syntax error, the queries it would run — stays hidden as long as its cache entry is there, and the run reports the page as clean. It's the single biggest way to get a falsely reassuring report.

Two deliberate behaviours worth knowing: the first declared path is profiled **twice** and its first result dropped (the kernel stays booted from one path to the next, so that one would otherwise carry every warm-up cost — config read from the database, templates compiled, cache pools filled — that none of the following ones show); and `services_resetter` is called after each path, exactly as a messenger worker does between two messages, without which every page would be reported carrying the previous ones' numbers.

## Contributing dev profile paths from other bundles

Any bundle can declare the pages it owns by implementing `DevProfilePathProviderInterface` — no manual service tagging needed, `TaggedInterfacePass` auto-detects any class implementing it, same mechanism as `MenuProviderInterface` above. Mark it `#[When('dev')]`, so it never reaches a production container:

```php
namespace App\Management;

use c975L\ConfigBundle\Management\DevProfilePathProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\When;

#[When('dev')]
class MyDevProfilePathProvider implements DevProfilePathProviderInterface
{
    public function __construct(
        private readonly MyRepository $myRepository,
    ) {
    }

    // One entry per path to profile: ['path' => local absolute path, 'label' => ?string]
    public function getPaths(): array
    {
        $paths = [];
        foreach ($this->myRepository->findAllPublished() as $item) {
            $paths[] = ['path' => '/shop/' . $item->getSlug(), 'label' => $item->getName()];
        }

        return $paths;
    }
}
```

Make sure your bundle's `services.yaml` includes the `Management/` folder in its `src/` resource so the class is registered.

**Local paths only** — `/pages/contact`, never `https://example.com/pages/contact`: the path is handed to the kernel, no HTTP request and no host involved. Two bundles declaring the same path is fine, it's profiled once. `c975l/site-bundle` already contributes `PageDevProfilePathProvider` (every published `Page`), so an app installing it has nothing to write for its own pages.

## Contributing procedures for the dashboard AI assistant

`ProcedureProviderInterface` lets a satellite bundle document its own admin workflows (e.g. "how do I create a page") for an AI assistant built into the consuming app's dashboard — ConfigBundle only collects and merges these entries, it doesn't ship the assistant itself.

Satellite bundles contribute procedures by implementing `ProcedureProviderInterface` — no manual service tagging needed, `TaggedInterfacePass` auto-detects any class implementing it, same mechanism as `MenuProviderInterface` above:

```php
namespace c975L\MyBundle\Management;

use c975L\ConfigBundle\Management\ProcedureJsonReader;
use c975L\ConfigBundle\Management\ProcedureProviderInterface;

class MyProcedureProvider implements ProcedureProviderInterface
{
    public function getProcedures(): array
    {
        return ProcedureJsonReader::read(\dirname(__DIR__, 2) . '/config/procedures.json');
    }
}
```

Make sure your bundle's `services.yaml` includes the `Management/` folder in its `src/` resource so the class is registered.

Declare your bundle's entries in a `config/procedures.json` file, `slug` unique across every bundle:

```json
[
    {
        "slug": "creer-page",
        "title": {
            "en": "Create a page",
            "fr": "Créer une page"
        },
        "body": {
            "en": "Go to Pages, click Add, fill in the title...",
            "fr": "Allez dans Pages, cliquez sur Ajouter, renseignez le titre..."
        }
    }
]
```

**Merging:** `ProcedureBuilder::getAll()` merges every provider's procedures, sorted by `slug` for a stable, deterministic order regardless of service registration order.

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
