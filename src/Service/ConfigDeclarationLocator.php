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

// Locates every configs*.json declaring config entries - the single source of truth for what a config entry is, shared by c975l:config:load-all (what to load) and c975l:config:prune (what is no longer declared)
class ConfigDeclarationLocator
{
    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    // Returns every declaration file: the installed c975L bundles' ones, plus the consuming application's own config/configs*.json
    public function findFiles(): array
    {
        $files = array_merge(
            glob($this->projectDir . '/vendor/c975l/*/config/configs*.json') ?: [],
            glob($this->projectDir . '/config/configs*.json') ?: [],
        );

        sort($files);

        return $files;
    }

    // Returns every slug declared across those files, a malformed file being ignored rather than fatal
    public function findDeclaredSlugs(): array
    {
        $slugs = [];

        foreach ($this->findFiles() as $file) {
            $configs = json_decode((string) file_get_contents($file), true);

            if (!is_array($configs)) {
                continue;
            }

            foreach ($configs as $config) {
                if (isset($config['slug'])) {
                    $slugs[] = $config['slug'];
                }
            }
        }

        return array_values(array_unique($slugs));
    }

    // Returns the declaration files that exist but can't be parsed - their slugs are missing from findDeclaredSlugs(), so any caller deleting undeclared entries (c975l:config:prune) must refuse to run rather than take them for orphans
    public function findUnreadableFiles(): array
    {
        $unreadable = [];

        foreach ($this->findFiles() as $file) {
            if (!is_array(json_decode((string) file_get_contents($file), true))) {
                $unreadable[] = $file;
            }
        }

        return $unreadable;
    }

    // Returns a short readable name for a declaration file: the c975L bundle it belongs to, or "app" for the application's own file
    public function describe(string $file): string
    {
        return str_starts_with($file, $this->projectDir . '/config/')
            ? 'app (' . basename($file) . ')'
            : basename(dirname(dirname($file)));
    }
}
