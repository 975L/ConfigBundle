<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Management;

use Symfony\Component\DependencyInjection\Attribute\When;

// Turns one path's DevProfileCollector numbers into the list of what's actually wrong with it, c975l:dev-profile:run only ever printing that list. Deliberately no threshold on the timings, nor on the cache hits/misses: a dev machine's milliseconds say nothing about production (APP_DEBUG, no opcache, no preloading), and the misses only say how warm the pools happened to be when the run started - both are carried in the report as context, never as an offence, unlike the counts (queries, deprecations, missing translations, external calls) which are the same numbers prod would produce
#[When('dev')]
class DevProfileAnalyzer
{
    public const LEVEL_ERROR = 'error';
    public const LEVEL_WARNING = 'warning';

    // A page whose rendering needs more queries than this is doing something the repositories should be doing in one go
    public const MAX_QUERIES = 30;
    public const MAX_QUERIES_ERROR = 60;

    // Two identical queries can legitimately happen (a shared fragment rendered twice); a dozen never can - that's an n+1
    public const MAX_DUPLICATE_QUERIES = 2;
    public const MAX_DUPLICATE_QUERIES_ERROR = 9;

    // Deliberately high: a block-based theme legitimately renders dozens of small templates per page (a real page of a c975L site sits between 45 and 110), so this only catches a genuine runaway - a template rendered per item inside a loop
    public const MAX_TEMPLATES = 150;

    // The sql of the worst offender is quoted in the report - kept short enough to stay readable in a terminal
    private const SQL_EXCERPT_LENGTH = 120;

    private const MAX_LISTED_ITEMS = 5;

    // One entry per offence: ['level' => self::LEVEL_*, 'area' => string, 'message' => string]. Empty means the page is clean
    public function analyze(array $metrics): array
    {
        if (null !== ($metrics['error'] ?? null)) {
            return [[
                'level' => self::LEVEL_ERROR,
                'area' => 'Kernel',
                'message' => sprintf('La requête a échoué : %s', $metrics['error']),
            ]];
        }

        $statusCode = $metrics['statusCode'] ?? 0;
        if (200 !== $statusCode) {
            return [$this->statusCodeIssue($statusCode)];
        }

        return array_merge(
            $this->analyzeQueryCount($metrics),
            $this->analyzeDuplicateQueries($metrics),
            $this->analyzeDeprecations($metrics),
            $this->analyzeTranslations($metrics),
            $this->analyzeRendering($metrics),
        );
    }

    // A redirect is usually the firewall answering instead of the page (an admin path, a page needing a login), so it's not an offence in itself - but nothing was profiled either, which the report has to say rather than showing a page with zero queries as clean
    private function statusCodeIssue(int $statusCode): array
    {
        if ($statusCode >= 300 && $statusCode < 400) {
            return [
                'level' => self::LEVEL_WARNING,
                'area' => 'Réponse',
                'message' => sprintf('Redirection (%d), page non analysée', $statusCode),
            ];
        }

        return [
            'level' => self::LEVEL_ERROR,
            'area' => 'Réponse',
            'message' => sprintf('La page répond %d', $statusCode),
        ];
    }

    private function analyzeQueryCount(array $metrics): array
    {
        $queries = $metrics['queries'] ?? 0;
        if ($queries <= self::MAX_QUERIES) {
            return [];
        }

        return [[
            'level' => $queries > self::MAX_QUERIES_ERROR ? self::LEVEL_ERROR : self::LEVEL_WARNING,
            'area' => 'Doctrine',
            'message' => sprintf('%d requêtes SQL (seuil %d) en %s ms', $queries, self::MAX_QUERIES, $metrics['queryTime'] ?? 0),
        ]];
    }

    private function analyzeDuplicateQueries(array $metrics): array
    {
        $duplicates = $metrics['duplicateQueries'] ?? 0;
        if ($duplicates <= self::MAX_DUPLICATE_QUERIES) {
            return [];
        }

        $worst = $metrics['worstDuplicateQuery'] ?? null;

        return [[
            'level' => $duplicates > self::MAX_DUPLICATE_QUERIES_ERROR ? self::LEVEL_ERROR : self::LEVEL_WARNING,
            'area' => 'Doctrine',
            'message' => sprintf(
                '%d requêtes identiques répétées (n+1)%s',
                $duplicates,
                null !== $worst ? sprintf(', dont %d fois : %s', $worst['count'], $this->excerpt($worst['sql'])) : ''
            ),
        ]];
    }

    private function analyzeDeprecations(array $metrics): array
    {
        $issues = [];

        $deprecations = $metrics['deprecations'] ?? 0;
        if ($deprecations > 0) {
            $issues[] = [
                'level' => self::LEVEL_WARNING,
                'area' => 'Dépréciations',
                'message' => sprintf('%d dépréciation(s)%s', $deprecations, $this->listed($metrics['deprecationMessages'] ?? [])),
            ];
        }

        $logErrors = $metrics['logErrors'] ?? 0;
        if ($logErrors > 0) {
            $issues[] = [
                'level' => self::LEVEL_ERROR,
                'area' => 'Logs',
                'message' => sprintf('%d erreur(s) loggée(s) pendant le rendu', $logErrors),
            ];
        }

        return $issues;
    }

    private function analyzeTranslations(array $metrics): array
    {
        $issues = [];

        $missing = $metrics['missingTranslations'] ?? 0;
        if ($missing > 0) {
            $issues[] = [
                'level' => self::LEVEL_ERROR,
                'area' => 'Traductions',
                'message' => sprintf('%d clé(s) sans traduction%s', $missing, $this->listed($metrics['missingTranslationKeys'] ?? [])),
            ];
        }

        $fallbacks = $metrics['fallbackTranslations'] ?? 0;
        if ($fallbacks > 0) {
            $issues[] = [
                'level' => self::LEVEL_WARNING,
                'area' => 'Traductions',
                'message' => sprintf('%d clé(s) servie(s) depuis la locale de repli', $fallbacks),
            ];
        }

        return $issues;
    }

    private function analyzeRendering(array $metrics): array
    {
        $issues = [];

        // Rendering a page must never wait on somebody else's server: whatever it needs from an api belongs in a command writing to the database, or at worst behind a cache
        $httpRequests = $metrics['httpRequests'] ?? 0;
        if ($httpRequests > 0) {
            $issues[] = [
                'level' => self::LEVEL_ERROR,
                'area' => 'HttpClient',
                'message' => sprintf('%d appel(s) HTTP externe(s) pendant le rendu', $httpRequests),
            ];
        }

        $templates = $metrics['templates'] ?? 0;
        if ($templates > self::MAX_TEMPLATES) {
            $issues[] = [
                'level' => self::LEVEL_WARNING,
                'area' => 'Twig',
                'message' => sprintf('%d templates rendus (seuil %d) en %s ms', $templates, self::MAX_TEMPLATES, $metrics['twigTime'] ?? 0),
            ];
        }

        return $issues;
    }

    // The first few offenders behind a count, appended to its message - the whole list would drown the report, and fixing the first ones usually removes the rest
    private function listed(array $items): string
    {
        if (!$items) {
            return '';
        }

        $shown = \array_slice($items, 0, self::MAX_LISTED_ITEMS);
        $remaining = \count($items) - \count($shown);

        return sprintf(' : %s%s', implode(', ', array_map([$this, 'excerpt'], $shown)), $remaining > 0 ? sprintf(' (+%d)', $remaining) : '');
    }

    private function excerpt(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > self::SQL_EXCERPT_LENGTH ? mb_substr($text, 0, self::SQL_EXCERPT_LENGTH) . '…' : $text;
    }
}
