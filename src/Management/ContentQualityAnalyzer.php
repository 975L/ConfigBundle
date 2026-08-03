<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Management;

use c975L\ConfigBundle\Entity\HealthCheckResult;
use c975L\ConfigBundle\Management\HealthCheckErrorRow;
use c975L\ConfigBundle\Service\ContentQualityClient;
use c975L\ConfigBundle\Service\UrlStatusChecker;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// Turns a list of urls into HealthCheckResult rows - the whole content-quality check (title, meta description, H1, image alt text, Open Graph share tags, broken links), independent of where the urls came from. Lives here rather than in SiteBundle so a site with no pages at all (a shop, a catalogue, a gallery) is checked just the same. Each entry may carry an opaque 'source' object, which ContentOffenceLocatorRegistry turns back into a link to the screen holding the offence - SiteBundle contributes the one tracing a Page's image back to its Block; without any locator the offence is still reported, just without a link. No external API, only the site's own rendered HTML
class ContentQualityAnalyzer
{
    // How many requests are kept in flight at once, for the page analyses as well as the link checks. Symfony's HttpClient caps concurrent connections per host, so firing every url of the whole site at once only queues the surplus - while each queued request's own timeout is already running, turning perfectly valid pages/links into timeouts, and timeouts into "broken" rows. It also bounds how many responses are held in memory at once, which matters for a bundle declaring thousands of urls (see DeclaredUrlsHealthCheckProvider)
    private const BATCH_SIZE = 10;

    // Recommended <title> window, in characters. The upper bound is the only one search engines actually impose: past 65 the results page truncates it mid-word. The lower one is editorial, and deliberately far below the 30 characters SEO tools conventionally flag - a title is built here as "Page title - Site name" (see layout.html.twig), so a short site name alone can push a perfectly explicit title under such a threshold, and a warning raised one character short of it only teaches the reader to ignore the table. Ten catches what it is meant to catch: a title reduced to a word or two. Public so PageHealthCheckAdviceBuilder states the same numbers in its advice line rather than keeping its own copy
    public const TITLE_MIN_LENGTH = 10;
    public const TITLE_MAX_LENGTH = 65;

    // Same for the meta description - under 50 characters says too little to earn a click, over 160 is cut off
    public const DESCRIPTION_MIN_LENGTH = 50;
    public const DESCRIPTION_MAX_LENGTH = 160;

    // The form field holding that description (on a Page it feeds both <meta name="description"> and og:description, see SiteBundle's PageCrudController). Every summary clause and advice line about it names the field by the very label the form shows, rather than by "meta description" - the user has to find that field to act on the advice, and nothing in the back office calls it that. In the 'config' domain rather than SiteBundle's own, being read from both bundles now, and a site with no pages at all still has to render this summary. Public so PageHealthCheckAdviceBuilder words its own lines identically
    public const DESCRIPTION_FIELD_LABEL = 'label.summary_social_network';

    // What a share preview actually needs to render on Facebook/LinkedIn/WhatsApp. og:url and og:type belong to the Open Graph protocol too, but nothing visible breaks without them, so they stay out rather than turning every page orange over a tag no one sees
    public const REQUIRED_SOCIAL_TAGS = ['og:title', 'og:description', 'og:image'];

