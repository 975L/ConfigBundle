<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Service\Export;

enum ExportFormat: string
{
    case Sql = 'sql';
    case Csv = 'csv';
    case Json = 'json';
}
