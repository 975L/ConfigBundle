<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

// The resource scan of services.yaml reflects on every class it finds under src/, so a class that can't be loaded there fails the container compilation of every consuming app - which is why some paths are excluded. An exclusion is silent when it goes stale (a renamed directory excludes nothing at all), and only shows up as a broken app, so each one is checked here
class ServicesYamlExclusionsTest extends TestCase
{
    private const SRC_DIR = __DIR__ . '/../src';

    // The paths listed between the braces of "../src/{A,B,C}"
    private function excludedPaths(): array
    {
        // PARSE_CUSTOM_TAGS: services.yaml uses !tagged_iterator, which the parser rejects otherwise
        $services = Yaml::parseFile(__DIR__ . '/../config/services.yaml', Yaml::PARSE_CUSTOM_TAGS)['services'];
        preg_match('/\{(.+)\}/', $services['c975L\ConfigBundle\\']['exclude'], $matches);

        return array_map('trim', explode(',', $matches[1]));
    }

    public function testEveryExcludedPathStillExists(): void
    {
        foreach ($this->excludedPaths() as $path) {
            $this->assertFileExists(self::SRC_DIR . '/' . $path, sprintf('"%s" is excluded from the service resource scan but no longer exists, so the exclusion silently covers nothing', $path));
        }
    }

    // ManagementTargetsTestCase extends PHPUnit's TestCase, a require-dev dependency absent in production
    public function testTheTestHelpersAreExcludedFromTheScan(): void
    {
        $this->assertContains('Test', $this->excludedPaths(), 'src/Test holds classes extending PHPUnit\'s TestCase, which no production app can load');
    }
}
