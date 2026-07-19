<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\ProviderMerger;
use PHPUnit\Framework\TestCase;

class ProviderMergerTest extends TestCase
{
    public function testMergeFlattensExtractedArraysAcrossProviders(): void
    {
        $providers = [
            new class {
                public function getItems(): array
                {
                    return ['a' => 1];
                }
            },
            new class {
                public function getItems(): array
                {
                    return ['b' => 2];
                }
            },
        ];

        $merged = ProviderMerger::merge($providers, fn (object $provider) => $provider->getItems());

        $this->assertSame(['a' => 1, 'b' => 2], $merged);
    }

    public function testMergeReturnsEmptyArrayWhenNoProviders(): void
    {
        $merged = ProviderMerger::merge([], fn (object $provider) => $provider->getItems());

        $this->assertSame([], $merged);
    }

    // A later provider's numeric-keyed items don't overwrite an earlier one's since array_merge renumbers them, while string keys shared by two providers do overwrite (last wins)
    public function testMergeRenumbersNumericKeysButLastWinsForStringKeys(): void
    {
        $providers = [
            new class {
                public function getItems(): array
                {
                    return [0 => 'first', 'shared' => 'from-first'];
                }
            },
            new class {
                public function getItems(): array
                {
                    return [0 => 'second', 'shared' => 'from-second'];
                }
            },
        ];

        $merged = ProviderMerger::merge($providers, fn (object $provider) => $provider->getItems());

        // array_merge() overwrites a string key in place (keeping its original position) while numeric keys are always appended, renumbered, at the end
        $this->assertSame([0 => 'first', 'shared' => 'from-second', 1 => 'second'], $merged);
    }
}
