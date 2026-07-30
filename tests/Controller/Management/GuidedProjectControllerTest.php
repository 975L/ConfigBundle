<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Controller\Management;

use c975L\ConfigBundle\Controller\Management\GuidedProjectController;
use c975L\ConfigBundle\Management\GuidedProjectBuilder;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class GuidedProjectControllerTest extends TestCase
{
    use ControllerContainerTestTrait;

    private const PROJECT = [
        'slug' => 'creer-page',
        'label' => 'Créer une page',
        'description' => '',
        'steps' => [['label' => 'Ouvrir la liste des pages', 'description' => '', 'url' => '/management/page', 'highlight' => null]],
    ];

    private function createController(?array $project, bool $granted = true): GuidedProjectController
    {
        $guidedProjectBuilder = $this->createStub(GuidedProjectBuilder::class);
        $guidedProjectBuilder->method('getProject')->willReturn($project);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('ROLE_ADMIN');

        $controller = new GuidedProjectController($guidedProjectBuilder, $configService);
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker($granted),
        ]));

        return $controller;
    }

    public function testStepsReturnsTheProjectAsJson(): void
    {
        $response = $this->createController(self::PROJECT)->steps('creer-page');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(self::PROJECT, json_decode($response->getContent(), true));
    }

    // The slug comes from the browser's own storage: a bundle uninstalled since leaves one behind, and the panel drops it on the 404 rather than retrying it on every admin page
    public function testStepsThrowsNotFoundForAnUnknownSlug(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->createController(null)->steps('gone-with-its-bundle');
    }

    public function testStepsDeniesAccessWithoutTheAdminRole(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->createController(self::PROJECT, false)->steps('creer-page');
    }
}
