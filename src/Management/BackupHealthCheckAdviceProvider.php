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
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Translation\TranslatorInterface;

// Advice for the "backup" rows BackupResultRecorder writes - what failed, and how far back the server itself can still restore from, which is the question the archives being copied offsite and deleted made impossible to answer without an SSH session
class BackupHealthCheckAdviceProvider implements HealthCheckAdviceProviderInterface
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
    ) {
    }

    public function buildAdvice(array $results): array
    {
        $advice = [];

        foreach ($results as $result) {
            if (BackupResultRecorder::KIND !== $result->getKind()) {
                continue;
            }

            $lines = array_merge($this->problemLines($result), $this->retentionLines($result));
            if ($lines) {
                $advice[HealthCheckAdviceBuilder::key($result)] = $lines;
            }
        }

        return $advice;
    }

    // Errors and warnings as they were recorded, each collapsed under its own line rather than spelled out in the summary: a run that lost ten tables would otherwise push every other row of the table off the screen
    private function problemLines(HealthCheckResult $result): array
    {
        $details = $result->getDetails() ?? [];

        $lines = [];
        foreach ([['errors', 'label.health_check_advice_backup_errors'], ['warnings', 'label.health_check_advice_backup_warnings']] as [$key, $translationId]) {
            $messages = $details[$key] ?? [];
            if ($messages) {
                // The count is for every admin, its detail is not: these messages carry the tools' raw stderr, which names the backup user and host - both restricted to ROLE_SUPER_ADMIN in configs.json, the same separation BackupAlertProvider's 'role' key keeps
                $lines[] = $this->line(
                    $translationId,
                    ['%count%' => \count($messages)],
                    $this->security->isGranted('ROLE_SUPER_ADMIN') ? $messages : [],
                );
            }
        }

        return $lines;
    }

    // What is still on the server right now, so the offsite copy isn't the only answer to "how far back can I go?"
    private function retentionLines(HealthCheckResult $result): array
    {
        $retention = ($result->getDetails() ?? [])['retention'] ?? null;
        if (null === $retention || null === ($retention['oldest'] ?? null)) {
            return [];
        }

        return [$this->line('label.health_check_advice_backup_retention', [
            '%count%' => $retention['runs'],
            '%oldest%' => $retention['oldest'],
            '%days%' => $retention['days'],
        ])];
    }

    private function line(string $translationId, array $parameters, array $messages = []): array
    {
        return [
            'text' => $this->translator->trans($translationId, $parameters, 'config'),
            'url' => null,
            'items' => array_map(
                static fn (string $message): array => ['text' => $message, 'url' => null, 'label' => null],
                $messages,
            ),
        ];
    }
}
