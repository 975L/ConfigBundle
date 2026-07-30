<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\ByteFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ByteFormatterTest extends TestCase
{
    public static function sizes(): array
    {
        return [
            'bytes stay whole' => [512, '512 B'],
            'one decimal below 100' => [13_000_000, '12.4 MB'],
            'no decimal above 100' => [500 * 1024, '500 KB'],
            'largest unit caps at TB' => [5000 * 1024 ** 4, '5000 TB'],
            'zero' => [0, '0 B'],
        ];
    }

    #[DataProvider('sizes')]
    public function testFormat(int $bytes, string $expected): void
    {
        $this->assertSame($expected, ByteFormatter::format($bytes));
    }
}
