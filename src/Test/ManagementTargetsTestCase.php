<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Test;

use c975L\ConfigBundle\Controller\Management\DashboardController;
use c975L\ConfigBundle\Management\EssentialActionProviderInterface;
use c975L\ConfigBundle\Management\GuidedProjectProviderInterface;
use c975L\ConfigBundle\Management\LinkableRouteProviderInterface;
use c975L\ConfigBundle\Management\MenuProviderInterface;
use c975L\ConfigBundle\Management\ShortcutProviderInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Extend this in a bundle contributing to the management interface, and its menus, shortcuts, essential actions, guided
 * projects and linkable routes are all checked against what actually exists - each one names a target (a CRUD controller
 * class, a route name) that nothing else verifies, so a renamed route or a moved controller compiles fine and only fails
 * once an admin clicks the entry. No kernel and no database: route names are read off the #[AdminRoute]/#[Route]
 * attributes the controllers carry, which is also why this class ships in src/ rather than tests/ - a bundle can't
 * autoload another bundle's test files. It is excluded from the service resource scan (see config/services.yaml), PHPUnit
 * being a require-dev dependency that isn't there in production.
 *
 * The only thing to write bundle-side:
 *
 *     class ManagementTargetsTest extends ManagementTargetsTestCase
 *     {
 *         protected function managementProviders(): iterable
 *         {
 *             // Providers generating urls take the two recorders below, so their targets can be read back
 *             return [
 *                 new MenuProvider($this->createStub(ConfigServiceInterface::class)),
 *                 new ShopGuidedProjectProvider($this->adminUrlGenerator(), $this->urlGenerator()),
 *             ];
 *         }
 *
 *         protected function controllerDirectories(): array
 *         {
 *             return [...parent::controllerDirectories(), __DIR__ . '/../../src/Controller'];
 *         }
 *     }
 */
abstract class ManagementTargetsTestCase extends TestCase
{
    /** @var list<string> Route names the providers asked the router to generate, filled by urlGenerator() */
    protected array $generatedRoutes = [];

    /** @var list<string> CRUD controllers the providers asked EasyAdmin to generate an url for, filled by adminUrlGenerator() */
    protected array $generatedControllers = [];

    // The bundle's own providers, already built: each one decides how to stub its dependencies, this class only reads what they return
    abstract protected function managementProviders(): iterable;

    // Directories holding the controllers whose routes these targets may name - ConfigBundle's own by default, every bundle linking to its screens; add the bundle's own on top (see the class docblock)
    protected function controllerDirectories(): array
    {
        return [__DIR__ . '/../Controller/Management'];
    }

    // An AdminUrlGenerator recording the CRUD controllers it is handed: an essential action or a guided step keeps only the generated url, so the target is captured on its way through
    protected function adminUrlGenerator(): AdminUrlGeneratorInterface
    {
        $generator = $this->createStub(AdminUrlGeneratorInterface::class);
        $generator->method('unsetAll')->willReturnSelf();
        $generator->method('setAction')->willReturnSelf();
        $generator->method('setEntityId')->willReturnSelf();
        $generator->method('set')->willReturnSelf();
        $generator->method('generateUrl')->willReturn('/management/generated');
        $generator->method('setController')->willReturnCallback(
            function (string $controller) use ($generator): AdminUrlGeneratorInterface {
                $this->generatedControllers[] = $controller;

                return $generator;
            }
        );

        return $generator;
    }

    // Same for the plain router, recording route names instead
    protected function urlGenerator(): UrlGeneratorInterface
    {
        $generator = $this->createStub(UrlGeneratorInterface::class);
        $generator->method('generate')->willReturnCallback(
            function (string $route): string {
                $this->generatedRoutes[] = $route;

                return '/management/' . $route;
            }
        );

        return $generator;
    }

    // A sidebar link naming a route that no longer exists throws on the dashboard itself, taking the whole back office down rather than just its own entry
    public function testEveryMenuLinkPointsToADeclaredRoute(): void
    {
        $this->assertTargetsExist($this->collect()['linkRoutes'], $this->declaredRouteNames(), 'menu link');
    }

    // A menu entry names a CRUD controller class, which EasyAdmin resolves to its index route
    public function testEveryMenuEntryPointsToACrudController(): void
    {
        $this->assertCrudControllers($this->collect()['menuControllers'], 'menu entry');
    }

    // A dashboard tile posts to its route (or opens it, for a GET one) - a renamed route only shows up as a 404 on click
    public function testEveryShortcutPointsToADeclaredRoute(): void
    {
        $this->assertTargetsExist($this->collect()['shortcutRoutes'], $this->declaredRouteNames(), 'shortcut');
    }

    // A route offered as a Menu item target has to still be there when the item is rendered on the front end, long after it was picked in the back office
    public function testEveryLinkableRouteIsDeclared(): void
    {
        $this->assertTargetsExist($this->collect()['linkableRoutes'], $this->declaredRouteNames(), 'linkable route');
    }

    // Essential actions and guided steps hold an already-generated url, so their targets are the ones the recorders captured while the providers ran
    public function testEveryGeneratedUrlPointsToADeclaredRoute(): void
    {
        $this->collect();

        $this->assertTargetsExist($this->generatedRoutes, $this->declaredRouteNames(), 'generated url');
    }

    public function testEveryGeneratedUrlPointsToACrudController(): void
    {
        $this->collect();

        $this->assertCrudControllers($this->generatedControllers, 'generated url');
    }