    public function __construct(
        private readonly ContentQualityClient $contentQualityClient,
        private readonly UrlStatusChecker $urlStatusChecker,
        private readonly ContentOffenceLocatorRegistry $offenceLocatorRegistry,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // @param list<array{url: string, label: ?string, editUrl: ?string, source: ?object}> $entries - 'source' is what lets an offence be traced back to the screen holding it (see ContentOffenceLocatorInterface), so an entry without one still gets every check, just without those links
    public function analyze(array $entries): array
    {
        $analyses = $this->analyzeUrls($entries);
        $brokenLinks = $this->checkBrokenLinks($analyses);

        return array_map(fn (array $entry) => $this->buildRow($entry, $brokenLinks), $analyses);
    }

    // Every analysis request of a batch is fired before any of its responses is read, letting the HttpClient transport run them concurrently instead of paying each page's timeout serially - but one batch at a time (see BATCH_SIZE), so a bundle declaring thousands of urls doesn't queue them all behind an already-running timeout, nor hold every response in memory at once. Failures are kept alongside successes rather than thrown away, so buildRow() can still emit a row for a page whose content couldn't be analyzed. Rows are keyed by the page's own position (not appended as each branch resolves) and ksort()ed back at the end, so a not-found/failed page in the middle of the list doesn't shuffle every row after it to the bottom
    private function analyzeUrls(array $entries): array
    {
        $analyses = [];
        foreach (array_chunk($entries, self::BATCH_SIZE, true) as $batch) {
            $pending = [];
            foreach ($batch as $index => $entry) {
                // A single HEAD before the slower analysis request, same as W3cHealthCheckProvider - and it carries the status code, so a page that doesn't answer is reported for what it answered rather than as a raw analysis failure. Only for an entry with a source though: a bare declared url gets the same verdict for free from its own analysis response, and doubling the request count of a bundle declaring thousands of urls is not free
                if (null !== $entry['source']) {
                    $status = $this->urlStatusChecker->status($entry['url']);
                    if (null === $status || $status >= 400) {
                        $analyses[$index] = $entry + $this->failedEntry(null, $status);
                        continue;
                    }
                }

                try {
                    $pending[$index] = $entry + ['response' => $this->contentQualityClient->request($entry['url'])];
                } catch (\Throwable $e) {
                    $analyses[$index] = $entry + $this->failedEntry($e->getMessage(), null);
                }
            }

            foreach ($pending as $index => $entry) {
                // The response is taken out of the entry before it is kept: nothing downstream reads it, and leaving it in would hold every batch's buffered bodies until the whole run ends, which is exactly what BATCH_SIZE is there to avoid
                $response = $entry['response'];
                unset($entry['response']);

                try {
                    $analyses[$index] = $entry + [
                        'analysis' => $this->contentQualityClient->read($response, $entry['url']),
                        'error' => null,
                        'httpStatus' => null,
                        'redirect' => $this->describeRedirect($response, $entry['url']),
                    ];
                } catch (\Throwable $e) {
                    $analyses[$index] = $entry + $this->failedEntry($e->getMessage(), $this->failureStatus($response));
                }
            }
        }

        ksort($analyses);

        return array_values($analyses);
    }

    // An entry no analysis could be built for, carrying whichever of the two says why: the status the url answered, or the message the request failed with
    // @return array{analysis: null, error: ?string, httpStatus: ?int, redirect: null}
    private function failedEntry(?string $error, ?int $status): array
    {
        return ['analysis' => null, 'error' => $error, 'httpStatus' => $status, 'redirect' => null];
    }

    // The redirects the analysis request already followed, read off its own response info rather than paying a second request with max_redirects: 0 for it. A url declared in a sitemap is meant to be the canonical one and answer 200 directly - Search Console reports the hop as a redirect error rather than following it the way a browser silently does
    // @return array{count: int, finalUrl: string}|null
    private function describeRedirect(ResponseInterface $response, string $url): ?array
    {
        $count = (int) $response->getInfo('redirect_count');
        if ($count < 1) {
            return null;
        }

        $finalUrl = (string) ($response->getInfo('url') ?? '');

        return ['count' => $count, 'finalUrl' => '' === $finalUrl ? $url : $finalUrl];
    }

    // The status behind a failed analysis when there is one that explains it - null otherwise, so the failure is reported by the message it carries: a response that never completed (timeout, DNS, refused connection) throws here and has no status at all, and one that answered 200 and still failed to parse is not explained by its code either
    private function failureStatus(ResponseInterface $response): ?int
    {
        try {
            $status = $response->getStatusCode();
        } catch (\Throwable) {
            return null;
        }

        return $status >= 400 ? $status : null;
    }

    // Every link found on every page, internal and external together, deduped, each checked once regardless of how many pages link to it - fired in batches (see BATCH_SIZE), then whatever the HEAD pass couldn't conclude on retried once in GET, so that only a real >= 400 answer ever ends up reported as broken. Internal and external are checked in the same pass (one dedup, one set of batches, no external host hit twice because two pages link to it) and only told apart when building each page's row
    private function checkBrokenLinks(array $analyses): array
    {
        $allLinks = [];
        foreach ($analyses as $entry) {
            foreach (array_merge($entry['analysis']['internalLinks'] ?? [], $entry['analysis']['externalLinks'] ?? []) as $link) {
                $allLinks[$link] = true;
            }
        }

        $verdicts = $this->runLinkChecks(array_keys($allLinks), fn (string $link) => $this->contentQualityClient->requestLinkCheck($link));

        $inconclusive = array_keys($verdicts, ContentQualityClient::LINK_UNKNOWN, true);
        if ($inconclusive) {
            $verdicts = array_replace($verdicts, $this->runLinkChecks($inconclusive, fn (string $link) => $this->contentQualityClient->requestLinkCheckFallback($link)));
        }

        // A link still unknown after both passes stays out of the broken list - a timeout of the run's own making says nothing about the link
        return array_map(static fn (string $verdict): bool => ContentQualityClient::LINK_BROKEN === $verdict, $verdicts);
    }

    // Each batch requested in full before any of it is read, so a batch still runs concurrently - request() itself can throw before any response exists (a malformed url), which is as inconclusive as a failed transfer
    private function runLinkChecks(array $links, callable $request): array
    {
        $verdicts = [];
        foreach (array_chunk($links, self::BATCH_SIZE) as $batch) {
            $pending = [];
            foreach ($batch as $link) {
                try {
                    $pending[$link] = $request($link);
                } catch (\Throwable) {
                    $verdicts[$link] = ContentQualityClient::LINK_UNKNOWN;
                }
            }

            foreach ($pending as $link => $response) {
                $verdicts[$link] = $this->contentQualityClient->readLinkCheck($response);
            }
        }

        return $verdicts;
    }

    private function buildRow(array $entry, array $brokenLinks): array
    {
        if (null === $entry['analysis']) {
            return $this->buildFailedRow($entry);
        }

        $details = $this->buildDetails($entry, $brokenLinks);
        $issues = array_merge($this->redirectIssue($details), $this->summarizeIssues($details));

        return [
            'url' => $entry['url'],
            'label' => $entry['label'],
            // Only a dead link on this site's own pages is an error. A dead external one is a warning: it isn't yours to fix on your own schedule, and it's the check most exposed to a false positive (a host down for an hour, a filter this run didn't get past)
            'status' => match (true) {
                [] === $issues => HealthCheckResult::STATUS_OK,
                [] !== $details['brokenLinks'] => HealthCheckResult::STATUS_ERROR,
                default => HealthCheckResult::STATUS_WARNING,
            },
            'summary' => $issues ? implode(' · ', $issues) : $this->translator->trans('label.health_check_content_quality_ok', [], 'site'),
            // hasDescription/hasH1 kept alongside imagesWithoutAlt/brokenLinks (already needed for the summary/status above) so PageHealthCheckAdviceBuilder can tell issues apart without re-parsing the translated summary text. The title/description verdicts are persisted already resolved ('short'/'long'/...) rather than as raw lengths alone, so the advice builder doesn't have to re-apply the thresholds - it only needs the length to state it back to the user
            'details' => $details,
            'editUrl' => $entry['editUrl'],
        ];
    }

    // The url never got analyzed, so there is nothing to judge about its content - only what it answered. A Page answering 404 used to be reported as "not tested" (STATUS_SKIPPED, so no dashboard alert either), which hid the very 404s Search Console reports: the command is documented as running against production's own database, where a page listed in the sitemap that doesn't answer is a real defect, not an undeployed one
    private function buildFailedRow(array $entry): array
    {
        $status = $entry['httpStatus'];

        // A warning rather than an error: the site answered correctly, the resource really was removed on purpose - what's left to fix is that something still declares it
        if (410 === $status) {
            return $this->failedRow($entry, HealthCheckResult::STATUS_WARNING, 'label.health_check_url_gone', []);
        }

        if (404 === $status) {
            return $this->failedRow($entry, HealthCheckResult::STATUS_ERROR, 'label.health_check_url_not_found', []);
        }

        // The same statuses the link checks refuse to call broken (see ContentQualityClient::INCONCLUSIVE_STATUSES): they describe how the server treats this checker, not whether the page is fine, so a site behind a WAF would otherwise turn every one of its pages red without a single real defect. A warning rather than a skipped row, which would raise no alert at all - the run genuinely couldn't judge the page, and that is worth knowing
        if (\in_array($status, ContentQualityClient::INCONCLUSIVE_STATUSES, true)) {
            return $this->failedRow($entry, HealthCheckResult::STATUS_WARNING, 'label.health_check_url_inconclusive', ['%status%' => $status]);
        }

        if (null !== $status) {
            return $this->failedRow($entry, HealthCheckResult::STATUS_ERROR, 'label.health_check_url_http_error', ['%status%' => $status]);
        }

        // No status at all: either the analysis request itself failed (its message says why), or the existence HEAD never completed
        return null !== $entry['error']
            ? HealthCheckErrorRow::build($this->translator, 'site', $entry['url'], $entry['label'], 'label.health_check_content_quality_call_failed', $entry['error'], $entry['editUrl'])
            : $this->failedRow($entry, HealthCheckResult::STATUS_ERROR, 'label.health_check_url_unreachable', []);
    }

    private function failedRow(array $entry, string $status, string $translationId, array $parameters): array
    {
        return [
            'url' => $entry['url'],
            'label' => $entry['label'],
            'status' => $status,
            'summary' => $this->translator->trans($translationId, $parameters, 'site'),
            'details' => [],
            'editUrl' => $entry['editUrl'],
        ];
    }

    // Everything found on one page, in the shape both summarizeIssues() and PageHealthCheckAdviceBuilder read
    private function buildDetails(array $entry, array $brokenLinks): array
    {
        $analysis = $entry['analysis'];
        $titleLength = mb_strlen($analysis['title'] ?? '');
        // A description that isn't there at all is reported as missing, not as too short - "add one" and "make it longer" are two different things to do
        $descriptionLength = mb_strlen($analysis['description'] ?? '');

        return [
            'titleIssue' => $this->lengthIssue($titleLength, self::TITLE_MIN_LENGTH, self::TITLE_MAX_LENGTH),
            'titleLength' => $titleLength,
            'hasDescription' => $analysis['hasDescription'],
            'descriptionIssue' => $analysis['hasDescription'] ? $this->lengthIssue($descriptionLength, self::DESCRIPTION_MIN_LENGTH, self::DESCRIPTION_MAX_LENGTH) : null,
            'descriptionLength' => $descriptionLength,
            'h1Count' => $analysis['h1Count'],
            'missingSocialTags' => $this->missingSocialTags($analysis),
            'imagesWithoutAlt' => $this->describeImages($entry['source'], $analysis['imagesWithoutAlt']),
            'brokenLinks' => $this->describeBrokenLinks($entry, $brokenLinks, 'internalLinks'),
            'brokenExternalLinks' => $this->describeBrokenLinks($entry, $brokenLinks, 'externalLinks'),
            // null when the url answered 200 directly, which is what a checked url is expected to do (see describeRedirect())
            'redirect' => $entry['redirect'],
        ];
    }

    // Kept out of summarizeIssues() and listed first: a url that redirects is answered before any of its content is - and whatever that content is, it belongs to another url than the one declared here
    private function redirectIssue(array $details): array
    {
        $redirect = $details['redirect'] ?? null;

        return null === $redirect
            ? []
            : [$this->translator->trans('label.health_check_content_quality_redirects', ['%count%' => $redirect['count'], '%url%' => $redirect['finalUrl']], 'site')];
    }

    // The links of one page that the shared check found broken - internal and external are checked in the same pass (see checkBrokenLinks()) and only told apart here, by which of the analysis' two lists they came from
    private function describeBrokenLinks(array $entry, array $brokenLinks, string $key): array
    {
        $analysis = $entry['analysis'];
        $broken = array_values(array_filter($analysis[$key] ?? [], static fn (string $link) => $brokenLinks[$link] ?? false));

        return $this->describeLinks($entry['source'], $broken, $analysis['linkTexts'] ?? []);
    }

    // One short clause per offence, joined into the row's summary - built from the same "details" the advice builder reads, so the two can never disagree on what was found
    private function summarizeIssues(array $details): array
    {
        $issues = [];
        if ('missing' === $details['titleIssue']) {
            $issues[] = $this->translator->trans('label.health_check_content_quality_no_title', [], 'site');
        } elseif ('short' === $details['titleIssue']) {
            $issues[] = $this->translator->trans('label.health_check_content_quality_title_too_short', ['%length%' => $details['titleLength']], 'site');
        } elseif ('long' === $details['titleIssue']) {
            $issues[] = $this->translator->trans('label.health_check_content_quality_title_too_long', ['%length%' => $details['titleLength']], 'site');
        }
        // Named by the form's own label rather than "meta description": the clause is only useful if the user can tell which field to go and fill
        $descriptionField = ['%field%' => $this->translator->trans(self::DESCRIPTION_FIELD_LABEL, [], 'config')];
        if (!$details['hasDescription']) {
            $issues[] = $this->translator->trans('label.health_check_content_quality_no_description', $descriptionField, 'site');
        } elseif ('short' === $details['descriptionIssue']) {
            $issues[] = $this->translator->trans('label.health_check_content_quality_description_too_short', $descriptionField + ['%length%' => $details['descriptionLength']], 'site');
        } elseif ('long' === $details['descriptionIssue']) {
            $issues[] = $this->translator->trans('label.health_check_content_quality_description_too_long', $descriptionField + ['%length%' => $details['descriptionLength']], 'site');
        }
        // Several <h1> are valid HTML and Google copes with them, so this is a warning like the rest: what it costs is a screen reader announcing two top-level subjects for one page (typically the layout's own page title plus a "hero" block left on its h1 level - see Page::$isTitleDisplayed)
        if (0 === $details['h1Count']) {
            $issues[] = $this->translator->trans('label.health_check_content_quality_no_h1', [], 'site');
        } elseif ($details['h1Count'] > 1) {
            $issues[] = $this->translator->trans('label.health_check_content_quality_several_h1', ['%count%' => $details['h1Count']], 'site');
        }

        foreach (['missingSocialTags' => 'missing_social_tags', 'imagesWithoutAlt' => 'images_without_alt', 'brokenLinks' => 'broken_links', 'brokenExternalLinks' => 'broken_external_links'] as $key => $translationId) {
            if ($details[$key]) {
                $issues[] = $this->translator->trans('label.health_check_content_quality_' . $translationId, ['%count%' => \count($details[$key])], 'site');
            }
        }

        return $issues;
    }

    // The share tags absent from the page, minus the ones whose own source field is already reported missing on its own line: layout.html.twig only ever emits og:title/og:description alongside the title/description they mirror, so an empty field would otherwise be reported twice - once as the field to fill, once as the tag it didn't produce - and send the reader looking for two things to do where there is one. Both stay listed when their source is there, since the tag is then genuinely missing from the template
    private function missingSocialTags(array $analysis): array
    {
        $missing = array_diff(self::REQUIRED_SOCIAL_TAGS, array_keys($analysis['socialTags'] ?? []));
        $reportedAsTheirOwnField = array_merge(
            '' === (string) ($analysis['title'] ?? '') ? ['og:title'] : [],
            $analysis['hasDescription'] ? [] : ['og:description'],
        );

        return array_values(array_diff($missing, $reportedAsTheirOwnField));
    }

    // 'missing'/'short'/'long', or null when the length sits inside the recommended window
    private function lengthIssue(int $length, int $min, int $max): ?string
    {
        return match (true) {
            0 === $length => 'missing',
            $length < $min => 'short',
            $length > $max => 'long',
            default => null,
        };
    }

    // Each image listed with the screen holding it, so the Health check panel can show them one by one with a direct link to fix each - an unclaimed image (a theme/template one, a logo) keeps its row, just without a link, and so does every image of an entry with no source behind it at all
    private function describeImages(?object $source, array $sources): array
    {
        return array_map(function (string $src) use ($source): array {
            $origin = $this->offenceLocatorRegistry->locateImage($source, $src);

            return ['src' => $src, 'block' => $origin['label'] ?? null, 'editUrl' => $origin['editUrl'] ?? null];
        }, $sources);
    }

    // Same as describeImages(), plus the link's own anchor text - "Nos tarifs" says more about which link to fix than its url does
    private function describeLinks(?object $source, array $links, array $linkTexts): array
    {
        return array_map(function (string $link) use ($source, $linkTexts): array {
            $origin = $this->offenceLocatorRegistry->locateLink($source, $link);

            return ['url' => $link, 'text' => $linkTexts[$link] ?? null, 'block' => $origin['label'] ?? null, 'editUrl' => $origin['editUrl'] ?? null];
        }, $links);
    }
}
