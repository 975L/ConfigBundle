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
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Management\SeoFilesHealthCheckProvider;
use c975L\ConfigBundle\Service\SeoFilesClient;
use c975L\ConfigBundle\Service\SiteUrlResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class SeoFilesHealthCheckProviderTest extends TestCase
{
    private const VALID_SITEMAP = '<?xml version="1.0"?><urlset><url><loc>https://example.com/</loc></url></urlset>';
    private const VALID_SITEMAP_INDEX = '<?xml version="1.0"?><sitemapindex><sitemap><loc>https://example.com/sitemap-page.xml</loc></sitemap><sitemap><loc>https://example.com/sitemap-book.xml</loc></sitemap></sitemapindex>';
    private const EMPTY_SITEMAP = '<?xml version="1.0"?><urlset></urlset>';
    private const EMPTY_SITEMAP_INDEX = '<?xml version="1.0"?><sitemapindex></sitemapindex>';
    private const OPEN_ROBOTS = "User-agent: *\nDisallow:\n";
    private const BLOCKING_ROBOTS = "User-agent: *\nDisallow: /\n";
    private const PARTIAL_DISALLOW_ROBOTS = "User-agent: *\nDisallow: /admin/\n";
    private const SCOPED_DISALLOW_ROBOTS = "User-agent: SomeBot\nDisallow: /\n\nUser-agent: *\nDisallow:\n";

    // A real resolver over a stubbed config, so the trailing-slash normalisation the provider relies on is exercised rather than stubbed away
    private function createSiteUrlResolver(?string $siteUrl): SiteUrlResolver
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn($siteUrl);

        return new SiteUrlResolver($configService);
    }

    private function createClient(array $responses): SeoFilesClient
    {
        $client = $this->createStub(SeoFilesClient::class);
        $client->method('fetch')->willReturnMap($responses);

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

    public function testGetKindReturnsSeoFiles(): void
    {
        $provider = new SeoFilesHealthCheckProvider($this->createSiteUrlResolver(null), $this->createClient([]), $this->createTranslator());

        $this->assertSame('seo-files', $provider->getKind());
    }

    public function testRunChecksReturnsEmptyArrayWithoutASiteUrl(): void
    {
        $provider = new SeoFilesHealthCheckProvider($this->createSiteUrlResolver(null), $this->createClient([]), $this->createTranslator());

        $this->assertSame([], $provider->runChecks());
    }

    public function testRunChecksReturnsOneRowEachForRobotsAndSitemapWhenBothAreFine(): void
    {
        $client = $this->createClient([
            ['https://example.com/robots.txt', ['statusCode' => 200, 'content' => self::OPEN_ROBOTS]],
            ['https://example.com/sitemap-site.xml', ['statusCode' => 200, 'content' => self::VALID_SITEMAP]],
            ['https://example.com/sitemap-index.xml', ['statusCode' => 404, 'content' => '']],
        ]);

        $provider = new SeoFilesHealthCheckProvider($this->createSiteUrlResolver('https://example.com'), $client, $this->createTranslator());
        $results = $provider->runChecks();

        $this->assertCount(2, $results);
        $this->assertSame(HealthCheckResult::STATUS_OK, $results[0]['status']);
        $this->assertSame('https://example.com/robots.txt', $results[0]['url']);
        $this->assertSame(HealthCheckResult::STATUS_OK, $results[1]['status']);
        $this->assertSame('https://example.com/sitemap-site.xml', $results[1]['url']);
    }

    // A "site-url" saved with its trailing slash used to produce "https://example.com//robots.txt", which answers 404 and reports a perfectly deployed file as missing
    public function testRunChecksDoesNotDoubleTheSlashOfASiteUrlSavedWithOne(): void
    {
        $client = $this->createClient([
            ['https://example.com/robots.txt', ['statusCode' => 200, 'content' => self::OPEN_ROBOTS]],
            ['https://example.com/sitemap-site.xml', ['statusCode' => 200, 'content' => self::VALID_SITEMAP]],
            ['https://example.com/sitemap-index.xml', ['statusCode' => 404, 'content' => '']],
        ]);

        $provider = new SeoFilesHealthCheckProvider($this->createSiteUrlResolver('https://example.com/'), $client, $this->createTranslator());
        $results = $provider->runChecks();

        $this->assertSame('https://example.com/robots.txt', $results[0]['url']);
        $this->assertSame(HealthCheckResult::STATUS_OK, $results[0]['status']);
    }

    public function testRunChecksStatusIsErrorWhenRobotsIsMissing(): void
    {
        $client = $this->createClient([
            ['https://example.com/robots.txt', ['statusCode' => 404, 'content' => '']],
            ['https://example.com/sitemap-site.xml', ['statusCode' => 200, 'content' => self::VALID_SITEMAP]],
            ['https://example.com/sitemap-index.xml', ['statusCode' => 404, 'content' => '']],
        ]);

        $provider = new SeoFilesHealthCheckProvider($this->createSiteUrlResolver('https://example.com'), $client, $this->createTranslator());

        $this->assertSame(HealthCheckResult::STATUS_ERROR, $provider->runChecks()[0]['status']);
    }

    public function testRunChecksStatusIsErrorWhenRobotsIsEmpty(): void
    {
        $client = $this->createClient([
            ['https://example.com/robots.txt', ['statusCode' => 200, 'content' => '   ']],
            ['https://example.com/sitemap-site.xml', ['statusCode' => 200, 'content' => self::VALID_SITEMAP]],
            ['https://example.com/sitemap-index.xml', ['statusCode' => 404, 'content' => '']],
        ]);

        $provider = new SeoFilesHealthCheckProvider($this->createSiteUrlResolver('https://example.com'), $client, $this->createTranslator());

        $this->assertSame(HealthCheckResult::STATUS_ERROR, $provider->runChecks()[0]['status']);
    }

    public function testRunChecksStatusIsWarningWhenRobotsBlocksEverything(): void
    {
        $client = $this->createClient([
            ['https://example.com/robots.txt', ['statusCode' => 200, 'content' => self::BLOCKING_ROBOTS]],
            ['https://example.com/sitemap-site.xml', ['statusCode' => 200, 'content' => self::VALID_SITEMAP]],
            ['https://example.com/sitemap-index.xml', ['statusCode' => 404, 'content' => '']],
        ]);

        $provider = new SeoFilesHealthCheckProvider($this->createSiteUrlResolver('https://example.com'), $client, $this->createTranslator());

        $this->assertSame(HealthCheckResult::STATUS_WARNING, $provider->runChecks()[0]['status']);
    }

    public function testRunChecksDoesNotFlagAPartialDisallow(): void
    {
        $client = $this->createClient([
            ['https://example.com/robots.txt', ['statusCode' => 200, 'content' => self::PARTIAL_DISALLOW_ROBOTS]],
            ['https://example.com/sitemap-site.xml', ['statusCode' => 200, 'content' => self::VALID_SITEMAP]],
            ['https://example.com/sitemap-index.xml', ['statusCode' => 404, 'content' => '']],
        ]);

        $provider = new SeoFilesHealthCheckProvider($this->createSiteUrlResolver('https://example.com'), $client, $this->createTranslator());

        $this->assertSame(HealthCheckResult::STATUS_OK, $provider->runChecks()[0]['status']);
    }

    public function testRunChecksDoesNotFlagADisallowScopedToAnotherAgent(): void
    {
        $client = $this->createClient([
            ['https://example.com/robots.txt', ['statusCode' => 200, 'content' => self::SCOPED_DISALLOW_ROBOTS]],
            ['https://example.com/sitemap-site.xml', ['statusCode' => 200, 'content' => self::VALID_SITEMAP]],
            ['https://example.com/sitemap-index.xml', ['statusCode' => 404, 'content' => '']],
        ]);

        $provider = new SeoFilesHealthCheckProvider($this->createSiteUrlResolver('https://example.com'), $client, $this->createTranslator());

        $this->assertSame(HealthCheckResult::STATUS_OK, $provider->runChecks()[0]['status']);
    }

    public function testRunChecksStatusIsErrorWhenSitemapIsMissing(): void
    {
        $client = $this->createClient([
            ['https://example.com/robots.txt', ['statusCode' => 200, 'content' => self::OPEN_ROBOTS]],
            ['https://example.com/sitemap-site.xml', ['statusCode' => 404, 'content' => '']],
            ['https://example.com/sitemap-index.xml', ['statusCode' => 404, 'content' => '']],
        ]);

        $provider = new SeoFilesHealthCheckProvider($this->createSiteUrlResolver('https://example.com'), $client, $this->createTranslator());

        $this->assertSame(HealthCheckResult::STATUS_ERROR, $provider->runChecks()[1]['status']);
    }

    public function testRunChecksStatusIsErrorWhenSitemapIsNotValidXml(): void
    {
        $client = $this->createClient([
            ['https://example.com/robots.txt', ['statusCode' => 200, 'content' => self::OPEN_ROBOTS]],
            ['https://example.com/sitemap-site.xml', ['statusCode' => 200, 'content' => '<html>Not Found</html>']],
            ['https://example.com/sitemap-index.xml', ['statusCode' => 404, 'content' => '']],
        ]);

        $provider = new SeoFilesHealthCheckProvider($this->createSiteUrlResolver('https://example.com'), $client, $this->createTranslator());

        $this->assertSame(HealthCheckResult::STATUS_ERROR, $provider->runChecks()[1]['status']);
    }

    private function runSitemapCheck(string $content, ?\DateTimeImmutable $lastModified = null): array
    {
        $client = $this->createClient([
            ['https://example.com/robots.txt', ['statusCode' => 200, 'content' => self::OPEN_ROBOTS, 'lastModified' => null]],
            ['https://example.com/sitemap-site.xml', ['statusCode' => 200, 'content' => $content, 'lastModified' => $lastModified]],
            ['https://example.com/sitemap-index.xml', ['statusCode' => 404, 'content' => '', 'lastModified' => null]],
        ]);

        $provider = new SeoFilesHealthCheckProvider($this->createSiteUrlResolver('https://example.com'), $client, $this->createTranslator());

        return $provider->runChecks()[1];
    }

    // When the sitemap file itself was last rewritten, the only thing telling the freshness checks apart
    private function writtenDaysAgo(int $daysAgo): \DateTimeImmutable
    {
        return new \DateTimeImmutable('-' . $daysAgo . ' days');
    }

    // Well-formed XML declaring nothing at all - Search Console reports "0 page discovered" for it without a single error, which is exactly what c975l:sitemaps:create never running on a deployment leaves behind
    public function testRunChecksStatusIsWarningWhenTheSitemapDeclaresNoUrl(): void
    {
        $result = $this->runSitemapCheck(self::EMPTY_SITEMAP);

        $this->assertSame(HealthCheckResult::STATUS_WARNING, $result['status']);
        $this->assertSame('label.health_check_sitemap_empty', $result['summary']);
    }

    // Nothing regenerated the file in a month, on a site whose sitemaps are documented as rebuilt weekly
    public function testRunChecksStatusIsWarningWhenTheSitemapFileHasNotBeenRewrittenForLong(): void
    {
        $result = $this->runSitemapCheck(self::VALID_SITEMAP, $this->writtenDaysAgo(90));

        $this->assertSame(HealthCheckResult::STATUS_WARNING, $result['status']);
        $this->assertSame('label.health_check_sitemap_stale', $result['summary']);
    }

    public function testRunChecksStatusIsOkWhenTheSitemapFileWasRewrittenRecently(): void
    {
        $result = $this->runSitemapCheck(self::VALID_SITEMAP, $this->writtenDaysAgo(2));

        $this->assertSame(HealthCheckResult::STATUS_OK, $result['status']);
        $this->assertSame('label.health_check_sitemap_ok_urls', $result['summary']);
    }

    // A response carrying no Last-Modified header says nothing about the file's freshness - not the same as it being stale
    public function testRunChecksStatusIsOkWhenTheResponseCarriesNoLastModified(): void
    {
        $this->assertSame(HealthCheckResult::STATUS_OK, $this->runSitemapCheck(self::VALID_SITEMAP)['status']);
    }

    // The whole point of reading the file's own date: a site whose content is simply stable declares months-old <lastmod>s while the command keeps regenerating the file faithfully, and used to be called stale for it
    public function testRunChecksStatusIsOkWhenTheFileIsFreshButItsLastmodsAreOld(): void
    {
        $oldDate = $this->writtenDaysAgo(90)->format('Y-m-d');
        $content = '<?xml version="1.0"?><urlset><url><loc>https://example.com/</loc><lastmod>' . $oldDate . '</lastmod></url></urlset>';

        $this->assertSame(HealthCheckResult::STATUS_OK, $this->runSitemapCheck($content, $this->writtenDaysAgo(1))['status']);
    }

    public function testRunChecksReturnsAnErrorRowWhenTheCallFails(): void
    {
        $client = $this->createStub(SeoFilesClient::class);
        $client->method('fetch')->willThrowException(new \RuntimeException('Connection refused'));

        $provider = new SeoFilesHealthCheckProvider($this->createSiteUrlResolver('https://example.com'), $client, $this->createTranslator());

        $results = $provider->runChecks();
        $this->assertSame(HealthCheckResult::STATUS_ERROR, $results[0]['status']);
        $this->assertSame(HealthCheckResult::STATUS_ERROR, $results[1]['status']);
    }

    public function testRunChecksAddsAnIndexRowPlusOneRowPerChildSitemapWhenAllAreFine(): void
    {
        $client = $this->createClient([
            ['https://example.com/robots.txt', ['statusCode' => 200, 'content' => self::OPEN_ROBOTS]],
            ['https://example.com/sitemap-site.xml', ['statusCode' => 200, 'content' => self::VALID_SITEMAP]],
            ['https://example.com/sitemap-index.xml', ['statusCode' => 200, 'content' => self::VALID_SITEMAP_INDEX]],
            ['https://example.com/sitemap-page.xml', ['statusCode' => 200, 'content' => self::VALID_SITEMAP]],
            ['https://example.com/sitemap-book.xml', ['statusCode' => 200, 'content' => self::VALID_SITEMAP]],
        ]);

        $provider = new SeoFilesHealthCheckProvider($this->createSiteUrlResolver('https://example.com'), $client, $this->createTranslator());
        $results = $provider->runChecks();

        $this->assertCount(5, $results);
        $this->assertSame(HealthCheckResult::STATUS_OK, $results[2]['status']);
        $this->assertSame('https://example.com/sitemap-index.xml', $results[2]['url']);
        $this->assertSame(HealthCheckResult::STATUS_OK, $results[3]['status']);
        $this->assertSame('https://example.com/sitemap-page.xml', $results[3]['url']);
        $this->assertSame('sitemap-page.xml', $results[3]['label']);
        $this->assertSame(HealthCheckResult::STATUS_OK, $results[4]['status']);
        $this->assertSame('https://example.com/sitemap-book.xml', $results[4]['url']);
        $this->assertSame('sitemap-book.xml', $results[4]['label']);
    }

    public function testRunChecksDoesNotAddAnyRowWhenSitemapIndexIsMissing(): void
    {
        $client = $this->createClient([
            ['https://example.com/robots.txt', ['statusCode' => 200, 'content' => self::OPEN_ROBOTS]],
            ['https://example.com/sitemap-site.xml', ['statusCode' => 200, 'content' => self::VALID_SITEMAP]],
            ['https://example.com/sitemap-index.xml', ['statusCode' => 404, 'content' => '']],
        ]);

        $provider = new SeoFilesHealthCheckProvider($this->createSiteUrlResolver('https://example.com'), $client, $this->createTranslator());

        $this->assertCount(2, $provider->runChecks());
    }

    public function testRunChecksStatusIsErrorWhenSitemapIndexIsNotValidXml(): void
    {
        $client = $this->createClient([
            ['https://example.com/robots.txt', ['statusCode' => 200, 'content' => self::OPEN_ROBOTS]],
            ['https://example.com/sitemap-site.xml', ['statusCode' => 200, 'content' => self::VALID_SITEMAP]],
            ['https://example.com/sitemap-index.xml', ['statusCode' => 200, 'content' => '<html>Not Found</html>']],
        ]);

        $provider = new SeoFilesHealthCheckProvider($this->createSiteUrlResolver('https://example.com'), $client, $this->createTranslator());
        $results = $provider->runChecks();

        $this->assertCount(3, $results);
        $this->assertSame(HealthCheckResult::STATUS_ERROR, $results[2]['status']);
    }

    public function testRunChecksStatusIsWarningWhenSitemapIndexHasNoEntries(): void
    {
        $client = $this->createClient([
            ['https://example.com/robots.txt', ['statusCode' => 200, 'content' => self::OPEN_ROBOTS]],
            ['https://example.com/sitemap-site.xml', ['statusCode' => 200, 'content' => self::VALID_SITEMAP]],
            ['https://example.com/sitemap-index.xml', ['statusCode' => 200, 'content' => self::EMPTY_SITEMAP_INDEX]],
        ]);

        $provider = new SeoFilesHealthCheckProvider($this->createSiteUrlResolver('https://example.com'), $client, $this->createTranslator());
        $results = $provider->runChecks();

        $this->assertCount(3, $results);
        $this->assertSame(HealthCheckResult::STATUS_WARNING, $results[2]['status']);
    }

    public function testRunChecksOnlyFlagsTheOneChildSitemapThatIsUnreachable(): void
    {
        $client = $this->createClient([
            ['https://example.com/robots.txt', ['statusCode' => 200, 'content' => self::OPEN_ROBOTS]],
            ['https://example.com/sitemap-site.xml', ['statusCode' => 200, 'content' => self::VALID_SITEMAP]],
            ['https://example.com/sitemap-index.xml', ['statusCode' => 200, 'content' => self::VALID_SITEMAP_INDEX]],
            ['https://example.com/sitemap-page.xml', ['statusCode' => 404, 'content' => '']],
            ['https://example.com/sitemap-book.xml', ['statusCode' => 200, 'content' => self::VALID_SITEMAP]],
        ]);

        $provider = new SeoFilesHealthCheckProvider($this->createSiteUrlResolver('https://example.com'), $client, $this->createTranslator());
        $results = $provider->runChecks();

        $this->assertCount(5, $results);
        $this->assertSame(HealthCheckResult::STATUS_OK, $results[2]['status']);
        $this->assertSame(HealthCheckResult::STATUS_ERROR, $results[3]['status']);
        $this->assertSame('https://example.com/sitemap-page.xml', $results[3]['url']);
        $this->assertSame(HealthCheckResult::STATUS_OK, $results[4]['status']);
    }

    public function testRunChecksStatusIsErrorWhenAChildSitemapIsNotValidXml(): void
    {
        $client = $this->createClient([
            ['https://example.com/robots.txt', ['statusCode' => 200, 'content' => self::OPEN_ROBOTS]],
            ['https://example.com/sitemap-site.xml', ['statusCode' => 200, 'content' => self::VALID_SITEMAP]],
            ['https://example.com/sitemap-index.xml', ['statusCode' => 200, 'content' => self::VALID_SITEMAP_INDEX]],
            ['https://example.com/sitemap-page.xml', ['statusCode' => 200, 'content' => '<html>Not Found</html>']],
            ['https://example.com/sitemap-book.xml', ['statusCode' => 200, 'content' => self::VALID_SITEMAP]],
        ]);

        $provider = new SeoFilesHealthCheckProvider($this->createSiteUrlResolver('https://example.com'), $client, $this->createTranslator());
        $results = $provider->runChecks();

        $this->assertSame(HealthCheckResult::STATUS_ERROR, $results[3]['status']);
    }
}
