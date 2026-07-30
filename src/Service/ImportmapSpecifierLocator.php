<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;

// Locates the third-party packages a c975L bundle's own JS imports by bare specifier (e.g. "@symfony/ux-chartjs"), and where each one lives on disk - what c975l:config:check-importmap needs to spot an importmap.php missing one of them. A bare specifier with no importmap entry doesn't degrade gracefully: the browser refuses to resolve it, the whole module fails, and every Stimulus controller it was going to register is silently lost - a failure only visible in the browser console, which is exactly what this makes detectable from the CLI.
class ImportmapSpecifierLocator
{
    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    // Returns every bare specifier imported across the installed c975L bundles' assets. Relative ones ("./js/menu.js") are skipped: AssetMapper resolves those from the importing file itself, they never need an entry of their own
    public function findBareSpecifiers(): array
    {
        $directories = glob($this->projectDir . '/vendor/c975l/*/assets', GLOB_ONLYDIR) ?: [];
        if ([] === $directories) {
            return [];
        }

        $specifiers = [];
        foreach ((new Finder())->files()->in($directories)->name('*.js') as $file) {
            foreach ($this->extractSpecifiers((string) $file->getContents()) as $specifier) {
                $specifiers[] = $specifier;
            }
        }

        $specifiers = array_values(array_unique($specifiers));
        sort($specifiers);

        return $specifiers;
    }

    // Resolves a bare specifier to the path an importmap.php entry needs, following the Symfony UX convention: the package ships an assets/package.json whose "name" is the specifier and whose "main" points at its entry file. Returns null for anything not installed that way (a CDN-only package, a typo...), which the caller reports rather than guesses at
    public function resolvePath(string $specifier): ?string
    {
        foreach (glob($this->projectDir . '/vendor/*/*/assets/package.json') ?: [] as $file) {
            $package = json_decode((string) file_get_contents($file), true);

            if (!is_array($package) || ($package['name'] ?? null) !== $specifier) {
                continue;
            }

            $main = $package['main'] ?? null;
            if (!is_string($main) || '' === $main) {
                continue;
            }

            return './' . ltrim(str_replace($this->projectDir . '/', '', dirname($file)), '/') . '/' . $main;
        }

        return null;
    }

    // Matches "import x from 'y'", "import {x} from 'y'", bare "import 'y'" and "export ... from 'y'" - the four forms the ecosystem's own controllers actually use. Dynamic import() is deliberately out: its specifier is often computed, and a literal one is resolved at call time, not at module load, so a missing entry there doesn't take the whole module down
    private function extractSpecifiers(string $contents): array
    {
        preg_match_all(
            '#^\s*(?:import|export)\s+(?:[^\'"]*?\bfrom\s+)?[\'"]([^\'"]+)[\'"]#m',
            $contents,
            $matches
        );

        return array_filter(
            $matches[1],
            static fn (string $specifier): bool => !str_starts_with($specifier, '.') && !str_starts_with($specifier, '/')
        );
    }
}
