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
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\DeploymentClient;
use c975L\ConfigBundle\Service\SiteUrlResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

// Two site-wide deployment checks nothing else covers, both silent when they break: that http:// actually redirects to https:// (a vhost/proxy setting no page-level check can see - the site itself answers perfectly well over https meanwhile), and that an unknown url answers a real 404 carrying the site's own error page (a soft 404 answering 200 has search engines index every typo as a page)
class DeploymentHealthCheckProvider implements HealthCheckProviderInterface
{
    // Deliberately a fixed url rather than a random one: the same path is probed on every run, so it reads as this check in the access logs instead of as a rotating stream of unexplained 404s
    private const NOT_FOUND_PROBE_PATH = '/c975l-health-check-404-probe';

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly SiteUrlResolver $siteUrlResolver,
        private readonly DeploymentClient $deploymentClient,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getKind(): string
    {
        return 'deployment';
    }

    public function runChecks(): array
    {
        $siteUrl = $this->siteUrlResolver->siteUrl();
        if (null === $siteUrl) {
            return [];
        }

        $rows = [];
        // Nothing to redirect *to* on a site not served over https in the first place - that's SslCertificateHealthCheckProvider's territory, and it skips itself the same way
        if (str_starts_with($siteUrl, 'https://')) {
            $rows[] = $this->checkHttpsRedirect($siteUrl);
        }

        $rows[] = $this->checkNotFoundPage($siteUrl);

        return $rows;
    }

    private function checkHttpsRedirect(string $siteUrl): array
    {
        $label = $this->translator->trans('label.health_check_deployment_https', [], 'config');
        $url = 'http://' . substr($siteUrl, \strlen('https://')) . '/';

        try {
            $response = $this->deploymentClient->fetchWithoutRedirect($url);
        } catch (\Throwable $e) {
            return $this->errorRow($url, $label, $e->getMessage());
        }

        if ($response['statusCode'] < 300 || $response['statusCode'] >= 400) {
            return $this->row($url, $label, HealthCheckResult::STATUS_ERROR, 'label.health_check_https_redirect_missing', ['%status%' => $response['statusCode']], ['issue' => 'https-redirect', 'statusCode' => $response['statusCode']]);
        }

        // A relative Location ("/") redirects within http, keeping the visitor unencrypted just as much as an explicit http:// target does
        if (!str_starts_with(strtolower((string) $response['location']), 'https://')) {
            return $this->row($url, $label, HealthCheckResult::STATUS_WARNING, 'label.health_check_https_redirect_insecure', [], ['issue' => 'insecure-redirect', 'location' => $response['location']]);
        }

        return $this->row($url, $label, HealthCheckResult::STATUS_OK, 'label.health_check_https_redirect_ok');
    }

    // "Custom" is a heuristic, same spirit as SeoFilesHealthCheckProvider's robots.txt one: an error page rendered inside the site's own layout carries the site name somewhere (header, footer, title), the framework's default one never does. It only ever downgrades an otherwise correct 404 to a warning, and is skipped altogether when no site-name is configured to match against
    private function checkNotFoundPage(string $siteUrl): array
    {
        $label = $this->translator->trans('label.health_check_deployment_not_found', [], 'config');
        $url = $siteUrl . self::NOT_FOUND_PROBE_PATH;

        try {
            $file = $this->deploymentClient->fetch($url);
        } catch (\Throwable $e) {
            return $this->errorRow($url, $label, $e->getMessage());
        }

        if (404 !== $file['statusCode']) {
            return $this->row($url, $label, HealthCheckResult::STATUS_ERROR, 'label.health_check_not_found_wrong_status', ['%status%' => $file['statusCode']], ['issue' => 'not-404', 'statusCode' => $file['statusCode']]);
        }

        $siteName = trim((string) $this->configService->get('site-name'));
        if ('' !== $siteName && !str_contains(mb_strtolower($file['content']), mb_strtolower($siteName))) {
            return $this->row($url, $label, HealthCheckResult::STATUS_WARNING, 'label.health_check_not_found_default_page', [], ['issue' => 'default-404']);
        }

        return $this->row($url, $label, HealthCheckResult::STATUS_OK, 'label.health_check_not_found_ok');
    }

    private function row(string $url, string $label, string $status, string $translationId, array $params = [], array $details = []): array
    {
        return [
            'url' => $url,
            'label' => $label,
            'status' => $status,
            'summary' => $this->translator->trans($translationId, $params, 'config'),
            'details' => $details,
        ];
    }

    private function errorRow(string $url, string $label, string $message): array
    {
        return [
            'url' => $url,
            'label' => $label,
            'status' => HealthCheckResult::STATUS_ERROR,
            'summary' => $this->translator->trans('label.health_check_deployment_call_failed', ['%message%' => $message], 'config'),
            'details' => ['error' => $message],
        ];
    }
}
