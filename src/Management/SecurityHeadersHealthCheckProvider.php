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
use c975L\ConfigBundle\Management\HealthCheckProviderInterface;
use c975L\ConfigBundle\Service\SecurityHeadersClient;
use c975L\ConfigBundle\Service\SiteUrlResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

// Checks the site's HTTP response headers against the same short-list securityheaders.com grades on (HSTS, CSP, X-Frame-Options...) - reimplemented directly (see SecurityHeadersClient) rather than calling that site, which has no public API for automated use. These headers are set once for the whole site (server/app config), never per-page, so only the site root is actually fetched - checking every url would just repeat the exact same result once per url, and it is a site with no pages at all (a shop, a catalogue) that needs this check just as much
class SecurityHeadersHealthCheckProvider implements HealthCheckProviderInterface
{
    private const RECOMMENDED_HEADERS = [
        'strict-transport-security',
        'x-content-type-options',
        'content-security-policy',
        'referrer-policy',
        'permissions-policy',
        // Superseded by CSP's own "frame-ancestors" directive - either is accepted, see hasFrameAncestors()
        'x-frame-options',
    ];

    public function __construct(
        private readonly SecurityHeadersClient $securityHeadersClient,
        private readonly SiteUrlResolver $siteUrlResolver,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getKind(): string
    {
        return 'security-headers';
    }

    public function runChecks(): array
    {
        // Same spelling of the root as every other site-wide check, so all of them group on a single dashboard row
        $url = $this->siteUrlResolver->siteRoot();
        if (null === $url) {
            return [];
        }

        return [$this->checkPage($url, null)];
    }

    private function checkPage(string $url, ?string $label): array
    {
        try {
            $headers = $this->securityHeadersClient->fetchHeaders($url);
        } catch (\Throwable $e) {
            return HealthCheckErrorRow::build($this->translator, 'config', $url, $label, 'label.health_check_security_headers_call_failed', $e->getMessage());
        }

        $missing = $this->findMissingHeaders($headers);
        $corsWildcard = '*' === ($headers['access-control-allow-origin'] ?? null);

        return [
            'url' => $url,
            'label' => $label,
            'status' => $this->resolveStatus($missing, $corsWildcard),
            'summary' => $this->buildSummary($missing, $corsWildcard),
            'details' => ['headers' => $headers, 'missing' => $missing],
        ];
    }

    private function findMissingHeaders(array $headers): array
    {
        return array_values(array_filter(
            self::RECOMMENDED_HEADERS,
            fn (string $header) => !isset($headers[$header]) && ('x-frame-options' !== $header || !$this->hasFrameAncestors($headers)),
        ));
    }

    private function resolveStatus(array $missing, bool $corsWildcard): string
    {
        return match (true) {
            [] === $missing && !$corsWildcard => HealthCheckResult::STATUS_OK,
            \count($missing) >= 3 => HealthCheckResult::STATUS_ERROR,
            default => HealthCheckResult::STATUS_WARNING,
        };
    }

    private function buildSummary(array $missing, bool $corsWildcard): string
    {
        $total = \count(self::RECOMMENDED_HEADERS);
        $summary = $this->translator->trans('label.health_check_summary_security_headers', [
            '%present%' => $total - \count($missing),
            '%total%' => $total,
        ], 'config');
        $summary .= ' · ' . $this->translator->trans('label.health_check_security_headers_scope', [], 'config');

        if ($missing) {
            $summary .= ' · ' . $this->translator->trans('label.health_check_security_headers_missing', ['%headers%' => implode(', ', $missing)], 'config');
        }
        if ($corsWildcard) {
            $summary .= ' · ' . $this->translator->trans('label.health_check_security_headers_cors_wildcard', [], 'config');
        }

        return $summary;
    }

    // CSP's "frame-ancestors" directive supersedes the legacy X-Frame-Options header - either counts as clickjacking protection
    private function hasFrameAncestors(array $headers): bool
    {
        return str_contains($headers['content-security-policy'] ?? '', 'frame-ancestors');
    }
}
