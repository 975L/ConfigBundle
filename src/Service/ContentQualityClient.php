<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

// Parses a page's own rendered HTML (native DOMDocument/DOMXPath, no dependency) for the content-quality checks - title, meta description, H1, image alt text, Open Graph share tags, internal links (for ContentQualityHealthCheckProvider's broken-link pass). Reading the actual rendered markup rather than the block data that produced it works regardless of which block kinds/theme a page uses. Nothing is judged here (what makes a title too short, which share tags matter) - that's ContentQualityHealthCheckProvider's call, this only reports what the page holds
class ContentQualityClient
{
    // A missing alt attribute is always an error, but an explicitly empty one (alt="") is the *correct* way to mark a decorative image - it only counts when nothing marks it as such: no aria-hidden, no role="presentation"/"none", and no enclosing link/button already carrying its own accessible name (a share button's icon, a logo inside a labelled link). Flagging those would leave a page in warning forever, since there is nothing to fix
    private const DECORATIVE_IMAGE = '@aria-hidden="true" or @role="presentation" or @role="none" or ancestor::*[self::a or self::button][@aria-label or @aria-labelledby]';

    // Verdicts returned by readLinkCheck()/checkLink(). LINK_UNKNOWN is deliberately not LINK_BROKEN: a timeout, a refused connection or a server that won't answer HEAD says something about the run, not about the link, and a health check reporting a live page as dead is worse than reporting nothing at all
    public const LINK_OK = 'ok';
    public const LINK_BROKEN = 'broken';
    public const LINK_UNKNOWN = 'unknown';

    // Statuses that describe how the *server* treats this client rather than whether the url exists: the method it refuses (405/501), the bot filtering big retailers/social sites answer datacenter IPs with (403, and LinkedIn's own non-standard 999), and rate limiting (429). All inconclusive, never broken. Public so ContentQualityAnalyzer judges a page the same way rather than keeping its own copy - a site behind a WAF would otherwise have every one of its own pages reported as an error
    public const INCONCLUSIVE_STATUSES = [403, 405, 429, 501, 999];

    // Identifies the checker honestly (a WAF operator can look it up and allow it) while keeping the "Mozilla/5.0 (compatible; ...)" shape crawlers have used since Googlebot, which far fewer filters reject outright than a bare library default. Sites that still answer 403 are reported as inconclusive, not as broken - see INCONCLUSIVE_STATUSES
    private const LINK_CHECK_USER_AGENT = 'Mozilla/5.0 (compatible; c975LHealthCheck/1.0; +https://github.com/975L/SiteBundle)';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    // Fires the request and returns immediately without waiting for a response - Symfony's HttpClient transports multiplex every in-flight response, so a caller analyzing many pages/links (ContentQualityHealthCheckProvider) can request()/requestLinkCheck() all of them up front and read()/readLinkCheck() them afterwards to run them concurrently instead of paying each timeout serially
    public function request(string $url): ResponseInterface
    {
        return $this->httpClient->request('GET', $url, ['timeout' => 30]);
    }

    // Blocks until the given in-flight response completes and parses it - $url is the same one passed to request(), needed again here to resolve links against its own host. Returns ['title' => string, 'description' => string, 'hasDescription' => bool, 'hasH1' => bool, 'imagesWithoutAlt' => string[] (each offending img's src), 'socialTags' => array<string, string>, 'internalLinks' => string[] (deduped, absolute, same-host only), 'externalLinks' => string[] (same, other hosts), 'linkTexts' => array<string, string> (each link's anchor text)]
    public function read(ResponseInterface $response, string $url): array
    {
        $xpath = $this->buildXPath($response->getContent());
        $host = parse_url($url, \PHP_URL_HOST);

        $description = trim((string) $xpath->query('//meta[@name="description"]/@content')->item(0)?->nodeValue);
        ['links' => $internalLinks, 'external' => $externalLinks, 'texts' => $linkTexts] = $this->extractInternalLinks($xpath, $url, $host);

        return [
            // Whitespace collapsed the same way a browser/crawler renders it - a <title> broken over three indented Twig lines is not a 40-character title
            'title' => trim(preg_replace('/\s+/', ' ', (string) $xpath->query('//title')->item(0)?->textContent)),
            // The description itself alongside hasDescription, since its length is checked too and "missing" and "too short" are two different things to tell the user
            'description' => $description,
            'hasDescription' => '' !== $description,
            // The count, not just their presence: several <h1> stay valid HTML, but they announce as many top-level subjects for one page to a screen reader - reported as its own issue, see ContentQualityAnalyzer::summarizeIssues()
            'h1Count' => $xpath->query('//h1')->length,
            'imagesWithoutAlt' => $this->extractImagesWithoutAlt($xpath),
            'socialTags' => $this->extractSocialTags($xpath),
            'internalLinks' => $internalLinks,
            'externalLinks' => $externalLinks,
            'linkTexts' => $linkTexts,
        ];
    }

