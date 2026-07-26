<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\SitemapProviderInterface;
use c975L\ConfigBundle\Management\SitemapWriter;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

class SitemapWriterTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/c975l-sitemap-writer-test-' . uniqid();
        mkdir($this->projectDir . '/public', 0775, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->projectDir . '/public/*') ?: []);
        @rmdir($this->projectDir . '/public');
        @rmdir($this->projectDir);
    }

    private function createConfigService(string $urlRoot = 'https://example.com'): ConfigServiceInterface
    {
        $service = $this->createStub(ConfigServiceInterface::class);
        $service->method('get')->willReturn($urlRoot);

        return $service;
    }

    // Renders the variables it's given as plain text, so each written file identifies the template and data it came from
    private function createEnvironment(): Environment
    {
        $environment = $this->createStub(Environment::class);
        $environment->method('render')->willReturnCallback(
            fn (string $template, array $context): string => $template . ':' . json_encode($context)
        );

        return $environment;
    }

    /** @param SitemapProviderInterface[] $providers */
    private function createWriter(array $providers, string $urlRoot = 'https://example.com'): SitemapWriter
    {
        return new SitemapWriter($this->createConfigService($urlRoot), $this->createEnvironment(), $providers, $this->projectDir);
    }

    // A contributing bundle, reduced to what the contract asks of it: a name and a list of urls
    private function createProvider(string $name, array $locs): SitemapProviderInterface
    {
        $provider = $this->createStub(SitemapProviderInterface::class);
        $provider->method('getSitemapName')->willReturn($name);
        $provider->method('getUrls')->willReturn(array_map(
            fn (string $loc): array => ['loc' => $loc, 'lastmod' => '2026-01-15', 'changefreq' => 'weekly', 'priority' => 4],
            $locs
        ));

        return $provider;
    }

    // A provider returning its urls as-is, to check what the writer hands over to the template
    private function createRawProvider(string $name, array $urls): SitemapProviderInterface
    {
        $provider = $this->createStub(SitemapProviderInterface::class);
        $provider->method('getSitemapName')->willReturn($name);
        $provider->method('getUrls')->willReturn($urls);

        return $provider;
    }

    // The context the stubbed Environment was given for a written sitemap, decoded back from the file
    private function renderedUrls(string $name): array
    {
        $content = file_get_contents($this->projectDir . '/public/sitemap-' . $name . '.xml');

        return json_decode(explode(':', $content, 2)[1], true)['urls'];
    }

    // Each provider gets its own file, named after the name it declares - the whole point of the contract: a bundle only supplies urls, ConfigBundle writes the files
    public function testWriteCreatesOneFilePerProvider(): void
    {
        $writer = $this->createWriter([
            $this->createProvider('site', ['https://example.com/pages/about']),
            $this->createProvider('book', ['https://example.com/livre/tome-1']),
        ]);

        $names = $writer->write();

        $this->assertSame(['site', 'book'], $names);
        $this->assertFileExists($this->projectDir . '/public/sitemap-site.xml');
        $this->assertFileExists($this->projectDir . '/public/sitemap-book.xml');
        $this->assertStringContainsString('@c975LConfig/sitemaps/sitemap.xml.twig', file_get_contents($this->projectDir . '/public/sitemap-site.xml'));
        $this->assertStringContainsString('tome-1', file_get_contents($this->projectDir . '/public/sitemap-book.xml'));
    }

    // The index declares every sub-sitemap just written, as absolute urls
    public function testWriteCreatesTheIndexDeclaringEveryWrittenSitemap(): void
    {
        $writer = $this->createWriter([
            $this->createProvider('site', ['https://example.com/pages/about']),
            $this->createProvider('shop', ['https://example.com/shop']),
        ]);

        $writer->write();

        $index = file_get_contents($this->projectDir . '/public/sitemap-index.xml');
        $this->assertStringContainsString('@c975LConfig/sitemaps/sitemap-index.xml.twig', $index);
        $this->assertStringContainsString('https:\/\/example.com\/sitemap-site.xml', $index);
        $this->assertStringContainsString('https:\/\/example.com\/sitemap-shop.xml', $index);
    }

    // A trailing slash on "site-url" must not produce a double slash in the declared urls
    public function testWriteIndexTrimsTrailingSlashFromSiteUrl(): void
    {
        $writer = $this->createWriter([$this->createProvider('site', ['https://example.com/pages/about'])], 'https://example.com/');

        $writer->write();

        $this->assertStringContainsString('https:\/\/example.com\/sitemap-site.xml', file_get_contents($this->projectDir . '/public/sitemap-index.xml'));
    }

    // A bundle installed but with nothing published yet gets no file, and isn't declared in the index either - an indexed empty urlset would just be a crawl error
    public function testWriteSkipsProvidersWithNoUrl(): void
    {
        $writer = $this->createWriter([
            $this->createProvider('site', ['https://example.com/pages/about']),
            $this->createProvider('book', []),
        ]);

        $names = $writer->write();

        $this->assertSame(['site'], $names);
        $this->assertFileDoesNotExist($this->projectDir . '/public/sitemap-book.xml');
        $this->assertStringNotContainsString('sitemap-book.xml', file_get_contents($this->projectDir . '/public/sitemap-index.xml'));
    }

    // A sitemap index only accepts absolute urls, so nothing is written at all before "site-url" is configured
    public function testWriteIndexIsSkippedWithoutSiteUrl(): void
    {
        $writer = $this->createWriter([$this->createProvider('site', ['https://example.com/pages/about'])], '');

        $writer->write();

        $this->assertFileExists($this->projectDir . '/public/sitemap-site.xml');
        $this->assertFileDoesNotExist($this->projectDir . '/public/sitemap-index.xml');
    }

    // No bundle contributing anything is a valid state (a brand new site), and must not write an empty index
    public function testWriteWithNoProviderWritesNothing(): void
    {
        $writer = $this->createWriter([]);

        $this->assertSame([], $writer->write());
        $this->assertSame([], glob($this->projectDir . '/public/*.xml'));
    }

    // A provider that stops publishing must not leave its previous file behind, or the search engines would keep being served urls that no longer exist
    public function testWriteRemovesTheFileOfAProviderWithNoUrl(): void
    {
        $staleFile = $this->projectDir . '/public/sitemap-book.xml';
        file_put_contents($staleFile, 'previous run');

        $this->createWriter([$this->createProvider('book', [])])->write();

        $this->assertFileDoesNotExist($staleFile);
    }

    // Two providers sharing a name would overwrite each other's file and duplicate the url in the index, so it has to be reported instead of producing a half written sitemap
    public function testWriteRejectsTwoProvidersSharingTheSameName(): void
    {
        $writer = $this->createWriter([
            $this->createProvider('site', ['https://example.com/pages/about']),
            $this->createProvider('site', ['https://example.com/pages/contact']),
        ]);

        $this->expectException(\LogicException::class);

        $writer->write();
    }

    // Only "loc" really identifies an url, so the missing keys are defaulted rather than rendered as empty elements
    public function testWriteDefaultsTheKeysAProviderLeftOut(): void
    {
        $this->createWriter([$this->createRawProvider('site', [['loc' => 'https://example.com/pages/about']])])->write();

        $url = $this->renderedUrls('site')[0];

        $this->assertSame(date('Y-m-d'), $url['lastmod']);
        $this->assertSame('weekly', $url['changefreq']);
        $this->assertSame(0.5, $url['priority']);
    }

    // Providers declare a priority on the admin's 0-10 scale, the protocol only accepts 0.0-1.0 - converted here, once, rather than by every provider
    public function testWriteConvertsPriorityToTheProtocolScale(): void
    {
        $this->createWriter([$this->createRawProvider('site', [
            ['loc' => 'https://example.com/a', 'priority' => 4],
            ['loc' => 'https://example.com/b', 'priority' => 10],
        ])])->write();

        $urls = $this->renderedUrls('site');

        $this->assertSame(0.4, (float) $urls[0]['priority']);
        $this->assertSame(1.0, (float) $urls[1]['priority']);
    }

    // A value outside the 0-10 scale is bounded rather than producing a priority the protocol rejects
    public function testWriteBoundsPriorityToTheProtocolRange(): void
    {
        $this->createWriter([$this->createRawProvider('site', [
            ['loc' => 'https://example.com/a', 'priority' => 40],
            ['loc' => 'https://example.com/b', 'priority' => -1],
        ])])->write();

        $urls = $this->renderedUrls('site');

        $this->assertSame(1.0, (float) $urls[0]['priority']);
        $this->assertSame(0.0, (float) $urls[1]['priority']);
    }
}
