<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;

// Exports every site_config row - single source of truth for ConfigCrudController's exportCsv/exportJson/exportContent (the standalone "Sync" export) and for the "export sync all" dashboard shortcut (see SyncAllExporter, ExportProviderInterface)
class ConfigExportProvider implements ExportProviderInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Security $security,
    ) {
    }

    public function getKind(): string
    {
        return ConfigImportProvider::KIND;
    }

    public function exportAll(): array
    {
        $items = array_map(static fn (array $row): array => [
            'slug' => $row['slug'],
            'label' => $row['label'],
            'isSensitive' => (bool) $row['is_sensitive'],
            'isRestricted' => (bool) $row['is_restricted'],
            'value' => $row['value'],
            'kind' => $row['kind'],
            'group' => $row['group'],
            'description' => $row['description'],
            'severity' => $row['severity'],
        ], $this->fetchRows());

        return ['items' => $items, 'files' => []];
    }

    // Same rows used by exportCsv/exportJson (raw table dump) - restricted configs (backup DB credentials, payment API keys...) are excluded below ROLE_SUPER_ADMIN, same restriction as the CRUD itself; is_restricted is nullable, legacy rows must NOT be treated as restricted
    public function fetchRows(): array
    {
        $sql = 'SELECT `label`, `slug`, `is_sensitive`, `is_restricted`, `value`, `kind`, `group`, `description`, `severity`, `creation`, `modification` FROM `site_config`';
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            $sql .= ' WHERE `is_restricted` IS NULL OR `is_restricted` = 0';
        }
        $sql .= ' ORDER BY `slug`';

        return $this->connection->fetchAllAssociative($sql);
    }
}
