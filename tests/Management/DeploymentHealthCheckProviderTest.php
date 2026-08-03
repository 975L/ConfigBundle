<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Entity\HealthCheckResult;
use c975L\ConfigBundle\Management\DeploymentHealthCheckProvider;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\DeploymentClient;
use c975L\ConfigBundle\Service\SiteUrlResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class DeploymentHealthCheckProviderTest extends TestCase
{
    private const PROBE_URL = 'https://example.com/c975l-health-check-404-probe';
    private const SITE_PAGE = '<html><head><title>Introuvable</title></head><body>Example Site</body></html>';
    private const DEFAULT_ERROR_PAGE = '<html><head><title>An Error Occurred</title></head><body>Oops!</body></html>';

    private function createConfigService(?string $siteUrl, ?string $siteName = 'Example Site'): ConfigServiceInterface
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnMap([
            ['site-url', $siteUrl],
            ['site-name', $siteName],
        ]);

        return $configService;
    }

    private function createClient(array $redirect, array $notFound): DeploymentClient
    {
        $client = $this->createStub(DeploymentClient::class);
        $client->method('fetchWithoutRedirect')->willReturn($redirect);
        $client->method('fetch')->willReturn($notFound);

        return $client;
    }

    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            fn (string $id, array $parameters = []) => strtr($id, $parameters)
        );

        return $translator;
    }

    private function createProvider(?string $siteUrl, array $redirect = [], array $notFound = [], ?string $siteName = 'Example Site'): DeploymentHealthCheckProvider
    {
        $configService = $this->createConfigService($siteUrl, $siteName);

        return new DeploymentHealthCheckProvider(
            $configService,
            new SiteUrlResolver($configService),
            $this->createClient($redirect + ['statusCode' => 301, 'location' => 'https://example.com/'], $notFound + ['statusCode' => 404, 'content' => self::SITE_PAGE]),
            $this->createTranslator(),
        );
    }

    public function testGetKindReturnsDeployment(): void
    {
        $this->assertSame('deployment', $this->createProvider(null)->getKind());
    }

    public function testRunChecksReturnsEmptyArrayWithoutASiteUrl(): void
    {
        $this->assertSame([], $this->createProvider(null)->runChecks());
    }

    public function testRunChecksReturnsOneRowEachWhenBothAreFine(): void
    {
        $results = $this->createProvider('https://example.com')->runChecks();

        $this->assertCount(2, $results);
        $this->assertSame(HealthCheckResult::STATUS_OK, $results[0]['status']);
        $this->assertSame('http://example.com/', $results[0]['url']);
        $this->assertSame(HealthCheckResult::STATUS_OK, $results[1]['status']);
        $this->assertSame(self::PROBE_URL, $results[1]['url']);
    }

    // A trailing slash on site-url must not produce a double-slashed probe url
    public function testRunChecksBuildsTheProbeUrlFromASiteUrlWithATrailingSlash(): void
    {
        $results = $this->createProvider('https://example.com/')->runChecks();

        $this->assertSame(self::PROBE_URL, $results[1]['url']);
    }

    // Nothing to redirect to on a site not served over https - only the 404 row remains
    public function testRunChecksSkipsTheHttpsRedirectOnAnHttpSite(): void
    {
        $results = $this->createProvider('http://example.com')->runChecks();

        $this->assertCount(1, $results);
        $this->assertSame('http://example.com/c975l-health-check-404-probe', $results[0]['url']);
    }

    public function testRunChecksStatusIsErrorWhenHttpDoesNotRedirect(): void
    {
        $results = $this->createProvider('https://example.com', ['statusCode' => 200, 'location' => null])->runChecks();

        $this->assertSame(HealthCheckResult::STATUS_ERROR, $results[0]['status']);
        $this->assertSame('https-redirect', $results[0]['details']['issue']);
        $this->assertSame(200, $results[0]['details']['statusCode']);
    }

    // A relative Location keeps the visitor on http just as much as an explicit http:// target does
    public function testRunChecksStatusIsWarningWhenTheRedirectStaysOnHttp(): void
    {
        $results = $this->createProvider('https://example.com', ['statusCode' => 301, 'location' => '/'])->runChecks();

        $this->assertSame(HealthCheckResult::STATUS_WARNING, $results[0]['status']);
        $this->assertSame('insecure-redirect', $results[0]['details']['issue']);
    }

    public function testRunChecksAcceptsAPermanentRedirectToHttps(): void
    {
        $results = $this->createProvider('https://example.com', ['statusCode' => 308, 'location' => 'HTTPS://example.com/'])->runChecks();

        $this->assertSame(HealthCheckResult::STATUS_OK, $results[0]['status']);
    }

    // A soft 404 has search engines index every typo as a page of its own
    public function testRunChecksStatusIsErrorWhenAnUnknownUrlAnswers200(): void
    {
        $results = $this->createProvider('https://example.com', notFound: ['statusCode' => 200, 'content' => self::SITE_PAGE])->runChecks();

        $this->assertSame(HealthCheckResult::STATUS_ERROR, $results[1]['status']);
        $this->assertSame('not-404', $results[1]['details']['issue']);
        $this->assertSame(200, $results[1]['details']['statusCode']);
    }

    public function testRunChecksStatusIsWarningWhenThe404PageIsTheFrameworkDefaultOne(): void
    {
        $results = $this->createProvider('https://example.com', notFound: ['statusCode' => 404, 'content' => self::DEFAULT_ERROR_PAGE])->runChecks();

        $this->assertSame(HealthCheckResult::STATUS_WARNING, $results[1]['status']);
        $this->assertSame('default-404', $results[1]['details']['issue']);
    }

    // The site name is matched case-insensitively - a footer shouting it in uppercase is still the site's own page
    public function testRunChecksMatchesTheSiteNameRegardlessOfCase(): void
    {
        $results = $this->createProvider('https://example.com', notFound: ['statusCode' => 404, 'content' => '<html><body>EXAMPLE SITE</body></html>'])->runChecks();

        $this->assertSame(HealthCheckResult::STATUS_OK, $results[1]['status']);
    }

    // With no site name to match against, a real 404 is taken at face value rather than warned about on a guess
    public function testRunChecksAcceptsAny404WithoutASiteName(): void
    {
        $results = $this->createProvider('https://example.com', notFound: ['statusCode' => 404, 'content' => self::DEFAULT_ERROR_PAGE], siteName: null)->runChecks();

        $this->assertSame(HealthCheckResult::STATUS_OK, $results[1]['status']);
    }

    public function testRunChecksReturnsAnErrorRowWhenTheCallFails(): void
    {
        $client = $this->createStub(DeploymentClient::class);
        $client->method('fetchWithoutRedirect')->willThrowException(new \RuntimeException('Connection refused'));
        $client->method('fetch')->willThrowException(new \RuntimeException('Connection refused'));

        $configService = $this->createConfigService('https://example.com');
        $provider = new DeploymentHealthCheckProvider($configService, new SiteUrlResolver($configService), $client, $this->createTranslator());
        $results = $provider->runChecks();

        $this->assertSame(HealthCheckResult::STATUS_ERROR, $results[0]['status']);
        $this->assertSame(['error' => 'Connection refused'], $results[0]['details']);
        $this->assertSame(HealthCheckResult::STATUS_ERROR, $results[1]['status']);
    }
}
