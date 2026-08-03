<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\ArchiveFileRegistrar;
use PHPUnit\Framework\TestCase;

class ArchiveFileRegistrarTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/c975l_archive_' . bin2hex(random_bytes(4));
        mkdir($this->projectDir . '/public/media', 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->projectDir . '/public/media/*') ?: []);
        @rmdir($this->projectDir . '/public/media');
        @rmdir($this->projectDir . '/public');
        @rmdir($this->projectDir);
    }

    public function testRegisterAddsAnExistingFileToTheArchiveMap(): void
    {
        file_put_contents($this->projectDir . '/public/media/photo.jpg', 'binary');
        $files = [];

        $registered = ArchiveFileRegistrar::register($this->projectDir, 'media/photo.jpg', $files);

        $this->assertSame('photo.jpg', $registered['originalFilename']);
        $this->assertCount(1, $files);
        $this->assertSame($this->projectDir . '/public/media/photo.jpg', $files[$registered['archivePath']]);
    }

    // The random prefix is what keeps two files of the same name, coming from different directories, apart inside one archive
    public function testRegisterPrefixesTheArchivePathSoSameNamedFilesDontCollide(): void
    {
        file_put_contents($this->projectDir . '/public/media/photo.jpg', 'binary');
        $files = [];

        $first = ArchiveFileRegistrar::register($this->projectDir, 'media/photo.jpg', $files);
        $second = ArchiveFileRegistrar::register($this->projectDir, 'media/photo.jpg', $files);

        $this->assertNotSame($first['archivePath'], $second['archivePath']);
        $this->assertStringStartsWith('files/', $first['archivePath']);
        $this->assertStringEndsWith('_photo.jpg', $first['archivePath']);
        $this->assertCount(2, $files);
    }

    // A missing file is reported rather than exported as a broken reference, so the caller can skip that entry
    public function testRegisterReturnsNullAndRegistersNothingWhenTheFileIsMissing(): void
    {
        $files = [];

        $this->assertNull(ArchiveFileRegistrar::register($this->projectDir, 'media/gone.jpg', $files));
        $this->assertSame([], $files);
    }
}
