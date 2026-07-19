# UPGRADE

## Unreleased

`ConfigCrudController`'s constructor gained two arguments, `ConfigRepository` and `EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator` (both autowired, nothing to configure) — only relevant if your app extends or manually instantiates this controller.

The Config list's EasyAdmin index is no longer a single flat table listing every group's entries together - it now shows a "pick a group" screen first (one row per distinct group, with its entry count), then the familiar grid filtered to that group (`?group=...`). Nothing to migrate: existing config rows work as-is, this only changes the admin UX. The EasyAdmin "group" filter is removed from the grid (redundant with the new screen); if you relied on it (e.g. a saved/bookmarked filtered URL using EasyAdmin's own `filters[group][...]` query format), switch to the plain `?group=<slug>` query param instead. If you link directly to the CRUD's index (bypassing the dashboard menu), append `?group=<slug>` to land straight on a given group's grid instead of the group picker.

**`ThemeCrudController` and its "Theme" dashboard menu entry are removed.** It existed to keep the `theme` group's CSS-variable entries out of the general Config list before that list could be filtered by group - now that Config's own "pick a group" screen does exactly that, the dedicated page is redundant. Theme entries (colors, fonts, light/dark mode) are edited from **Config → theme** like any other group. Concretely:
- `/management/theme` (and any bookmarked link to it) is gone - link to Config's `theme` group instead (`?group=theme` on the Config CRUD's index route).
- **Permission changed**: theme entries were viewable/preset-applicable at `site-role-editor` and hand-editable at `ROLE_SUPER_ADMIN`; they're now gated like every other Config entry, at `site-role-admin` for both viewing and editing. A site relying on an editor-level role to manage theme colors/fonts must grant it `site-role-admin` instead (or wait for the Presets UI's eventual rework, see below).
- The "Presets" admin action (apply a vetted preset in one click) and its `applyPreset` route are removed - it was already hidden pending a rework (`// $actions->add(Crud::PAGE_INDEX, $presetsGroup);` was commented out) and had no working entry point. `ThemePresetProviderInterface`/`ThemePresetRegistry` still exist for any bundle-owned feature reading presets directly (e.g. SiteBundle's `?preset=<slug>` per-page preview) - nothing to change there.
- `label.theme` (the removed page's title) is unused but still translated - harmless, not removed.
- If your app extended `ThemeCrudController` or linked to it directly (custom dashboard menu override, etc.), update accordingly - there is no replacement class, `ConfigCrudController` handles every group generically.

## > v5.4

- Added `isRestricted` column on `Config`: run `php bin/console doctrine:migrations:diff && php bin/console doctrine:migrations:migrate`
- A config flagged `"restricted": true` in a bundle's `configs.json` is now hidden entirely (index/detail/edit/export) from any user without `ROLE_SUPER_ADMIN` — use it for secrets shared across the install (DB backup credentials, payment API keys...) that a regular site admin must never see, even encrypted

## v4.x > v5.x

Made use of database to store config parameters. Needs a databse migration.