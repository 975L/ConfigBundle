<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\DevProfileAnalyzer;
use PHPUnit\Framework\TestCase;

class DevProfileAnalyzerTest extends TestCase
{
    private DevProfileAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new DevProfileAnalyzer();
    }

    // A page with nothing wrong, every metric under its threshold - each test overrides only what it's about
    private function metrics(array $overrides = []): array
    {
        return $overrides + [
            'path' => '/',
            'label' => 'Accueil',
            'statusCode' => 200,
            'error' => null,
            'queries' => 8,
            'duplicateQueries' => 0,
            'worstDuplicateQuery' => null,
            'queryTime' => 3.2,
            'deprecations' => 0,
            'deprecationMessages' => [],
            'logErrors' => 0,
            'missingTranslations' => 0,
            'missingTranslationKeys' => [],
            'fallbackTranslations' => 0,
            'templates' => 12,
            'twigTime' => 20.0,
            'cacheHits' => 30,
            'cacheMisses' => 2,
            'cacheWrites' => 0,
            'httpRequests' => 0,
            'duration' => 180.0,
            'memory' => 12582912,
        ];
    }

    private function areas(array $issues): array
    {
        return array_map(static fn (array $issue): string => $issue['area'], $issues);
    }

    public function testAnalyzeReturnsNoIssueOnACleanPage(): void
    {
        $this->assertSame([], $this->analyzer->analyze($this->metrics()));
    }

    public function testAnalyzeReportsAKernelErrorAndNothingElse(): void
    {
        $issues = $this->analyzer->analyze($this->metrics(['error' => 'Service introuvable', 'queries' => 999]));

        $this->assertCount(1, $issues);
        $this->assertSame(DevProfileAnalyzer::LEVEL_ERROR, $issues[0]['level']);
        $this->assertSame('Kernel', $issues[0]['area']);
        $this->assertStringContainsString('Service introuvable', $issues[0]['message']);
    }

    public function testAnalyzeReportsARedirectionAsAWarningAndStopsThere(): void
    {
        $issues = $this->analyzer->analyze($this->metrics(['statusCode' => 302, 'queries' => 999]));

        $this->assertCount(1, $issues);
        $this->assertSame(DevProfileAnalyzer::LEVEL_WARNING, $issues[0]['level']);
        $this->assertStringContainsString('302', $issues[0]['message']);
    }

    public function testAnalyzeReportsANonRedirectStatusCodeAsAnError(): void
    {
        $issues = $this->analyzer->analyze($this->metrics(['statusCode' => 404]));

        $this->assertCount(1, $issues);
        $this->assertSame(DevProfileAnalyzer::LEVEL_ERROR, $issues[0]['level']);
        $this->assertSame('Réponse', $issues[0]['area']);
    }

    public function testAnalyzeWarnsThenErrorsOnTheQueryCount(): void
    {
        $this->assertSame([], $this->analyzer->analyze($this->metrics(['queries' => DevProfileAnalyzer::MAX_QUERIES])));

        $warning = $this->analyzer->analyze($this->metrics(['queries' => DevProfileAnalyzer::MAX_QUERIES + 1]));
        $this->assertCount(1, $warning);
        $this->assertSame(DevProfileAnalyzer::LEVEL_WARNING, $warning[0]['level']);
        $this->assertSame('Doctrine', $warning[0]['area']);

        $error = $this->analyzer->analyze($this->metrics(['queries' => DevProfileAnalyzer::MAX_QUERIES_ERROR + 1]));
        $this->assertSame(DevProfileAnalyzer::LEVEL_ERROR, $error[0]['level']);
    }

    public function testAnalyzeReportsDuplicateQueriesAndNamesTheWorstOffender(): void
    {
        $issues = $this->analyzer->analyze($this->metrics([
            'duplicateQueries' => 31,
            'worstDuplicateQuery' => ['sql' => 'SELECT t0.id FROM site_block t0 WHERE t0.page_id = ?', 'count' => 32],
        ]));

        $this->assertCount(1, $issues);
        $this->assertSame(DevProfileAnalyzer::LEVEL_ERROR, $issues[0]['level']);
        $this->assertStringContainsString('31 requêtes identiques', $issues[0]['message']);
        $this->assertStringContainsString('32 fois', $issues[0]['message']);
        $this->assertStringContainsString('site_block', $issues[0]['message']);
    }

    public function testAnalyzeToleratesAFewDuplicateQueries(): void
    {
        $this->assertSame([], $this->analyzer->analyze($this->metrics(['duplicateQueries' => DevProfileAnalyzer::MAX_DUPLICATE_QUERIES])));
    }

    public function testAnalyzeReportsDeprecationsWithTheirMessages(): void
    {
        $issues = $this->analyzer->analyze($this->metrics([
            'deprecations' => 2,
            'deprecationMessages' => ['Since symfony/framework-bundle 7.3: not doing that anymore'],
        ]));

        $this->assertCount(1, $issues);
        $this->assertSame(DevProfileAnalyzer::LEVEL_WARNING, $issues[0]['level']);
        $this->assertSame('Dépréciations', $issues[0]['area']);
        $this->assertStringContainsString('not doing that anymore', $issues[0]['message']);
    }

    public function testAnalyzeReportsMissingTranslationsAsAnErrorAndFallbacksAsAWarning(): void
    {
        $issues = $this->analyzer->analyze($this->metrics([
            'missingTranslations' => 1,
            'missingTranslationKeys' => ['label.legal (site)'],
            'fallbackTranslations' => 3,
        ]));

        $this->assertCount(2, $issues);
        $this->assertSame(DevProfileAnalyzer::LEVEL_ERROR, $issues[0]['level']);
        $this->assertStringContainsString('label.legal (site)', $issues[0]['message']);
        $this->assertSame(DevProfileAnalyzer::LEVEL_WARNING, $issues[1]['level']);
    }

    public function testAnalyzeReportsALoggedErrorAndAnExternalHttpCall(): void
    {
        $issues = $this->analyzer->analyze($this->metrics(['logErrors' => 1, 'httpRequests' => 2]));

        $this->assertSame(['Logs', 'HttpClient'], $this->areas($issues));
        $this->assertSame([DevProfileAnalyzer::LEVEL_ERROR, DevProfileAnalyzer::LEVEL_ERROR], array_column($issues, 'level'));
    }

    public function testAnalyzeWarnsOnTooManyTemplates(): void
    {
        $this->assertSame(['Twig'], $this->areas($this->analyzer->analyze($this->metrics(['templates' => DevProfileAnalyzer::MAX_TEMPLATES + 1]))));
    }

    // The timings and the cache counters are context, never an offence: a dev machine's milliseconds say nothing about production, and a run started on cold pools reports nothing but misses
    public function testAnalyzeNeverReportsATimingNorACacheMiss(): void
    {
        $this->assertSame([], $this->analyzer->analyze($this->metrics([
            'duration' => 9000.0,
            'queryTime' => 4000.0,
            'twigTime' => 3000.0,
            'memory' => 512 * 1024 * 1024,
            'cacheHits' => 0,
            'cacheMisses' => 300,
        ])));
    }

    // A count with more offenders behind it than the report shows says how many are left, rather than dropping them silently
    public function testAnalyzeCapsTheListedItemsAndSaysHowManyRemain(): void
    {
        $issues = $this->analyzer->analyze($this->metrics([
            'missingTranslations' => 8,
            'missingTranslationKeys' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'],
        ]));

        $this->assertStringContainsString('(+3)', $issues[0]['message']);
    }
}
