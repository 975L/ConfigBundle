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
use c975L\ConfigBundle\Management\HealthCheckProviderInterface;
use c975L\ConfigBundle\Service\SiteUrlResolver;
use c975L\ConfigBundle\Service\SslCertificateClient;
use Symfony\Contracts\Translation\TranslatorInterface;

// Checks the site's TLS certificate expiry - one check for the whole site (the certificate is issued for the host, not per-page), same "only the homepage/site-url matters" pattern as SecurityHeadersHealthCheckProvider. Auto-renewal (eg. Let's Encrypt/certbot) usually makes this a non-issue, but a silently broken renewal job is exactly the kind of failure that stays invisible until the certificate has already expired - this check is what would actually catch it
class SslCertificateHealthCheckProvider implements HealthCheckProviderInterface
{
    private const WARNING_THRESHOLD_DAYS = 30;
    private const ERROR_THRESHOLD_DAYS = 7;

    public function __construct(
        private readonly SiteUrlResolver $siteUrlResolver,
        private readonly SslCertificateClient $sslCertificateClient,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getKind(): string
    {
        return 'ssl-certificate';
    }

    public function runChecks(): array
    {
        // The site root as SiteUrlResolver spells it, not raw "site-url": the two spellings of that same root ("https://example.com" and "https://example.com/") would show as two rows on the Health check dashboard, which groups by url - and a site with pages resolves its home Page to this exact string
        $homeUrl = $this->siteUrlResolver->siteRoot();
        if (null === $homeUrl) {
            return [];
        }

        $host = parse_url($homeUrl, \PHP_URL_HOST);
        if (!$host || 'https' !== parse_url($homeUrl, \PHP_URL_SCHEME)) {
            return [[
                'url' => $homeUrl,
                'label' => null,
                'status' => HealthCheckResult::STATUS_SKIPPED,
                'summary' => $this->translator->trans('label.health_check_ssl_certificate_not_https', [], 'config'),
                'details' => [],
            ]];
        }

        try {
            $expiresAt = $this->sslCertificateClient->fetchExpiry($host);
        } catch (\Throwable $e) {
            return [[
                'url' => $homeUrl,
                'label' => null,
                'status' => HealthCheckResult::STATUS_ERROR,
                'summary' => $this->translator->trans('label.health_check_ssl_certificate_call_failed', ['%message%' => $e->getMessage()], 'config'),
                'details' => ['error' => $e->getMessage()],
            ]];
        }

        $daysLeft = (int) (new \DateTimeImmutable())->diff($expiresAt)->format('%r%a');

        return [[
            'url' => $homeUrl,
            'label' => null,
            'status' => $this->resolveStatus($daysLeft),
            'summary' => $this->translator->trans('label.health_check_summary_ssl_certificate', [
                '%days%' => $daysLeft,
                '%date%' => $expiresAt->format('Y-m-d'),
            ], 'config'),
            'details' => ['expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM), 'daysLeft' => $daysLeft],
        ]];
    }

    private function resolveStatus(int $daysLeft): string
    {
        return match (true) {
            $daysLeft <= self::ERROR_THRESHOLD_DAYS => HealthCheckResult::STATUS_ERROR,
            $daysLeft <= self::WARNING_THRESHOLD_DAYS => HealthCheckResult::STATUS_WARNING,
            default => HealthCheckResult::STATUS_OK,
        };
    }
}
