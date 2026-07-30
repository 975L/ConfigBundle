<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Controller\Management;

use c975L\ConfigBundle\Controller\Management\ConfigPruneController;
use c975L\ConfigBundle\Entity\Config;
use c975L\ConfigBundle\Repository\ConfigRepository;
use c975L\ConfigBundle\Service\ConfigDeclarationLocator;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

class ConfigPruneControllerTest extends TestCase
{
    use ControllerContainerTestTrait;

    private const TEMPLATE = '@c975LConfig/management/config_prune/index.html.twig';

    private function createConfig(string $slug): Config
    {
        return (new Config())->setSlug($slug)->setLabel($slug);
    }

    // Declaration files are located by globbing a real project dir, so the locator is stubbed rather than pointed at a fixture tree - ConfigDeclarationLocatorTest already covers the globbing itself
    private function createDeclarationLocator(array $files, array $declaredSlugs, array $unreadableFiles = []): ConfigDeclarationLocator
    {
        $locator = $this->createStub(ConfigDeclarationLocator::class);
        $locator->method('findFiles')->willReturn($files);
        $locator->method('findDeclaredSlugs')->willReturn($declaredSlugs);
        $locator->method('findUnreadableFiles')->willReturn($unreadableFiles);
        $locator->method('describe')->willReturnCallback(static fn (string $file) => basename($file));

        return $locator;
    }

    private function createController(
        ConfigRepository $configRepository,
        ConfigDeclarationLocator $declarationLocator,
        ?ConfigServiceInterface $configService = null,
        ?EntityManagerInterface $manager = null,
    ): ConfigPruneController {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new ConfigPruneController(
            $configRepository,
            $declarationLocator,
            $configService ?? $this->createStub(ConfigServiceInterface::class),
            $manager ?? $this->createStub(EntityManagerInterface::class),
            $translator,
        );
    }

    private function createTwigExpecting(array $context): Environment
    {
        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with(self::TEMPLATE, $context)
            ->willReturn('<html></html>');

        return $twig;
    }