    // Convenience for a single-URL analysis - returns the same shape as read(), or throws on a network/API error
    public function analyze(string $url): array
    {
        return $this->read($this->request($url), $url);
    }

    // A HEAD request is enough to know if a link resolves
    public function requestLinkCheck(string $url): ResponseInterface
    {
        return $this->httpClient->request('HEAD', $url, ['timeout' => 15, 'headers' => ['User-Agent' => self::LINK_CHECK_USER_AGENT]]);
    }

    // Second pass for a link the HEAD couldn't conclude on - a fair share of servers answer 405/501 to HEAD, or drop it altogether, on urls that serve perfectly well in GET
    public function requestLinkCheckFallback(string $url): ResponseInterface
    {
        return $this->httpClient->request('GET', $url, ['timeout' => 15, 'headers' => ['User-Agent' => self::LINK_CHECK_USER_AGENT]]);
    }

    // Only a conclusive >= 400 answer means broken. A transport failure (DNS, timeout, connection refused) yields LINK_UNKNOWN, and so does anything in INCONCLUSIVE_STATUSES, which describes how the server treats this client rather than whether the url exists - all worth a requestLinkCheckFallback() retry before anything is called broken
    public function readLinkCheck(ResponseInterface $response): string
    {
        try {
            $status = $response->getStatusCode();
        } catch (\Throwable) {
            return self::LINK_UNKNOWN;
        }

        return match (true) {
            \in_array($status, self::INCONCLUSIVE_STATUSES, true) => self::LINK_UNKNOWN,
            $status >= 400 => self::LINK_BROKEN,
            default => self::LINK_OK,
        };
    }

    // Convenience for a single-URL check, HEAD then GET - returns one of the LINK_* verdicts, catching a synchronous failure from request() itself the same way as a failed transfer
    public function checkLink(string $url): string
    {
        $verdict = $this->readLinkCheckSafely(fn (): ResponseInterface => $this->requestLinkCheck($url));

        return self::LINK_UNKNOWN === $verdict
            ? $this->readLinkCheckSafely(fn (): ResponseInterface => $this->requestLinkCheckFallback($url))
            : $verdict;
    }

    // True only on a conclusive LINK_BROKEN - an unreachable host is not reported as a broken link
    public function isLinkBroken(string $url): bool
    {
        return self::LINK_BROKEN === $this->checkLink($url);
    }

    private function readLinkCheckSafely(callable $request): string
    {
        try {
            return $this->readLinkCheck($request());
        } catch (\Throwable) {
            return self::LINK_UNKNOWN;
        }
    }

    private function buildXPath(string $html): \DOMXPath
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        // Forces UTF-8 interpretation regardless of the page's own <meta charset> (or lack thereof) - DOMDocument defaults to ISO-8859-1 otherwise, mangling accented characters
        $dom->loadHTML('<?xml encoding="utf-8">' . $html, \LIBXML_NOERROR | \LIBXML_NOWARNING);
        libxml_clear_errors();

