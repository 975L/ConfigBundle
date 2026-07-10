# UPGRADE

## > v5.4

- Added `isRestricted` column on `Config`: run `php bin/console doctrine:migrations:diff && php bin/console doctrine:migrations:migrate`
- A config flagged `"restricted": true` in a bundle's `configs.json` is now hidden entirely (index/detail/edit/export) from any user without `ROLE_SUPER_ADMIN` — use it for secrets shared across the install (DB backup credentials, payment API keys...) that a regular site admin must never see, even encrypted

## v4.x > v5.x

Made use of database to store config parameters. Needs a databse migration.