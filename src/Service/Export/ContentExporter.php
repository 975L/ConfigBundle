<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Service\Export;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

// Exports a selection of nested entities (eg. a Page with its Blocks) as a downloadable zip, read back by ContentImportController/ImportDispatcher. Unlike TableExporter (flat table rows, SQL/CSV/JSON), this carries nested structures that don't fit a single SQL table dump, matched back on import by each ImportProvider's own natural key (never by the exported id, which won't match between dev and prod). Real files (eg. a Block's Media) travel as actual zip entries, not base64 inside the JSON - keeps the download a manageable size and avoids holding a huge string in memory for a selection with many/large files
class ContentExporter
{
    // $items is JSON-encoded as the archive's manifest.json. $files maps each archive-relative path referenced from within $items (eg. 'files/a1b2c3.jpg') to the real file to embed at that path - empty for a kind that never carries files (eg. site_config)
    public function export(string $kind, array $items, array $files = []): BinaryFileResponse
    {
        $payload = [
            'kind' => $kind,
            'exportedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'items' => $items,
        ];
        // Throws rather than silently writing a truncated/empty manifest - json_encode() returns false (not an exception) on failure, easy to miss with a large payload
        $manifest = json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        $archivePath = tempnam(sys_get_temp_dir(), 'content_export_') . '.zip';
        $zip = new \ZipArchive();
        $zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', $manifest);
        foreach ($files as $archiveRelativePath => $diskPath) {
            $zip->addFile($diskPath, $archiveRelativePath);
        }
        $zip->close();

        $filename = sprintf('%s_%s.zip', $kind, date('Ymd_His'));
        $response = new BinaryFileResponse($archivePath, 200, ['Content-Type' => 'application/zip']);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
        $response->deleteFileAfterSend(true);

        return $response;
    }
}