    public function testIndexListsOnlyTheSlugsNoDeclarationFileDeclaresAnymore(): void
    {
        $orphan = $this->createConfig('site-favicon');

        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('findAllSlugs')->willReturn(['site-name', 'site-favicon']);
        $configRepository->expects($this->once())
            ->method('findBy')
            ->with(['slug' => ['site-favicon']], ['slug' => 'ASC'])
            ->willReturn([$orphan]);

        $controller = $this->createController(
            $configRepository,
            $this->createDeclarationLocator(['/app/config/configs.json'], ['site-name']),
        );
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'twig' => $this->createTwigExpecting(['orphans' => [$orphan], 'hasDeclarations' => true, 'unreadableFiles' => []]),
        ]));

        $this->assertSame(200, $controller->index()->getStatusCode());
    }

    // An unfinished install would otherwise turn every entry into an orphan, exactly the case c975l:config:prune refuses to run on
    public function testIndexReportsNoOrphanWhenNoDeclarationFileIsFound(): void
    {
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->expects($this->never())->method('findAllSlugs');

        $controller = $this->createController($configRepository, $this->createDeclarationLocator([], []));
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'twig' => $this->createTwigExpecting(['orphans' => [], 'hasDeclarations' => false, 'unreadableFiles' => []]),
        ]));

        $controller->index();
    }

    // A malformed configs.json declares none of its slugs, which would all show up here as orphans, pre-ticked and one click away from being deleted
    public function testIndexReportsNoOrphanWhenADeclarationFileCannotBeParsed(): void
    {
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->expects($this->never())->method('findAllSlugs');

        $controller = $this->createController(
            $configRepository,
            $this->createDeclarationLocator(['/app/config/configs.json'], [], ['/app/config/configs.json']),
        );
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'twig' => $this->createTwigExpecting(['orphans' => [], 'hasDeclarations' => true, 'unreadableFiles' => ['configs.json']]),
        ]));

        $controller->index();
    }

    // The listing being empty isn't enough: the submitted slugs are recomputed against the same gap on the delete path
    public function testDeleteRemovesNothingWhenADeclarationFileCannotBeParsed(): void
    {
        $configRepository = $this->createStub(ConfigRepository::class);
        $configRepository->method('findAllSlugs')->willReturn(['site-name', 'site-favicon']);

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->never())->method('remove');
        $manager->expects($this->never())->method('flush');

        $controller = $this->createController(
            $configRepository,
            $this->createDeclarationLocator(['/app/config/configs.json'], [], ['/app/config/configs.json']),
            null,
            $manager,
        );

        [$requestStack, $session] = $this->createRequestStackWithSession();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'router' => $this->createRouter('/management/obsolete-configs'),
            'request_stack' => $requestStack,
        ]));

        $controller->delete(new Request([], ['_token' => 'valid-token', 'slugs' => ['site-favicon']]));

        $this->assertSame(['flash.config_prune_nothing_deleted'], $session->getFlashBag()->get('warning'));
    }

    public function testIndexDeniesAccessWhenNotGranted(): void
    {
        $this->expectException(AccessDeniedException::class);

        $controller = $this->createController(
            $this->createStub(ConfigRepository::class),
            $this->createDeclarationLocator(['/app/config/configs.json'], []),
        );
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(false),
        ]));

        $controller->index();
    }

    public function testDeleteRemovesTheSubmittedOrphanAndInvalidatesCache(): void
    {
        $orphan = $this->createConfig('site-favicon');

        $configRepository = $this->createStub(ConfigRepository::class);
        $configRepository->method('findAllSlugs')->willReturn(['site-name', 'site-favicon']);
        $configRepository->method('findBy')->willReturn([$orphan]);

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->once())->method('remove')->with($orphan);
        $manager->expects($this->once())->method('flush');

        $configService = $this->createMock(ConfigServiceInterface::class);
        $configService->expects($this->once())->method('invalidateCache');

        $controller = $this->createController(
            $configRepository,
            $this->createDeclarationLocator(['/app/config/configs.json'], ['site-name']),
            $configService,
            $manager,
        );

        [$requestStack, $session] = $this->createRequestStackWithSession();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'router' => $this->createRouter('/management/obsolete-configs'),
            'request_stack' => $requestStack,
        ]));

        $response = $controller->delete(new Request([], ['_token' => 'valid-token', 'slugs' => ['site-favicon']]));

        $this->assertSame(['flash.config_prune_deleted'], $session->getFlashBag()->get('success'));
        $this->assertSame('/management/obsolete-configs', $response->getTargetUrl());
    }

    // Only the ticked rows go, the others staying in database even though they're orphans too
    public function testDeleteRemovesOnlyTheCheckedOrphans(): void
    {
        $favicon = $this->createConfig('site-favicon');
        $logo = $this->createConfig('site-logo');

        $configRepository = $this->createStub(ConfigRepository::class);
        $configRepository->method('findAllSlugs')->willReturn(['site-favicon', 'site-logo']);
        $configRepository->method('findBy')->willReturnCallback(
            static fn (array $criteria) => array_values(array_filter(
                [$favicon, $logo],
                static fn (Config $config) => \in_array($config->getSlug(), $criteria['slug'], true),
            )),
        );

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->once())->method('remove')->with($favicon);

        $controller = $this->createController(
            $configRepository,
            $this->createDeclarationLocator(['/app/config/configs.json'], []),
            null,
            $manager,
        );
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'router' => $this->createRouter('/management/obsolete-configs'),
            'request_stack' => $this->createRequestStackWithSession()[0],
        ]));

        $controller->delete(new Request([], ['_token' => 'valid-token', 'slugs' => ['site-favicon']]));
    }

    // A page left open while a bundle is reinstalled must not take a declared entry - and its value - with it
    public function testDeleteIgnoresASubmittedSlugThatIsDeclaredAgain(): void
    {
        $configRepository = $this->createStub(ConfigRepository::class);
        $configRepository->method('findAllSlugs')->willReturn(['site-name', 'site-favicon']);

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->never())->method('remove');
        $manager->expects($this->never())->method('flush');

        $controller = $this->createController(
            $configRepository,
            $this->createDeclarationLocator(['/app/config/configs.json'], ['site-name', 'site-favicon']),
            null,
            $manager,
        );

        [$requestStack, $session] = $this->createRequestStackWithSession();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'router' => $this->createRouter('/management/obsolete-configs'),
            'request_stack' => $requestStack,
        ]));

        $controller->delete(new Request([], ['_token' => 'valid-token', 'slugs' => ['site-favicon']]));

        $this->assertSame(['flash.config_prune_nothing_deleted'], $session->getFlashBag()->get('warning'));
    }

    public function testDeleteDoesNothingWhenCsrfTokenIsInvalid(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->never())->method('remove');

        $controller = $this->createController(
            $this->createStub(ConfigRepository::class),
            $this->createDeclarationLocator(['/app/config/configs.json'], []),
            null,
            $manager,
        );
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(false),
            'router' => $this->createRouter('/management/obsolete-configs'),
        ]));

        $response = $controller->delete(new Request([], ['_token' => 'invalid-token', 'slugs' => ['site-favicon']]));

        $this->assertSame('/management/obsolete-configs', $response->getTargetUrl());
    }

    public function testDeleteDeniesAccessWhenNotGranted(): void
    {
        $this->expectException(AccessDeniedException::class);

        $controller = $this->createController(
            $this->createStub(ConfigRepository::class),
            $this->createDeclarationLocator(['/app/config/configs.json'], []),
        );
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(false),
        ]));

        $controller->delete(new Request());
    }
}