    // A provider returning nothing at all (a stub too tight, a renamed method) would make every test above pass on an empty list
    public function testTheProvidersExposeAtLeastOneTarget(): void
    {
        $targets = $this->collect();

        $this->assertNotEmpty(
            array_merge(...array_values($targets), ...[$this->generatedRoutes, $this->generatedControllers]),
            'The providers exposed no target at all, which would make every other test of this class pass on nothing',
        );
    }

    // Walks each provider once, by the interfaces it implements - a bundle contributing only a menu simply leaves the other lists empty
    private function collect(): array
    {
        $targets = ['menuControllers' => [], 'linkRoutes' => [], 'shortcutRoutes' => [], 'linkableRoutes' => []];

        foreach ($this->managementProviders() as $provider) {
            if ($provider instanceof MenuProviderInterface) {
                $targets['menuControllers'] = [...$targets['menuControllers'], ...array_column($provider->getMenus(), 'controller')];
                // A link carrying its own literal 'url' (the site's own address, a known deployment) names no route at all - see MenuProviderInterface::getLinks()
                $targets['linkRoutes'] = [...$targets['linkRoutes'], ...array_column(array_filter($provider->getLinks(), static fn ($link) => !isset($link['url'])), 'name')];
            }

            if ($provider instanceof ShortcutProviderInterface) {
                $targets['shortcutRoutes'] = [...$targets['shortcutRoutes'], ...array_column($provider->getShortcuts(), 'route')];
            }

            if ($provider instanceof LinkableRouteProviderInterface) {
                $targets['linkableRoutes'] = [...$targets['linkableRoutes'], ...array_keys($provider->getLinkableRoutes())];
            }

            // Nothing to read back from these two: their urls are already generated, and the recorders above captured what they were built from
            if ($provider instanceof EssentialActionProviderInterface) {
                $provider->getEssentialActions();
            }

            if ($provider instanceof GuidedProjectProviderInterface) {
                $provider->getGuidedProjects();
            }
        }

        return $targets;
    }

    // Every route name the watched controllers declare, spelled the way it is registered: the dashboard's own route name prefixing each #[AdminRoute], and plain #[Route] names as they are
    private function declaredRouteNames(): array
    {
        $dashboardRouteName = (new \ReflectionClass(DashboardController::class))->getAttributes(AdminDashboard::class)[0]->newInstance()->routeName;
        $names = [$dashboardRouteName];

        foreach ($this->controllerDirectories() as $directory) {
            foreach (glob(rtrim($directory, '/') . '/*.php') ?: [] as $file) {
                $class = $this->classInFile($file);
                if (null === $class || !class_exists($class)) {
                    continue;
                }

                $reflection = new \ReflectionClass($class);
                // A class-level attribute carries the prefix its methods' own names are appended to
                $adminPrefix = $dashboardRouteName . '_' . $this->attributeName($reflection, AdminRoute::class);
                $routePrefix = $this->attributeName($reflection, Route::class);

                foreach ($reflection->getMethods() as $method) {
                    // An inherited method's routes belong to the class declaring it, and would be spelled here with this class' own prefix
                    if ($method->getDeclaringClass()->getName() !== $class) {
                        continue;
                    }

                    foreach ($method->getAttributes(AdminRoute::class) as $attribute) {
                        $name = $attribute->newInstance()->name;
                        if (null !== $name) {
                            $names[] = $adminPrefix . $name;
                        }
                    }

                    foreach ($method->getAttributes(Route::class) as $attribute) {
                        $name = $attribute->newInstance()->name;
                        if (null !== $name) {
                            $names[] = $routePrefix . $name;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($names));
    }

    // The name a class-level route attribute contributes as a prefix, '' when it carries none (the usual case) - both attributes spell it as a public "name" property
    private function attributeName(\ReflectionClass $reflection, string $attributeClass): string
    {
        $attributes = $reflection->getAttributes($attributeClass);
        if ([] === $attributes) {
            return '';
        }

        $instance = $attributes[0]->newInstance();

        return $instance instanceof AdminRoute || $instance instanceof Route ? (string) $instance->name : '';
    }

    // Read off the file rather than derived from its path: a bundle is free to map its namespaces the way it likes
    private function classInFile(string $file): ?string
    {
        $source = (string) file_get_contents($file);

        if (!preg_match('/^namespace\s+([^;]+);/m', $source, $namespace)
            || !preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $source, $class)) {
            return null;
        }

        return trim($namespace[1]) . '\\' . $class[1];
    }

    // One assertion per test rather than one per target: the failure message names every unknown target at once, and an empty list never leaves the test without an assertion
    private function assertTargetsExist(array $targets, array $declared, string $kind): void
    {
        $unknown = array_values(array_unique(array_diff($targets, $declared)));

        $this->assertSame([], $unknown, sprintf('Each of these %s targets names a route no watched controller declares: %s', $kind, implode(', ', $unknown)));
    }

    private function assertCrudControllers(array $controllers, string $kind): void
    {
        $notCrud = array_values(array_unique(array_filter($controllers, static fn ($controller) => !is_subclass_of((string) $controller, AbstractCrudController::class))));

        $this->assertSame([], $notCrud, sprintf('Each of these %s targets names a class that is not a CRUD controller: %s', $kind, implode(', ', $notCrud)));
    }
}
