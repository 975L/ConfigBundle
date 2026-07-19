<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Service\Export;

use c975L\ConfigBundle\Service\Export\Encoder\SqlEncoder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Encoder\CsvEncoder;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Serializer;

// Exports an array of associative rows (e.g. Connection::fetchAllAssociative()) as a downloadable SQL/CSV/JSON file, so CRUD controllers only need to wire an "Export" action per format
class TableExporter
{
    private const CONTENT_TYPES = [
        ExportFormat::Sql->value => 'application/octet-stream',
        ExportFormat::Csv->value => 'text/csv; charset=utf-8',
        ExportFormat::Json->value => 'application/json',
    ];

    private readonly Serializer $serializer;

    public function __construct(SqlEncoder $sqlEncoder)
    {
        $this->serializer = new Serializer([], [$sqlEncoder, new CsvEncoder(), new JsonEncoder()]);
    }

    public function export(ExportFormat $format, string $tableName, array $rows, array $context = []): Response
    {
        $content = $this->serializer->encode($rows, $format->value, $context + ['table' => $tableName]);
        $filename = sprintf('%s_%s.%s', $tableName, date('Ymd_His'), $format->value);

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => self::CONTENT_TYPES[$format->value],
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