        return new \DOMXPath($dom);
    }

    // Each offending image's own src rather than just how many there are, so the Health check panel can list them one by one (and SiteBundle's PageBlockLocator trace each one back to the block holding it). Deduped: the same image used twice on a page is a single alt text to write, not two
    private function extractImagesWithoutAlt(\DOMXPath $xpath): array
    {
        $sources = [];

        foreach ($xpath->query('//img[not(@alt) or (@alt="" and not(' . self::DECORATIVE_IMAGE . '))]') as $image) {
            $src = trim($image->getAttribute('src'));
            if ('' !== $src) {
                $sources[$src] = true;
            }
        }

        return array_keys($sources);
    }

    // Every og:* meta tag holding an actual value, keyed by its (lowercased) name - which of them a page is expected to carry is ContentQualityHealthCheckProvider's call, not this one's. Both the "property" and the "name" attribute are read: the Open Graph protocol specifies "property", but "name" is common in the wild and works just as well. An empty content is the same as no tag at all - a share preview has nothing to render either way
    private function extractSocialTags(\DOMXPath $xpath): array
    {
        $tags = [];

        foreach ($xpath->query('//meta[@content][@property or @name]') as $meta) {
            $name = strtolower(trim($meta->getAttribute('property') ?: $meta->getAttribute('name')));
            $content = trim($meta->getAttribute('content'));
            if ('' !== $content && str_starts_with($name, 'og:')) {
                $tags[$name] ??= $content;
            }
        }

        return $tags;
    }

    // Split into same-host links (this site's own pages) and http(s) links to another host, both absolute and deduped - anchors, mailto:/tel:/javascript: and host-less relative hrefs ("contact.html", which nothing in this bundle produces) are dropped either way. The two are kept apart rather than merged because a dead link on your own site and a merchant that took its product page down are not the same problem, nor the same severity (see ContentQualityHealthCheckProvider::buildRow()). Each link's anchor text is kept alongside it ('texts'), so a broken link can be listed by what the visitor actually clicks on rather than by its url alone - first occurrence wins, the same url linked twice with two different labels only needs fixing once
    private function extractInternalLinks(\DOMXPath $xpath, string $pageUrl, ?string $host): array
    {
        $links = [];
        $external = [];
        $texts = [];

        foreach ($xpath->query('//a[@href]') as $anchor) {
            $link = $this->absoluteLink(trim($anchor->getAttribute('href')), $pageUrl, $host);
            if (null === $link) {
                continue;
            }

            if (parse_url($link, \PHP_URL_HOST) === $host) {
                $links[$link] = true;
            } elseif (preg_match('#^https?://#i', $link)) {
                $external[$link] = true;
            } else {
                continue;
            }

            $texts[$link] ??= $this->anchorText($anchor);
        }

        return ['links' => array_keys($links), 'external' => array_keys($external), 'texts' => array_filter($texts)];
    }

    // The requestable url an href points at, or null for what never is - an empty href, an anchor, a mailto:/tel:/javascript: scheme. A protocol-relative href ("//cdn.example.com/x") inherits the page's own scheme, a root-relative one its host as well
    private function absoluteLink(string $href, string $pageUrl, ?string $host): ?string
    {
        if ('' === $href || str_starts_with($href, '#') || preg_match('/^(mailto|tel|javascript):/i', $href)) {
            return null;
        }

        $scheme = parse_url($pageUrl, \PHP_URL_SCHEME);

        return match (true) {
            str_starts_with($href, '//') => $scheme . ':' . $href,
            str_starts_with($href, '/') => $scheme . '://' . $host . $href,
            default => $href,
        };
    }

    // An image-only link has no text of its own - its image's alt is the closest thing to a label, and an empty string when it has none either (filtered out by the caller)
    private function anchorText(\DOMNode $anchor): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $anchor->textContent));
        if ('' !== $text) {
            return $text;
        }

        $image = (new \DOMXPath($anchor->ownerDocument))->query('.//img[@alt]', $anchor)->item(0);

        return $image instanceof \DOMElement ? trim($image->getAttribute('alt')) : '';
    }
}
