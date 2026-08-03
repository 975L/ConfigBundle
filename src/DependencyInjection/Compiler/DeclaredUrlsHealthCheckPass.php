<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\DependencyInjection\Compiler;

use c975L\ConfigBundle\Attribute\AsHealthCheck;
use c975L\ConfigBundle\Management\ContentQualityAnalyzer;
use c975L\ConfigBundle\Management\DeclaredUrlsHealthCheckProvider;
use c975L\ConfigBundle\Management\SelfCheckedSitemapProviderInterface;
use c975L\ConfigBundle\Management\SitemapProviderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

// Registers one DeclaredUrlsHealthCheckProvider per SitemapProviderInterface found in the container, so a bundle declaring a sitemap is health-checked without implementing anything else - BookBundle, ShopBundle, GalleryBundle and CrowdfundingBundle get their books/products/photos/campaigns checked with no line of code of their own. Services are discovered by interface rather than by ConfigBundle's own tag, so this doesn't depend on which bundle's compiler pass ran first
class DeclaredUrlsHealthCheckPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(ContentQualityAnalyzer::class)) {
            return;
        }

        foreach ($container->getDefinitions() as $id => $definition) {
            // An abstract definition is a template for other services, not a service - referencing one fails the container's compilation outright. A synthetic one is injected at runtime and may never be set at all, which is no better a thing to health-check. ConfigBundle's TaggedInterfacePass needs neither guard because it only tags what it finds, where this pass builds a Reference to it
            if ($definition->isAbstract() || $definition->isSynthetic()) {
                continue;
            }

            $class = $definition->getClass();
            if (!$class || !$this->isDeclaredUrlSource($class)) {
                continue;
            }

            $container->setDefinition(
                'c975l.site.declared_urls_health_check.' . $this->suffix($class),
                (new Definition(DeclaredUrlsHealthCheckProvider::class))
                    ->setArguments([new Reference($id), new Reference(ContentQualityAnalyzer::class), $this->frequencyOf($class)])
                    ->addTag('c975l.health_check_provider'),
            );
        }
    }

    // The cadence is read off the bundle's own sitemap provider, the only class that knows how much it declares: GalleryBundle marks its own #[AsHealthCheck(monthly)] because it holds one url per photo, where books and products stay on the weekly default. Nothing to add here when a new bundle ships a heavy sitemap - it says so itself
    private function frequencyOf(string $class): string
    {
        try {
            $attributes = (new \ReflectionClass($class))->getAttributes(AsHealthCheck::class);
        } catch (\Throwable) {
            return AsHealthCheck::FREQUENCY_WEEKLY;
        }

        return $attributes ? $attributes[0]->newInstance()->frequency : AsHealthCheck::FREQUENCY_WEEKLY;
    }

    // A sitemap whose urls already have a check of their own opts out by implementing SelfCheckedSitemapProviderInterface (SiteBundle's pages do: ContentQualityHealthCheckProvider checks every one of them in more detail) - the bundle owning the sitemap says so itself, rather than this pass naming a class from a bundle it must not depend on
    private function isDeclaredUrlSource(string $class): bool
    {
        try {
            if (is_subclass_of($class, SelfCheckedSitemapProviderInterface::class)) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        try {
            // Same guard as ConfigBundle's TaggedInterfacePass: a vendor service can reference a class whose interfaces come from a require-dev-only package, absent in prod
            return is_subclass_of($class, SitemapProviderInterface::class);
        } catch (\Throwable) {
            return false;
        }
    }

    // Only used to keep the generated service ids apart and readable - the kind the dashboard shows comes from the sitemap provider itself, at runtime (see DeclaredUrlsHealthCheckProvider::getKind())
    private function suffix(string $class): string
    {
        return strtolower(str_replace('\\', '_', $class));
    }
}
