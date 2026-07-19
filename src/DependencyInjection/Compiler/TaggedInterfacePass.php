<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

// Auto-tags every service implementing $interface with $tag, so it's collected by a !tagged_iterator (one instance per provider mechanism, see c975LConfigBundle::build())
class TaggedInterfacePass implements CompilerPassInterface
{
    public function __construct(
        private readonly string $interface,
        private readonly string $tag,
    ) {
    }

    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass();
            if (!$class) {
                continue;
            }

            try {
                // Some vendor services (e.g. Symfony's translation extractor visitors) reference classes whose interfaces come from require-dev-only packages (e.g. nikic/php-parser), which aren't installed in prod (--no-dev)
                if (is_subclass_of($class, $this->interface)) {
                    $definition->addTag($this->tag);
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
    }
}
