<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Attribute\AsHealthCheck;
use c975L\ConfigBundle\Entity\HealthCheckResult;
use c975L\ConfigBundle\Management\ContentQualityAnalyzer;
use c975L\ConfigBundle\Management\DeclaredUrlsHealthCheckProvider;
use c975L\ConfigBundle\Management\ContentOffenceLocatorRegistry;
use c975L\ConfigBundle\Management\SitemapProviderInterface;
use c975L\ConfigBundle\Service\ContentQualityClient;
use c975L\ConfigBundle\Service\UrlStatusChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class DeclaredUrlsHealthCheckProviderTest extends TestCase
{
    private const GOOD_ANALYSIS = [
        'title' => 'Un titre de livre tout à fait correct',
        'description' => 'Une description de test suffisamment longue pour passer le seuil minimal recommandé.',
        'hasDescription' => true,
        'h1Count' => 1,
        'imagesWithoutAlt' => [],
        'socialTags' => ['og:title' => 'T', 'og:description' => 'D', 'og:image' => '/media/og.png'],
        'internalLinks' => [],
        'externalLinks' => [],
        'linkTexts' => [],
    ];

    private function createSitemapProvider(string $name, array $urls): SitemapProviderInterface
    {
        $provider = $this->createStub(SitemapProviderInterface::class);
        $provider->method('getSitemapName')->willReturn($name);
        $provider->method('getUrls')->willReturn($urls);

        return $provider;
    }

    private function createAnalyzer(?array $analysis = null, ?int $status = null, ?UrlStatusChecker $checker = null): ContentQualityAnalyzer
    {
        return new ContentQualityAnalyzer(
            $this->createContentQualityClient($analysis, $status),
            $checker ?? $this->createStub(UrlStatusChecker::class),
            $this->createStub(ContentOffenceLocatorRegistry::class),
            $this->createTranslator(),
        );
    }

    // $status >= 400 stands for a url that isn't there: the analysis response answers it, and reading it throws, exactly as HttpClient does on a 4xx
    private function createContentQualityClient(?array $analysis, ?int $status): ContentQualityClient
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status ?? 200);

        $client = $this->createStub(ContentQualityClient::class);
        $client->method('request')->willReturn($response);
        if (null !== $status && $status >= 400) {
            $client->method('read')->willThrowException(new \RuntimeException('HTTP ' . $status . ' returned for the analysis request'));
        } else {
            $client->method('read')->willReturn($analysis ?? self::GOOD_ANALYSIS);
        }

        return $client;
    }

    // Each label rendered as its own translation id with the parameters substituted, so a test asserts on what the row really says rather than on a stub's canned string
    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            fn (string $id, array $parameters = []) => strtr($id, $parameters)
        );

        return $translator;
    }

    private function url(string $loc): array
    {
        return ['loc' => $loc, 'lastmod' => '2026-07-27', 'changefreq' => 'weekly', 'priority' => 8];
    }

    // One kind per bundle, so a gallery declaring two thousand photos can be scheduled apart from a handful of books
    public function testGetKindIsDerivedFromTheSitemapName(): void
    {
        $provider = new DeclaredUrlsHealthCheckProvider($this->createSitemapProvider('gallery', []), $this->createAnalyzer());

        $this->assertSame('urls-gallery', $provider->getKind());
    }

    public function testRunChecksReturnsNoRowWhenTheBundleDeclaresNothing(): void
    {
        $provider = new DeclaredUrlsHealthCheckProvider($this->createSitemapProvider('book', []), $this->createAnalyzer());

        $this->assertSame([], $provider->runChecks());
    }

    public function testRunChecksReturnsOneRowPerDeclaredUrl(): void
    {
        $provider = new DeclaredUrlsHealthCheckProvider(
            $this->createSitemapProvider('book', [$this->url('https://example.com/livres'), $this->url('https://example.com/livre/mon-livre')]),
            $this->createAnalyzer(),
        );

        $results = $provider->runChecks();

        $this->assertCount(2, $results);
        $this->assertSame('https://example.com/livres', $results[0]['url']);
        $this->assertSame(HealthCheckResult::STATUS_OK, $results[0]['status']);
    }

    // The url's own path tells two rows of the same bundle apart on the dashboard, where the full url is already shown
    public function testRunChecksLabelsEachRowWithItsPath(): void
    {
        $provider = new DeclaredUrlsHealthCheckProvider(
            $this->createSitemapProvider('book', [$this->url('https://example.com/livre/mon-livre'), $this->url('https://example.com/')]),
            $this->createAnalyzer(),
        );

        $results = $provider->runChecks();

        $this->assertSame('livre/mon-livre', $results[0]['label']);
        // Nothing left of the root url to label it with, so it stands for itself
        $this->assertSame('https://example.com/', $results[1]['label']);
    }

    // No Page and no admin screen behind a declared url
    public function testRunChecksLeavesTheEditUrlEmpty(): void
    {
        $provider = new DeclaredUrlsHealthCheckProvider(
            $this->createSitemapProvider('shop', [$this->url('https://example.com/shop/products/un-produit')]),
            $this->createAnalyzer(),
        );

        $this->assertNull($provider->runChecks()[0]['editUrl']);
    }

    // The same checks as content-quality, since it's the same analyzer behind both
    public function testRunChecksAppliesTheContentQualityChecks(): void
    {
        $provider = new DeclaredUrlsHealthCheckProvider(
            $this->createSitemapProvider('gallery', [$this->url('https://example.com/photos/nature/12')]),
            $this->createAnalyzer(['title' => 'Photo', 'hasDescription' => false] + self::GOOD_ANALYSIS),
        );

        $result = $provider->runChecks()[0];

        $this->assertSame(HealthCheckResult::STATUS_WARNING, $result['status']);
        $this->assertStringContainsString('label.health_check_content_quality_title_too_short', $result['summary']);
        $this->assertStringContainsString('label.health_check_content_quality_no_description', $result['summary']);
    }

    // Unlike a Page (which exists in database whether or not it's deployed), a declared url answering 404 is the declaring bundle's own defect - it's advertising a resource that isn't there, which is exactly what these checks exist to surface
    public function testRunChecksReportsADeclaredUrlAnsweringNotFoundAsAnError(): void
    {
        $provider = new DeclaredUrlsHealthCheckProvider(
            $this->createSitemapProvider('book', [$this->url('https://example.com/livre/pas-encore-en-ligne')]),
            $this->createAnalyzer(status: 404),
        );

        $this->assertSame(HealthCheckResult::STATUS_ERROR, $provider->runChecks()[0]['status']);
    }

    // 410 is the site answering on purpose - nothing is broken, only the declaration is stale, so it warns rather than erroring
    public function testRunChecksWarnsOnADeclaredUrlDeliberatelyRemoved(): void
    {
        $provider = new DeclaredUrlsHealthCheckProvider(
            $this->createSitemapProvider('book', [$this->url('https://example.com/livre/retire-du-catalogue')]),
            $this->createAnalyzer(status: 410),
        );

        $result = $provider->runChecks()[0];

        $this->assertSame(HealthCheckResult::STATUS_WARNING, $result['status']);
        $this->assertSame('label.health_check_url_gone', $result['summary']);
    }

    // A url the server fails on says nothing about the url existing - reported as the failure it is, never as "not tested"
    public function testRunChecksReportsAFailingUrlAsAnError(): void
    {
        foreach ([500, 503] as $status) {
            $provider = new DeclaredUrlsHealthCheckProvider(
                $this->createSitemapProvider('book', [$this->url('https://example.com/livre/un-livre')]),
                $this->createAnalyzer(status: $status),
            );

            $this->assertSame(HealthCheckResult::STATUS_ERROR, $provider->runChecks()[0]['status'], 'HTTP ' . $status);
        }
    }

    // The statuses describing how the server treats this checker rather than whether the page is fine (see ContentQualityClient::INCONCLUSIVE_STATUSES) - a site behind a WAF would otherwise have every one of its declared urls reported as an error. A warning, not a skipped row: the run genuinely couldn't judge the page
    public function testRunChecksWarnsOnAUrlWhoseStatusIsInconclusive(): void
    {
        foreach (ContentQualityClient::INCONCLUSIVE_STATUSES as $status) {
            $provider = new DeclaredUrlsHealthCheckProvider(
                $this->createSitemapProvider('book', [$this->url('https://example.com/livre/un-livre')]),
                $this->createAnalyzer(status: $status),
            );

            $result = $provider->runChecks()[0];

            $this->assertSame(HealthCheckResult::STATUS_WARNING, $result['status'], 'HTTP ' . $status);
            $this->assertSame('label.health_check_url_inconclusive', $result['summary'], 'HTTP ' . $status);
        }
    }

    // The existence HEAD only pays off for a Page - a bundle declaring thousands of urls would see its request count doubled for a verdict its own analysis response already carries
    public function testRunChecksDoesNotFireAnExistenceCheckOfItsOwn(): void
    {
        $checker = $this->createMock(UrlStatusChecker::class);
        $checker->expects($this->never())->method('exists');

        $provider = new DeclaredUrlsHealthCheckProvider(
            $this->createSitemapProvider('gallery', [$this->url('https://example.com/photos/nature/12')]),
            $this->createAnalyzer(checker: $checker),
        );

        $provider->runChecks();
    }

    // More urls than a single batch (see ContentQualityAnalyzer): every one still gets its row, in the declared order
    public function testRunChecksKeepsEveryUrlInOrderAcrossBatches(): void
    {
        $urls = array_map(fn (int $i): array => $this->url('https://example.com/photos/' . $i), range(1, 25));
        $provider = new DeclaredUrlsHealthCheckProvider($this->createSitemapProvider('gallery', $urls), $this->createAnalyzer());

        $results = $provider->runChecks();

        $this->assertCount(25, $results);
        $this->assertSame('https://example.com/photos/1', $results[0]['url']);
        $this->assertSame('https://example.com/photos/25', $results[24]['url']);
    }

    // SitemapWriter tolerates an incomplete url, so this has to as well - one without a usable "loc" simply has nothing to check
    public function testRunChecksIgnoresAnEntryWithoutALocation(): void
    {
        $provider = new DeclaredUrlsHealthCheckProvider(
            $this->createSitemapProvider('book', [['lastmod' => '2026-07-27'], ['loc' => ''], ['loc' => 42], $this->url('https://example.com/livres')]),
            $this->createAnalyzer(),
        );

        $results = $provider->runChecks();

        $this->assertCount(1, $results);
        $this->assertSame('https://example.com/livres', $results[0]['url']);
    }

    // A bundle saying nothing about its volume is checked weekly, like the site's own pages
    public function testFrequencyDefaultsToWeekly(): void
    {
        $provider = new DeclaredUrlsHealthCheckProvider($this->createSitemapProvider('book', []), $this->createAnalyzer());

        $this->assertSame(AsHealthCheck::FREQUENCY_WEEKLY, $provider->getFrequency());
    }

    // The cadence is carried per instance, not per class: one class serves every bundle (see DeclaredUrlsHealthCheckPass)
    public function testFrequencyIsTheOneHandedOver(): void
    {
        $provider = new DeclaredUrlsHealthCheckProvider(
            $this->createSitemapProvider('gallery', []),
            $this->createAnalyzer(),
            AsHealthCheck::FREQUENCY_MONTHLY,
        );

        $this->assertSame(AsHealthCheck::FREQUENCY_MONTHLY, $provider->getFrequency());
    }
}
