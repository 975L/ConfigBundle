<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\DependencyInjection\Compiler;

use c975L\ConfigBundle\Attribute\AsHealthCheck;
use c975L\ConfigBundle\DependencyInjection\Compiler\DeclaredUrlsHealthCheckPass;
use c975L\ConfigBundle\Management\ContentQualityAnalyzer;
use c975L\ConfigBundle\Management\DeclaredUrlsHealthCheckProvider;
use c975L\ConfigBundle\Management\SitemapProviderInterface;
use c975L\ConfigBundle\Management\SitePageSitemapProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Yaml\Yaml;

class DeclaredUrlsHealthCheckPassTest extends TestCase
{
    private function createContainer(bool $withAnalyzer = true): ContainerBuilder
    {
        $container = new ContainerBuilder();
        if ($withAnalyzer) {
            $container->setDefinition(ContentQualityAnalyzer::class, new Definition(ContentQualityAnalyzer::class));
        }

        return $container;
    }

    private function healthCheckDefinitions(ContainerBuilder $container): array
    {
        return array_filter(
            $container->getDefinitions(),
            static fn (Definition $definition) => DeclaredUrlsHealthCheckProvider::class === $definition->getClass(),
        );
    }

    // This pass is the only thing allowed to instantiate the provider: autowiring it from the src/ resource scan asks for a single SitemapProviderInterface implementation, which no app has - the container then fails to compile before anything else runs
    public function testServicesFileExcludesTheProviderFromAutowiring(): void
    {
        // PARSE_CUSTOM_TAGS: services.yaml uses !tagged_iterator, which the parser rejects otherwise
        $services = Yaml::parseFile(__DIR__ . '/../../../config/services.yaml', Yaml::PARSE_CUSTOM_TAGS)['services'];

        $this->assertStringContainsString('Management/DeclaredUrlsHealthCheckProvider.php', $services['c975L\ConfigBundle\\']['exclude']);
    }

    // Declaring a sitemap is all it takes: no health check class to write in BookBundle/ShopBundle/GalleryBundle/CrowdfundingBundle
    public function testProcessRegistersOneProviderPerSitemapProvider(): void
    {
        $container = $this->createContainer();
        $container->setDefinition('book.sitemap', new Definition(FakeBookSitemapProvider::class));
        $container->setDefinition('shop.sitemap', new Definition(FakeShopSitemapProvider::class));

        (new DeclaredUrlsHealthCheckPass())->process($container);

        $definitions = $this->healthCheckDefinitions($container);
        $this->assertCount(2, $definitions);
        foreach ($definitions as $definition) {
            $this->assertTrue($definition->hasTag('c975l.health_check_provider'));
        }
    }

    // SiteBundle's own pages are already checked, in more detail, by ContentQualityHealthCheckProvider
    public function testProcessSkipsTheSitePageSitemapProvider(): void
    {
        $container = $this->createContainer();
        $container->setDefinition('site.sitemap', new Definition(SitePageSitemapProvider::class));

        (new DeclaredUrlsHealthCheckPass())->process($container);

        $this->assertSame([], $this->healthCheckDefinitions($container));
    }

    public function testProcessIgnoresAServiceThatIsNotASitemapProvider(): void
    {
        $container = $this->createContainer();
        $container->setDefinition('some.service', new Definition(\stdClass::class));

        (new DeclaredUrlsHealthCheckPass())->process($container);

        $this->assertSame([], $this->healthCheckDefinitions($container));
    }

    // A definition can carry no class at all (an alias-like or abstract one) - not a reason to blow up the whole compilation
    public function testProcessIgnoresAClasslessDefinition(): void
    {
        $container = $this->createContainer();
        $container->setDefinition('classless', new Definition());

        (new DeclaredUrlsHealthCheckPass())->process($container);

        $this->assertSame([], $this->healthCheckDefinitions($container));
    }

    // Nothing to build a provider with if SiteBundle's own analyzer isn't there
    public function testProcessDoesNothingWithoutTheAnalyzer(): void
    {
        $container = $this->createContainer(withAnalyzer: false);
        $container->setDefinition('book.sitemap', new Definition(FakeBookSitemapProvider::class));

        (new DeclaredUrlsHealthCheckPass())->process($container);

        $this->assertSame([], $this->healthCheckDefinitions($container));
    }

    // An abstract definition is a template for other services, not a service: referencing it would fail the whole container's compilation
    public function testProcessSkipsAnAbstractDefinition(): void
    {
        $container = $this->createContainer();
        $container->setDefinition('book.sitemap.abstract', (new Definition(FakeBookSitemapProvider::class))->setAbstract(true));

        (new DeclaredUrlsHealthCheckPass())->process($container);

        $this->assertSame([], $this->healthCheckDefinitions($container));
    }

