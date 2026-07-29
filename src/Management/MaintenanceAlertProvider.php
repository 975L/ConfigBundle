<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

use c975L\ConfigBundle\Controller\Management\ConfigCrudController;
use c975L\ConfigBundle\Entity\Config;
use c975L\ConfigBundle\Repository\ConfigRepository;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// Alerts while the site is closed to its visitors (see MaintenanceListener), turning to danger once the outage has lasted long enough for search engines to act on it
class MaintenanceAlertProvider implements AlertProviderInterface
{
    // Days of uninterrupted maintenance after which search engines stop reading the 503 as temporary and start dropping the pages from their index
    private const DEINDEXING_RISK_DAYS = 2;

    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly ConfigServiceInterface $configService,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getAlerts(): array
    {
        $config = $this->configRepository->findOneBy(['slug' => 'site-maintenance']);

        // Nothing to say on a site open to its visitors
        if (null === $config || !$this->configService->getBool($config->getValue())) {
            return [];
        }

        $since = $this->maintenanceSince($config);
        $atRisk = $since < new \DateTime(sprintf('-%d days', self::DEINDEXING_RISK_DAYS));
        $severity = $atRisk ? Config::SEVERITY_DANGER : Config::SEVERITY_INFO;

        $alerts = [[
            'label' => $this->translator->trans('label.maintenance_alert', [], 'config'),
            'description' => $this->translator->trans(
                $atRisk ? 'description.maintenance_alert_long' : 'description.maintenance_alert',
                ['%date%' => $since->format('d/m/Y H:i')],
                'config',
            ),
            'severity' => $severity,
            'url' => $this->adminUrlGenerator
                ->unsetAll()
                ->setController(ConfigCrudController::class)
                ->setAction(Action::EDIT)
                ->setEntityId($config->getId())
                ->generateUrl(),
        ]];

        $previewUrl = $this->previewUrl();
        if (null !== $previewUrl) {
            $alerts[] = [
                'label' => $this->translator->trans('label.maintenance_preview_link', [], 'config'),
                'description' => $this->translator->trans('description.maintenance_preview_link', ['%url%' => $previewUrl], 'config'),
                'severity' => $severity,
                'url' => $previewUrl,
            ];
        }

        return $alerts;
    }

    // The token url anyone can be handed to visit the closed site (a client signing off on the work, a colleague testing it), the token opening a 6-hour session from the first page loaded with it. MaintenanceShortcutController fills the hash when the mode is switched on, so it's only ever missing here on a site closed by editing the entry by hand
    private function previewUrl(): ?string
    {
        $siteUrl = rtrim((string) $this->configService->get('site-url'), '/');
        $hash = (string) $this->configService->get('site-maintenance-hash');

        if ('' === $siteUrl || '' === $hash) {
            return null;
        }

        return sprintf('%s/?t=%s', $siteUrl, $hash);
    }

    // MaintenanceShortcutController stamps the modification date when the mode is toggled, so it dates the current outage. Editing the entry by hand resets it, at worst postponing the danger level; the column being non-nullable, the fallback only ever covers an entity built outside the database
    private function maintenanceSince(Config $config): \DateTimeInterface
    {
        return $config->getModification() ?? new \DateTime();
    }
}
