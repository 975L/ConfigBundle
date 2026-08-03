<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Service;

use c975L\ConfigBundle\Service\ContentQualityClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ContentQualityClientTest extends TestCase
{
    private function htmlResponse(string $html): MockHttpClient
    {
        return new MockHttpClient(fn (string $method, string $url, array $options) => new MockResponse($html, ['http_code' => 200]));
    }

    public function testAnalyzeDetectsAPresentDescriptionAndH1(): void
    {
        $html = '<html><head><meta name="description" content="A great page"></head><body><h1>Title</h1></body></html>';
        $client = new ContentQualityClient($this->htmlResponse($html));

        $result = $client->analyze('https://example.com/pages/home/');

        $this->assertTrue($result['hasDescription']);
        $this->assertSame(1, $result['h1Count']);
        $this->assertSame([], $result['imagesWithoutAlt']);
    }

    public function testAnalyzeDetectsAMissingDescriptionAndH1(): void
    {
        $html = '<html><head></head><body><p>No heading here</p></body></html>';
        $client = new ContentQualityClient($this->htmlResponse($html));

        $result = $client->analyze('https://example.com/pages/home/');

        $this->assertFalse($result['hasDescription']);
        $this->assertSame(0, $result['h1Count']);
    }

    public function testAnalyzeCountsEveryH1(): void
    {
        $html = '<html><head></head><body><h1>Home</h1><section><h1>Our services</h1></section></body></html>';
        $client = new ContentQualityClient($this->htmlResponse($html));

        $this->assertSame(2, $client->analyze('https://example.com/pages/home/')['h1Count']);
    }

    public function testAnalyzeTreatsABlankDescriptionAsMissing(): void
    {
        $html = '<html><head><meta name="description" content="   "></head><body><h1>Title</h1></body></html>';
        $client = new ContentQualityClient($this->htmlResponse($html));

        $this->assertFalse($client->analyze('https://example.com/pages/home/')['hasDescription']);
    }

    public function testAnalyzeReadsTheTitleAndDescription(): void
    {
        $html = '<html><head><title>Nos tarifs 2026</title><meta name="description" content=" A great page "></head><body><h1>T</h1></body></html>';
        $client = new ContentQualityClient($this->htmlResponse($html));

        $result = $client->analyze('https://example.com/pages/home/');

        $this->assertSame('Nos tarifs 2026', $result['title']);
        $this->assertSame('A great page', $result['description']);
    }

    // A <title> broken over several indented Twig lines is not a longer title than the same one on a single line
    public function testAnalyzeCollapsesWhitespaceInTheTitle(): void
    {
        $html = "<html><head><title>\n    Nos   tarifs\n    2026\n</title></head><body><h1>T</h1></body></html>";
        $client = new ContentQualityClient($this->htmlResponse($html));

        $this->assertSame('Nos tarifs 2026', $client->analyze('https://example.com/pages/home/')['title']);
    }

    public function testAnalyzeReturnsAnEmptyTitleWhenThePageHasNone(): void
    {
        $html = '<html><head></head><body><h1>T</h1></body></html>';
        $client = new ContentQualityClient($this->htmlResponse($html));

        $this->assertSame('', $client->analyze('https://example.com/pages/home/')['title']);
    }

    // The Open Graph protocol specifies "property", but "name" is common in the wild and works just as well
    public function testAnalyzeReadsSocialTagsFromEitherPropertyOrName(): void
    {
        $html = '<html><head>
            <meta property="og:title" content="A title">
            <meta name="og:description" content="A description">
            <meta property="og:image" content="/media/og.png">
            <meta name="viewport" content="width=device-width">
        </head><body><h1>T</h1></body></html>';
        $client = new ContentQualityClient($this->htmlResponse($html));

        $tags = $client->analyze('https://example.com/pages/home/')['socialTags'];

        $this->assertSame(['og:title', 'og:description', 'og:image'], array_keys($tags));
        $this->assertSame('/media/og.png', $tags['og:image']);
    }

    // A share preview has nothing to render from an empty content, so it counts as no tag at all
    public function testAnalyzeIgnoresASocialTagWithAnEmptyContent(): void
    {
        $html = '<html><head><meta property="og:image" content="  "></head><body><h1>T</h1></body></html>';
        $client = new ContentQualityClient($this->htmlResponse($html));

        $this->assertSame([], $client->analyze('https://example.com/pages/home/')['socialTags']);
    }

    // Each offending image's own src, not just how many - the Health check panel lists them one by one
    public function testAnalyzeListsImagesWithoutAlt(): void
    {
        $html = '<html><body><h1>T</h1><img src="a.jpg" alt="Described"><img src="b.jpg"><img src="c.jpg" alt=""></body></html>';
        $client = new ContentQualityClient($this->htmlResponse($html));

        $this->assertSame(['b.jpg', 'c.jpg'], $client->analyze('https://example.com/pages/home/')['imagesWithoutAlt']);
    }

    // alt="" is the correct way to mark a decorative image - flagging it would leave the page in warning with nothing to fix
    public function testAnalyzeSkipsDecorativeImagesWithAnEmptyAlt(): void
    {
        $html = '<html><body><h1>T</h1>
            <img src="hidden.svg" alt="" aria-hidden="true">
            <img src="presentation.svg" alt="" role="presentation">
            <img src="none.svg" alt="" role="none">
            <a href="/pages/contact/" aria-label="Share on Facebook"><img src="icon.svg" alt=""></a>
            <button aria-labelledby="x"><img src="button.svg" alt=""></button>
        </body></html>';
        $client = new ContentQualityClient($this->htmlResponse($html));

        $this->assertSame([], $client->analyze('https://example.com/pages/home/')['imagesWithoutAlt']);
    }

    // A missing alt attribute stays an error even on an image nothing else would have flagged
    public function testAnalyzeStillListsAMissingAltOnADecorativeImage(): void
    {
        $html = '<html><body><h1>T</h1><a href="/pages/contact/" aria-label="Contact"><img src="icon.svg"></a></body></html>';
        $client = new ContentQualityClient($this->htmlResponse($html));

        $this->assertSame(['icon.svg'], $client->analyze('https://example.com/pages/home/')['imagesWithoutAlt']);
    }

    // An enclosing element that is not a link/button names nothing - its own image keeps being flagged
    public function testAnalyzeListsAnEmptyAltUnderANonInteractiveLabelledAncestor(): void
    {
        $html = '<html><body><h1>T</h1><nav aria-label="Main"><img src="b.jpg" alt=""></nav></body></html>';
        $client = new ContentQualityClient($this->htmlResponse($html));

        $this->assertSame(['b.jpg'], $client->analyze('https://example.com/pages/home/')['imagesWithoutAlt']);
    }

    // The same image used twice on a page is a single alt text to write
    public function testAnalyzeDedupesImagesWithoutAlt(): void
    {
        $html = '<html><body><h1>T</h1><img src="b.jpg"><img src="b.jpg"></body></html>';
        $client = new ContentQualityClient($this->htmlResponse($html));

        $this->assertSame(['b.jpg'], $client->analyze('https://example.com/pages/home/')['imagesWithoutAlt']);
    }

    // A link is listed by what the visitor clicks on rather than by its url alone
    public function testAnalyzeKeepsEachInternalLinkAnchorText(): void
    {
        $html = '<html><body><h1>T</h1><a href="/pages/contact/">  Nos   tarifs </a><a href="/pages/about/"><img src="a.jpg" alt="Notre équipe"></a></body></html>';
        $client = new ContentQualityClient($this->htmlResponse($html));

        $texts = $client->analyze('https://example.com/pages/home/')['linkTexts'];

        $this->assertSame('Nos tarifs', $texts['https://example.com/pages/contact/']);
        $this->assertSame('Notre équipe', $texts['https://example.com/pages/about/']);
    }

    public function testAnalyzeExtractsRootRelativeAndSameHostAbsoluteLinksOnly(): void
    {
        $html = '<html><body><h1>T</h1>
            <a href="/pages/contact/">Contact</a>
            <a href="https://example.com/pages/about/">About</a>
            <a href="https://external.com/">External</a>
            <a href="#top">Anchor</a>
            <a href="mailto:hello@example.com">Mail</a>
        </body></html>';
        $client = new ContentQualityClient($this->htmlResponse($html));

        $links = $client->analyze('https://example.com/pages/home/')['internalLinks'];

        $this->assertSame(['https://example.com/pages/contact/', 'https://example.com/pages/about/'], $links);
    }

    // Links to another host are kept apart from this site's own, not dropped - a merchant that took a product page down is a real dead link, just not the same problem nor the same severity
    public function testAnalyzeExtractsExternalLinksSeparately(): void
    {
        $html = '<html><body><h1>T</h1>
            <a href="/pages/contact/">Contact</a>
            <a href="https://www.fnac.com/livre/123">Acheter</a>
            <a href="http://partner.example.org/">Partenaire</a>
            <a href="//cdn.example.net/asset">CDN</a>
            <a href="mailto:hello@example.com">Mail</a>
            <a href="ftp://files.example.org/">Ftp</a>
        </body></html>';
        $client = new ContentQualityClient($this->htmlResponse($html));

        $result = $client->analyze('https://example.com/pages/home/');

        $this->assertSame(['https://example.com/pages/contact/'], $result['internalLinks']);
        // The protocol-relative one inherits the page's own scheme, otherwise it isn't requestable; ftp:/mailto: are out
        $this->assertSame(['https://www.fnac.com/livre/123', 'http://partner.example.org/', 'https://cdn.example.net/asset'], $result['externalLinks']);
        $this->assertSame('Acheter', $result['linkTexts']['https://www.fnac.com/livre/123']);
    }

    // A status that says how the server treats this client (bot filtering, rate limiting, a refused method) says nothing about whether the url exists
    public function testCheckLinkTreatsAntiBotAndRateLimitStatusesAsInconclusive(): void
    {
        foreach ([403, 429, 999] as $status) {
            $httpClient = new MockHttpClient(fn (string $method, string $url, array $options) => new MockResponse('', ['http_code' => $status]));
            $client = new ContentQualityClient($httpClient);

            $this->assertSame(ContentQualityClient::LINK_UNKNOWN, $client->checkLink('https://www.fnac.com/livre/123'), 'HTTP ' . $status);
            $this->assertFalse($client->isLinkBroken('https://www.fnac.com/livre/123'));
        }
    }

    // Identifying the checker is what lets a WAF operator allow it rather than guess at it
    public function testLinkChecksSendAnIdentifiableUserAgent(): void
    {
        $sentHeaders = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$sentHeaders): MockResponse {
            $sentHeaders[] = implode("\n", $options['headers'] ?? []);

            // 405 on the HEAD so the GET fallback is exercised too - both passes must identify themselves
            return new MockResponse('', ['http_code' => 'HEAD' === $method ? 405 : 200]);
        });
        $client = new ContentQualityClient($httpClient);

        $client->checkLink('https://external.com/');

        $this->assertCount(2, $sentHeaders);
        foreach ($sentHeaders as $headers) {
            $this->assertStringContainsString('c975LHealthCheck', $headers);
        }
    }

    public function testAnalyzeDedupesInternalLinks(): void
    {
        $html = '<html><body><h1>T</h1><a href="/pages/contact/">A</a><a href="/pages/contact/">B</a></body></html>';
        $client = new ContentQualityClient($this->htmlResponse($html));

        $this->assertSame(['https://example.com/pages/contact/'], $client->analyze('https://example.com/pages/home/')['internalLinks']);
    }

    public function testIsLinkBrokenReturnsTrueForA4xxStatus(): void
    {
        $httpClient = new MockHttpClient(fn (string $method, string $url, array $options) => new MockResponse('', ['http_code' => 404]));
        $client = new ContentQualityClient($httpClient);

        $this->assertTrue($client->isLinkBroken('https://example.com/pages/missing/'));
    }

    public function testIsLinkBrokenReturnsFalseForA2xxStatus(): void
    {
        $httpClient = new MockHttpClient(fn (string $method, string $url, array $options) => new MockResponse('', ['http_code' => 200]));
        $client = new ContentQualityClient($httpClient);

        $this->assertFalse($client->isLinkBroken('https://example.com/pages/home/'));
    }

    // A timeout/refused connection says something about the run, not about the link - reporting a live page as dead is worse than reporting nothing
    public function testIsLinkBrokenReturnsFalseOnTransportFailure(): void
    {
        $httpClient = new MockHttpClient(fn (string $method, string $url, array $options) => new MockResponse('', ['error' => 'Connection refused']));
        $client = new ContentQualityClient($httpClient);

        $this->assertFalse($client->isLinkBroken('https://example.com/pages/home/'));
        $this->assertSame(ContentQualityClient::LINK_UNKNOWN, $client->checkLink('https://example.com/pages/home/'));
    }

    // Some servers refuse the HEAD method itself on a url that serves perfectly well in GET
    public function testCheckLinkRetriesInGetWhenHeadIsRefused(): void
    {
        $methods = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$methods): MockResponse {
            $methods[] = $method;

            return new MockResponse('', ['http_code' => 'HEAD' === $method ? 405 : 200]);
        });
        $client = new ContentQualityClient($httpClient);

        $this->assertSame(ContentQualityClient::LINK_OK, $client->checkLink('https://example.com/pages/home/'));
        $this->assertSame(['HEAD', 'GET'], $methods);
    }

    // A GET retry answering >= 400 does conclude - the link really is broken
    public function testCheckLinkReportsBrokenWhenTheGetRetryAlsoFails(): void
    {
        $httpClient = new MockHttpClient(fn (string $method, string $url, array $options) => new MockResponse('', ['http_code' => 'HEAD' === $method ? 405 : 404]));
        $client = new ContentQualityClient($httpClient);

        $this->assertSame(ContentQualityClient::LINK_BROKEN, $client->checkLink('https://example.com/pages/missing/'));
    }

    // No retry when the HEAD already concluded
    public function testCheckLinkDoesNotRetryAConclusiveHead(): void
    {
        $calls = 0;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('', ['http_code' => 404]);
        });
        $client = new ContentQualityClient($httpClient);

        $this->assertSame(ContentQualityClient::LINK_BROKEN, $client->checkLink('https://example.com/pages/missing/'));
        $this->assertSame(1, $calls);
    }
}
