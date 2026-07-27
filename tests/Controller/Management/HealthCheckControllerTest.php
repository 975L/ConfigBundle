<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Controller\Management;

use c975L\ConfigBundle\Controller\Management\HealthCheckController;
use c975L\ConfigBundle\Entity\HealthCheckResult;
use c975L\ConfigBundle\Management\AlertBuilder;
use c975L\ConfigBundle\Management\HealthCheckAdviceBuilder;
use c975L\ConfigBundle\Management\HealthCheckRunner;
use c975L\ConfigBundle\Management\HealthCheckTrendChartBuilder;
use c975L\ConfigBundle\Repository\HealthCheckResultRepository;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\Export\ExportFormat;
use c975L\ConfigBundle\Service\Export\TableExporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

class HealthCheckControllerTest extends TestCase
{
    use ControllerContainerTestTrait;

    private function createConfigService(): ConfigServiceInterface
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('site-role-admin');

        return $configService;
    }

    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return $translator;
    }

    private function createAlertBuilder(array $alerts = []): AlertBuilder
    {
        $alertBuilder = $this->createStub(AlertBuilder::class);
        $alertBuilder->method('getAlerts')->willReturn($alerts);

        return $alertBuilder;
    }

    private function createAdviceBuilder(array $advice = []): HealthCheckAdviceBuilder
    {
        $adviceBuilder = $this->createStub(HealthCheckAdviceBuilder::class);
        $adviceBuilder->method('build')->willReturn($advice);

        return $adviceBuilder;
    }

    private function createTableExporter(): TableExporter
    {
        return $this->createStub(TableExporter::class);
    }

    private function createTrendChartBuilder(): HealthCheckTrendChartBuilder
    {
        $builder = $this->createStub(HealthCheckTrendChartBuilder::class);
        $builder->method('build')->willReturn(null);

        return $builder;
    }

    public function testIndexRendersTemplateWithLatestResultsAndAlerts(): void
    {
        $resultA = (new HealthCheckResult())->setKind('pagespeed')->setUrl('https://example.com/')->setStatus(HealthCheckResult::STATUS_OK)->setSummary('ok')->setCheckedAt(new \DateTime('2026-07-24 10:00:00'));
        $resultB = (new HealthCheckResult())->setKind('w3c')->setUrl('https://example.com/contact/')->setStatus(HealthCheckResult::STATUS_OK)->setSummary('ok')->setCheckedAt(new \DateTime('2026-07-24 10:05:00'));

        $healthCheckResultRepository = $this->createStub(HealthCheckResultRepository::class);
        $healthCheckResultRepository->method('findLatestPerUrlAndKind')->willReturn([$resultA, $resultB]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with(
                '@c975LConfig/management/health_check/index.html.twig',
                [
                    'results' => [$resultA, $resultB],
                    'kinds' => ['pagespeed', 'w3c'],
                    'siteResults' => [],
                    'siteKinds' => [],
                    'alerts' => ['danger' => ['some-alert']],
                    'trendChart' => null,
                    'lastCheckedAt' => $resultB->getCheckedAt(),
                    'advice' => [],
                ],
            )
            ->willReturn('<html></html>');

        $controller = new HealthCheckController(
            $healthCheckResultRepository,
            $this->createStub(HealthCheckRunner::class),
            $this->createAlertBuilder(['danger' => ['some-alert']]),
            $this->createAdviceBuilder(),
            $this->createTableExporter(),
            $this->createTrendChartBuilder(),
            $this->createConfigService(),
            $this->createTranslator(),
        );
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'twig' => $twig,
        ]));

        $response = $controller->index();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('<html></html>', $response->getContent());
    }

    public function testIndexPullsSiteWideKindsOutOfTheTableResults(): void
    {
        $pagespeedResult = (new HealthCheckResult())->setKind('pagespeed')->setUrl('https://example.com/')->setStatus(HealthCheckResult::STATUS_OK)->setSummary('ok')->setCheckedAt(new \DateTime('2026-07-24 10:00:00'));
        $securityHeadersResult = (new HealthCheckResult())->setKind('security-headers')->setUrl('https://example.com/')->setStatus(HealthCheckResult::STATUS_OK)->setSummary('6/6')->setCheckedAt(new \DateTime('2026-07-24 10:00:00'));
        $sslCertificateResult = (new HealthCheckResult())->setKind('ssl-certificate')->setUrl('https://example.com')->setStatus(HealthCheckResult::STATUS_OK)->setSummary('89 days left')->setCheckedAt(new \DateTime('2026-07-24 10:00:00'));
        // Checked once for the whole site (http/https redirection, 404 page), not once per page
        $deploymentResult = (new HealthCheckResult())->setKind('deployment')->setUrl('https://example.com')->setStatus(HealthCheckResult::STATUS_OK)->setSummary('4/4')->setCheckedAt(new \DateTime('2026-07-24 10:00:00'));

        $healthCheckResultRepository = $this->createStub(HealthCheckResultRepository::class);
        $healthCheckResultRepository->method('findLatestPerUrlAndKind')->willReturn([$pagespeedResult, $securityHeadersResult, $sslCertificateResult, $deploymentResult]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with(
                '@c975LConfig/management/health_check/index.html.twig',
                [
                    'results' => [$pagespeedResult],
                    'kinds' => ['pagespeed'],
                    'siteResults' => [$securityHeadersResult, $sslCertificateResult, $deploymentResult],
                    'siteKinds' => ['security-headers', 'ssl-certificate', 'deployment'],
                    'alerts' => [],
                    'trendChart' => null,
                    'lastCheckedAt' => $pagespeedResult->getCheckedAt(),
                    'advice' => [],
                ],
            )
            ->willReturn('<html></html>');

        $controller = new HealthCheckController(
            $healthCheckResultRepository,
            $this->createStub(HealthCheckRunner::class),
            $this->createAlertBuilder([]),
            $this->createAdviceBuilder(),
            $this->createTableExporter(),
            $this->createTrendChartBuilder(),
            $this->createConfigService(),
            $this->createTranslator(),
        );
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'twig' => $twig,
        ]));

        $controller->index();
    }

    public function testIndexPassesNullLastCheckedAtWhenThereAreNoResults(): void
    {
        $healthCheckResultRepository = $this->createStub(HealthCheckResultRepository::class);
        $healthCheckResultRepository->method('findLatestPerUrlAndKind')->willReturn([]);

        $twig = $this->createMock(Environment::class);
        $twig->expects($this->once())
            ->method('render')
            ->with(
                '@c975LConfig/management/health_check/index.html.twig',
                [
                    'results' => [],
                    'kinds' => [],
                    'siteResults' => [],
                    'siteKinds' => [],
                    'alerts' => [],
                    'trendChart' => null,
                    'lastCheckedAt' => null,
                    'advice' => [],
                ],
            )
            ->willReturn('<html></html>');

        $controller = new HealthCheckController(
            $healthCheckResultRepository,
            $this->createStub(HealthCheckRunner::class),
            $this->createAlertBuilder([]),
            $this->createAdviceBuilder(),
            $this->createTableExporter(),
            $this->createTrendChartBuilder(),
            $this->createConfigService(),
            $this->createTranslator(),
        );
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'twig' => $twig,
        ]));

        $controller->index();
    }

    public function testIndexDeniesAccessWhenNotGranted(): void
    {
        $this->expectException(AccessDeniedException::class);

        $controller = new HealthCheckController(
            $this->createStub(HealthCheckResultRepository::class),
            $this->createStub(HealthCheckRunner::class),
            $this->createAlertBuilder(),
            $this->createAdviceBuilder(),
            $this->createTableExporter(),
            $this->createTrendChartBuilder(),
            $this->createConfigService(),
            $this->createTranslator(),
        );
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(false),
        ]));

        $controller->index();
    }

    public function testRunCallsHealthCheckRunnerAndAddsFlashWhenTokenIsValid(): void
    {
        $healthCheckRunner = $this->createMock(HealthCheckRunner::class);
        $healthCheckRunner->expects($this->once())->method('run')->willReturn(['pagespeed' => 3]);

        $controller = new HealthCheckController(
            $this->createStub(HealthCheckResultRepository::class),
            $healthCheckRunner,
            $this->createAlertBuilder(),
            $this->createAdviceBuilder(),
            $this->createTableExporter(),
            $this->createTrendChartBuilder(),
            $this->createConfigService(),
            $this->createTranslator(),
        );
        [$requestStack, $session] = $this->createRequestStackWithSession();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(true),
            'router' => $this->createRouter(),
            'request_stack' => $requestStack,
        ]));

        $response = $controller->run(new Request([], ['_token' => 'valid-token']));

        $this->assertSame(['flash.health_check_run_success'], $session->getFlashBag()->get('success'));
        $this->assertSame('/management', $response->getTargetUrl());
    }

    public function testRunAddsAnErrorFlashAndSkipsTheRunWhenCsrfTokenIsInvalid(): void
    {
        $healthCheckRunner = $this->createMock(HealthCheckRunner::class);
        $healthCheckRunner->expects($this->never())->method('run');

        $controller = new HealthCheckController(
            $this->createStub(HealthCheckResultRepository::class),
            $healthCheckRunner,
            $this->createAlertBuilder(),
            $this->createAdviceBuilder(),
            $this->createTableExporter(),
            $this->createTrendChartBuilder(),
            $this->createConfigService(),
            $this->createTranslator(),
        );
        [$requestStack, $session] = $this->createRequestStackWithSession();
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
            'security.csrf.token_manager' => $this->createCsrfTokenManager(false),
            'router' => $this->createRouter(),
            'request_stack' => $requestStack,
        ]));

        $response = $controller->run(new Request([], ['_token' => 'invalid-token']));

        $this->assertSame(['flash.health_check_run_invalid_token'], $session->getFlashBag()->get('danger'));
        $this->assertSame('/management', $response->getTargetUrl());
    }

    public function testRunDeniesAccessWhenNotGranted(): void
    {
        $this->expectException(AccessDeniedException::class);

        $controller = new HealthCheckController(
            $this->createStub(HealthCheckResultRepository::class),
            $this->createStub(HealthCheckRunner::class),
            $this->createAlertBuilder(),
            $this->createAdviceBuilder(),
            $this->createTableExporter(),
            $this->createTrendChartBuilder(),
            $this->createConfigService(),
            $this->createTranslator(),
        );
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(false),
        ]));

        $controller->run(new Request([], ['_token' => 'valid-token']));
    }

    public function testExportCsvMapsResultsAndDelegatesToTheTableExporter(): void
    {
        $result = (new HealthCheckResult())
            ->setKind('pagespeed')
            ->setUrl('https://example.com/')
            ->setLabel('Home')
            ->setStatus(HealthCheckResult::STATUS_OK)
            ->setSummary('Perf 95')
            ->setCheckedAt(new \DateTime('2026-07-24 10:00:00'));

        $healthCheckResultRepository = $this->createStub(HealthCheckResultRepository::class);
        $healthCheckResultRepository->method('findLatestPerUrlAndKind')->willReturn([$result]);

        $expectedResponse = new Response('csv-content');
        $tableExporter = $this->createMock(TableExporter::class);
        $tableExporter->expects($this->once())
            ->method('export')
            ->with(ExportFormat::Csv, 'health_check', [[
                'kind' => 'pagespeed',
                'url' => 'https://example.com/',
                'label' => 'Home',
                'status' => HealthCheckResult::STATUS_OK,
                'summary' => 'Perf 95',
                'checkedAt' => '2026-07-24 10:00:00',
            ]])
            ->willReturn($expectedResponse);

        $controller = new HealthCheckController(
            $healthCheckResultRepository,
            $this->createStub(HealthCheckRunner::class),
            $this->createAlertBuilder(),
            $this->createAdviceBuilder(),
            $tableExporter,
            $this->createTrendChartBuilder(),
            $this->createConfigService(),
            $this->createTranslator(),
        );
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(true),
        ]));

        $this->assertSame($expectedResponse, $controller->exportCsv());
    }

    public function testExportCsvDeniesAccessWhenNotGranted(): void
    {
        $this->expectException(AccessDeniedException::class);

        $controller = new HealthCheckController(
            $this->createStub(HealthCheckResultRepository::class),
            $this->createStub(HealthCheckRunner::class),
            $this->createAlertBuilder(),
            $this->createAdviceBuilder(),
            $this->createTableExporter(),
            $this->createTrendChartBuilder(),
            $this->createConfigService(),
            $this->createTranslator(),
        );
        $controller->setContainer($this->createContainer([
            'security.authorization_checker' => $this->createAuthorizationChecker(false),
        ]));

        $controller->exportCsv();
    }
}
