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
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds the SQL export of site_config, shared by ConfigCrudController::exportSql and ConfigShortcutController.
 * Always exports every row (restricted included), since both callers require ROLE_SUPER_ADMIN.
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2026 975L <contact@975l.com>
 */
class ConfigSqlExporter
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TableExporter $tableExporter,
    ) {
    }

    // Non-sensitive: INSERT ... ON DUPLICATE KEY UPDATE (syncs label/value/kind/group/description/severity); sensitive: INSERT IGNORE INTO (creates if missing, preserves production values)
    public function export(): Response
    {
        $sql = 'SELECT `label`, `slug`, `is_sensitive`, `is_restricted`, `value`, `kind`, `group`, `description`, `severity`, `creation`, `modification` '
            . 'FROM `site_config` ORDER BY `slug`';

        return $this->tableExporter->export(ExportFormat::Sql, 'site_config', $this->connection->fetchAllAssociative($sql), [
            'primary_key' => 'slug',
            'exclude_from_update' => ['creation'],
            'insert_ignore_when' => fn (array $row): bool => (bool) $row['is_sensitive'],
        ]);
    }
}