    // A synthetic service is injected at runtime and may never be set at all - no better a thing to health-check
    public function testProcessSkipsASyntheticDefinition(): void
    {
        $container = $this->createContainer();
        $container->setDefinition('book.sitemap.synthetic', (new Definition(FakeBookSitemapProvider::class))->setSynthetic(true));

        (new DeclaredUrlsHealthCheckPass())->process($container);

        $this->assertSame([], $this->healthCheckDefinitions($container));
    }

    // The generated providers must survive a real compilation, which is where an invalid reference would surface - collected here the same way ConfigBundle's HealthCheckRunner collects them, so they aren't removed as unused before anything gets checked
    public function testTheGeneratedProvidersCompile(): void
    {
        $container = $this->createContainer();
        $container->setDefinition('book.sitemap', new Definition(FakeBookSitemapProvider::class));
        $container->setDefinition('book.sitemap.abstract', (new Definition(FakeBookSitemapProvider::class))->setAbstract(true));
        $container->setDefinition('health_check_runner', (new Definition(\ArrayObject::class))
            ->setPublic(true)
            ->setArguments([new TaggedIteratorArgument('c975l.health_check_provider')]));

        (new DeclaredUrlsHealthCheckPass())->process($container);
        $container->compile();

        $this->assertCount(1, $this->healthCheckDefinitions($container));
    }

    // Each generated service gets its own id, so a second bundle's provider doesn't overwrite the first one's
    public function testProcessGivesEachGeneratedServiceItsOwnId(): void
    {
        $container = $this->createContainer();
        $container->setDefinition('book.sitemap', new Definition(FakeBookSitemapProvider::class));
        $container->setDefinition('shop.sitemap', new Definition(FakeShopSitemapProvider::class));

        (new DeclaredUrlsHealthCheckPass())->process($container);

        $this->assertCount(2, array_unique(array_keys($this->healthCheckDefinitions($container))));
    }

    // What a site sells or funds is worth catching weekly: a bundle saying nothing lands on the weekly entry
    public function testProcessPassesTheWeeklyCadenceByDefault(): void
    {
        $container = $this->createContainer();
        $container->setDefinition('book.sitemap', new Definition(FakeBookSitemapProvider::class));

        (new DeclaredUrlsHealthCheckPass())->process($container);

        $definition = array_values($this->healthCheckDefinitions($container))[0];
        $this->assertSame(AsHealthCheck::FREQUENCY_WEEKLY, $definition->getArgument(2));
    }

    // One class serves every bundle, so the cadence is read off each bundle's own sitemap provider - the only class that knows how much it declares
    public function testProcessReadsTheCadenceOffTheSitemapProvider(): void
    {
        $container = $this->createContainer();
        $container->setDefinition('gallery.sitemap', new Definition(FakeGallerySitemapProvider::class));

        (new DeclaredUrlsHealthCheckPass())->process($container);

        $definition = array_values($this->healthCheckDefinitions($container))[0];
        $this->assertSame(AsHealthCheck::FREQUENCY_MONTHLY, $definition->getArgument(2));
    }

    // The heavy bundle going monthly must not take the others with it
    public function testEachBundleKeepsItsOwnCadence(): void
    {
        $container = $this->createContainer();
        $container->setDefinition('book.sitemap', new Definition(FakeBookSitemapProvider::class));
        $container->setDefinition('gallery.sitemap', new Definition(FakeGallerySitemapProvider::class));

        (new DeclaredUrlsHealthCheckPass())->process($container);

        $frequencies = array_map(
            static fn (Definition $definition) => $definition->getArgument(2),
            array_values($this->healthCheckDefinitions($container)),
        );

        $this->assertSame([AsHealthCheck::FREQUENCY_WEEKLY, AsHealthCheck::FREQUENCY_MONTHLY], $frequencies);
    }
}

class FakeBookSitemapProvider implements SitemapProviderInterface
{
    public function getSitemapName(): string
    {
        return 'book';
    }

    public function getUrls(): array
    {
        return [];
    }
}

class FakeShopSitemapProvider implements SitemapProviderInterface
{
    public function getSitemapName(): string
    {
        return 'shop';
    }

    public function getUrls(): array
    {
        return [];
    }
}

// What GalleryBundle's own sitemap provider carries: one declared url per photo is the volume run of the family
#[AsHealthCheck(frequency: AsHealthCheck::FREQUENCY_MONTHLY)]
class FakeGallerySitemapProvider implements SitemapProviderInterface
{
    public function getSitemapName(): string
    {
        return 'gallery';
    }

    public function getUrls(): array
    {
        return [];
    }
}
