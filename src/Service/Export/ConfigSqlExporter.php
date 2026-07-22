<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Service\Export;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds the SQL export of site_config, shared by ConfigCrudController::exportSql and ConfigShortcutController.
 * Restricted rows (backup DB credentials, payment API keys...) are excluded below ROLE_SUPER_ADMIN, same restriction as ConfigCrudController::fetchExportRows().
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2026 975L <contact@975l.com>
 */
class ConfigSqlExporter
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TableExporter $tableExporter,
        private readonly Security $security,
    ) {
    }

    // Non-sensitive: INSERT ... ON DUPLICATE KEY UPDATE (syncs label/value/kind/group/description/severity); sensitive: INSERT IGNORE INTO (creates if missing, preserves production values)
    public function export(): Response
    {
        $sql = 'SELECT `label`, `slug`, `is_sensitive`, `is_restricted`, `value`, `kind`, `group`, `description`, `severity`, `creation`, `modification` '
            . 'FROM `site_config`';
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            $sql .= ' WHERE `is_restricted` IS NULL OR `is_restricted` = 0';
        }
        $sql .= ' ORDER BY `slug`';

        return $this->tableExporter->export(ExportFormat::Sql, 'site_config', $this->connection->fetchAllAssociative($sql), [
            'primary_key' => 'slug',
            'exclude_from_update' => ['creation'],
            'insert_ignore_when' => fn (array $row): bool => (bool) $row['is_sensitive'],
        ]);
    }
}
