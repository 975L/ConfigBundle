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
use Symfony\Contracts\Translation\TranslatorInterface;

// What to do about the "database-load" row DatabaseLoadHealthCheckProvider writes. A rate on its own tells nobody where to look, and the counters answer the one question that decides it: whether the load follows the traffic or exists without it - a background process polling the database costs exactly as much at 4am as at noon, and no amount of reading page code will ever show it
class DatabaseLoadHealthCheckAdviceProvider implements HealthCheckAdviceProviderInterface
{
    // Past this share of the average, what the server does during the run's own few seconds is what it does all the time - the weekly run happening at night (see the scaffold's MaintenanceSchedule), that floor is what runs with nobody on the site
    private const BACKGROUND_SHARE = 0.7;

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildAdvice(array $results): array
    {
        $advice = [];

        foreach ($results as $result) {
            if (DatabaseLoadHealthCheckProvider::KIND !== $result->getKind()) {
                continue;
            }

            $details = $result->getDetails() ?? [];
            $lines = array_merge(
                $this->emptyTransactionLines($details, $result->getStatus()),
                $this->backgroundLines($details),
                $this->serverLines($details),
            );

            if ($lines) {
                $advice[HealthCheckAdviceBuilder::key($result)] = $lines;
            }
        }

        return $advice;
    }

    // Said on the row's own verdict rather than on a threshold of its own: DatabaseLoadHealthCheckProvider already decides what an acceptable share of empty transactions is, and a second copy of that number here would drift away from it
    private function emptyTransactionLines(array $details, string $status): array
    {
        $judged = $details['window'] ?? $details['instant'] ?? null;
        if (HealthCheckResult::STATUS_WARNING !== $status || null === $judged) {
            return [];
        }

        return [$this->line('label.health_check_advice_database_load_empty', [
            '%empty%' => (int) round(100 * $judged['emptyShare']),
            '%rate%' => $judged['transactions'],
            '%writes%' => $judged['writes'],
        ])];
    }

    // The question the ratio "transactions per http request" cannot answer, and gets wrong: a transaction opened by a worker belongs to no request at all
    private function backgroundLines(array $details): array
    {
        $instant = $details['instant'] ?? null;
        $window = $details['window'] ?? null;
        if (null === $instant || null === $window || $window['transactions'] <= 0) {
            return [];
        }

        $share = $instant['transactions'] / $window['transactions'];
        if ($share < self::BACKGROUND_SHARE) {
            return [];
        }

        return [$this->line('label.health_check_advice_database_load_background', [
            '%rate%' => $instant['transactions'],
            '%seconds%' => $instant['seconds'],
            '%share%' => (int) round(100 * $share),
        ])];
    }

    // Context the same reading gives for free, each on its own line so a real problem isn't buried inside the transaction one
    private function serverLines(array $details): array
    {
        $window = $details['window'] ?? null;
        if (null === $window) {
            return [];
        }

        $lines = [];
        foreach ([['slowQueries', 'label.health_check_advice_database_load_slow'], ['lockWaits', 'label.health_check_advice_database_load_locks'], ['abortedConnects', 'label.health_check_advice_database_load_aborted']] as [$key, $translationId]) {
            if (($window[$key] ?? 0) > 0) {
                $lines[] = $this->line($translationId, ['%count%' => $window[$key], '%days%' => $window['days']]);
            }
        }

        return $lines;
    }

    private function line(string $translationId, array $parameters): array
    {
        return [
            'text' => $this->translator->trans($translationId, $parameters, 'config'),
            'url' => null,
        ];
    }
}
