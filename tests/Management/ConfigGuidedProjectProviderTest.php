<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\ConfigGuidedProjectProvider;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ConfigGuidedProjectProviderTest extends TestCase
{
    private function createAdminUrlGenerator(): AdminUrlGeneratorInterface
    {
        $generator = $this->createStub(AdminUrlGeneratorInterface::class);
        $generator->method('unsetAll')->willReturnSelf();
        $generator->method('setController')->willReturnSelf();
        $generator->method('setAction')->willReturnSelf();
        $generator->method('generateUrl')->willReturn('/management/config');

        return $generator;
    }

    private function createUrlGenerator(array &$routes = []): UrlGeneratorInterface
    {
        $generator = $this->createStub(UrlGeneratorInterface::class);
        $generator->method('generate')->willReturnCallback(
            static function (string $route) use (&$routes): string {
                $routes[] = $route;

                return '/management/' . $route;
            }
        );

        return $generator;
    }

    private function createProvider(array &$routes = []): ConfigGuidedProjectProvider
    {
        return new ConfigGuidedProjectProvider($this->createAdminUrlGenerator(), $this->createUrlGenerator($routes));
    }

    // ConfigBundle opens the sequence the satellite bundles continue - SiteBundle picks up at 50
    public function testGetGuidedProjectsOpensTheOrderSequence(): void
    {
        $projects = $this->createProvider()->getGuidedProjects();

        $this->assertSame(
            ['config-settings', 'config-health-check', 'config-maintenance'],
            array_column($projects, 'slug')
        );
        $this->assertSame([10, 20, 30], array_column($projects, 'order'));
    }

    public function testEverySlugIsPrefixedWithTheBundleName(): void
    {
        foreach ($this->createProvider()->getGuidedProjects() as $project) {
            $this->assertStringStartsWith('config-', $project['slug'], 'A slug is unique across every bundle contributing projects');
        }
    }

    public function testEveryProjectCarriesTheConfigTranslationDomainAndSteps(): void
    {
        foreach ($this->createProvider()->getGuidedProjects() as $project) {
            $this->assertSame('config', $project['translation_domain']);
            $this->assertNotEmpty($project['steps']);
        }
    }

    public function testNoStepSetsBothUrlAndHighlight(): void
    {
        foreach ($this->createProvider()->getGuidedProjects() as $project) {
            foreach ($project['steps'] as $index => $step) {
                $this->assertFalse(
                    isset($step['url']) && isset($step['highlight']),
                    sprintf('Step %d of "%s" sets both url and highlight', $index, $project['slug'])
                );
            }
        }
    }

    // EasyAdmin renders a button as `action-<actionName>`, so a highlight guessing at the name (`.action-save`) points at nothing
    public function testEveryHighlightedActionIsAnEasyAdminOne(): void
    {
        $actions = $this->easyAdminActionNames();

        foreach ($this->createProvider()->getGuidedProjects() as $project) {
            foreach ($project['steps'] as $index => $step) {
                if (!isset($step['highlight']) || !preg_match('/^\.action-(\w+)$/', $step['highlight'], $matches)) {
                    continue;
                }

                $this->assertContains(
                    $matches[1],
                    $actions,
                    sprintf('Step %d of "%s" highlights an action EasyAdmin does not render', $index, $project['slug'])
                );
            }
        }
    }

    private function easyAdminActionNames(): array
    {
        $constants = (new \ReflectionClass(Action::class))->getConstants();

        return array_values(array_filter(
            $constants,
            static fn (string $name): bool => !str_starts_with($name, 'TYPE_'),
            ARRAY_FILTER_USE_KEY
        ));
    }

    // Only the opening step leaves the screen, everything after it walking the one the user has been sent to
    public function testOnlyTheFirstStepOfEachProjectCarriesAnUrl(): void
    {
        foreach ($this->createProvider()->getGuidedProjects() as $project) {
            $steps = $project['steps'];

            $this->assertArrayHasKey('url', $steps[0], sprintf('Project "%s" does not open on a screen', $project['slug']));

            foreach (array_slice($steps, 1) as $index => $step) {
                $this->assertArrayNotHasKey('url', $step, sprintf('Step %d of "%s" leaves the screen again', $index + 1, $project['slug']));
            }
        }
    }

    public function testTheRouteBasedProjectsOpenOnTheirOwnScreen(): void
    {
        $routes = [];
        $this->createProvider($routes)->getGuidedProjects();

        $this->assertSame(['management_health_check_index', 'management'], $routes);
    }

    // A label or description with no translation reads as its own key in the panel
    public function testEveryLabelAndDescriptionIsTranslated(): void
    {
        $translated = $this->translatedKeys();

        foreach ($this->createProvider()->getGuidedProjects() as $project) {
            foreach ([$project, ...$project['steps']] as $item) {
                $this->assertContains($item['label'], $translated);
                if (isset($item['description'])) {
                    $this->assertContains($item['description'], $translated);
                }
            }
        }
    }

    private function translatedKeys(): array
    {
        $xliff = new \DOMDocument();
        $xliff->load(\dirname(__DIR__, 2) . '/translations/config.fr.xlf');

        $keys = [];
        foreach ($xliff->getElementsByTagName('source') as $source) {
            $keys[] = $source->textContent;
        }

        return $keys;
    }
}
