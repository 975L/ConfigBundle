<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Service\Export\Encoder;

use c975L\ConfigBundle\Service\Export\ExportFormat;
use Doctrine\DBAL\Connection;
use Symfony\Component\Serializer\Encoder\EncoderInterface;

// Encodes an array of associative rows (e.g. Connection::fetchAllAssociative()) as SQL INSERT
// statements. Context options:
// - table (string, required): the table name
// - primary_key (string): unique column, enables an ON DUPLICATE KEY UPDATE clause on the other columns
// - exclude_from_update (string[]): columns never rewritten by the UPDATE clause (e.g. an immutable creation date)
// - insert_ignore_when (callable(array $row): bool): when true for a row, emits INSERT IGNORE instead of the upsert
class SqlEncoder implements EncoderInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function encode(mixed $data, string $format, array $context = []): string
    {
        $table = $context['table'] ?? throw new \InvalidArgumentException('The "table" context option is required to encode SQL.');
        $primaryKey = $context['primary_key'] ?? null;
        $excludeFromUpdate = $context['exclude_from_update'] ?? [];
        $insertIgnoreWhen = $context['insert_ignore_when'] ?? null;

        $lines = [
            "-- {$table} export -- " . date('Y-m-d H:i:s'),
            'SET NAMES utf8mb4;',
            '',
        ];

        foreach ($data as $row) {
            $lines[] = $this->buildInsert($table, $row, $primaryKey, $excludeFromUpdate, $insertIgnoreWhen);
        }

        return implode("\n", $lines) . "\n";
    }

    public function supportsEncoding(string $format): bool
    {
        return ExportFormat::Sql->value === $format;
    }

    private function buildInsert(
        string $table,
        array $row,
        ?string $primaryKey,
        array $excludeFromUpdate,
        ?callable $insertIgnoreWhen,
    ): string {
        $columns = array_keys($row);
        $quotedColumns = implode(', ', array_map(fn (string $column): string => "`{$column}`", $columns));
        $values = implode(', ', array_map($this->quote(...), $row));

        if (null !== $insertIgnoreWhen && $insertIgnoreWhen($row)) {
            return "INSERT IGNORE INTO `{$table}` ({$quotedColumns}) VALUES ({$values});";
        }

        if (null === $primaryKey) {
            return "INSERT INTO `{$table}` ({$quotedColumns}) VALUES ({$values});";
        }

        $updateColumns = array_diff($columns, [$primaryKey], $excludeFromUpdate);
        $updateClause = implode(', ', array_map(
            fn (string $column): string => "`{$column}`=VALUES(`{$column}`)",
            $updateColumns
        ));

        return "INSERT INTO `{$table}` ({$quotedColumns}) VALUES ({$values})"
            . " ON DUPLICATE KEY UPDATE {$updateClause};";
    }

    private function quote(mixed $value): string
    {
        return null === $value ? 'NULL' : $this->connection->quote((string) $value);
    }
}
