<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Entity\HealthCheckResult;
use c975L\ConfigBundle\Management\BackupDigestBuilder;
use c975L\ConfigBundle\Management\BackupResultRecorder;
use c975L\ConfigBundle\Repository\HealthCheckResultRepository;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class BackupDigestBuilderTest extends TestCase
{
    private function createRun(string $status, string $checkedAt, array $details = []): HealthCheckResult
    {
        return (new HealthCheckResult())
            ->setKind(BackupResultRecorder::KIND)
            ->setUrl('https://example.com')
            ->setStatus($status)
            ->setSummary('24 tables')
            ->setDetails(array_merge(['sqlBytes' => 1048576, 'errors' => [], 'warnings' => []], $details))
            ->setCheckedAt(new \DateTime($checkedAt));
    }

    // @param HealthCheckResult[] $runs in any order, the repository returning them newest first
    private function createBuilder(array $runs, array $configs = []): BackupDigestBuilder
    {
        usort($runs, static fn (HealthCheckResult $a, HealthCheckResult $b) => $b->getCheckedAt() <=> $a->getCheckedAt());

        $repository = $this->createStub(HealthCheckResultRepository::class);
        $repository->method('findByKindSince')->willReturn($runs);

        $values = array_merge(['site-url' => 'https://example.com', 'site-backup-max-age-hours' => '30'], $configs);
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $slug) => $values[$slug] ?? '');

        // Parameters substituted as the real translator would, so what the digest actually writes into the email is what gets asserted
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []) => $id . (empty($parameters) ? '' : ' ' . implode(' ', $parameters))
        );

        return new BackupDigestBuilder($repository, $configService, $translator);
    }

    // A week of healthy runs is what the email exists to confirm, the dashboard nobody opens daily being the only other place saying so
    public function testAWeekOfSuccessfulRunsIsReportedAsOk(): void
    {
        $digest = $this->createBuilder([
            $this->createRun(HealthCheckResult::STATUS_OK, '-2 hours'),
            $this->createRun(HealthCheckResult::STATUS_OK, '-8 hours'),
        ])->build();

        $this->assertSame(HealthCheckResult::STATUS_OK, $digest['status']);
        $this->assertStringContainsString('label.backup_digest_subject_ok', $digest['subject']);
        $this->assertStringContainsString('label.backup_digest_runs 2 2 0 0', $digest['body']);
    }

    // A single failure anywhere in the window must reach the subject line: the Monday report only ever covered its own run
    public function testOneFailedRunAmongSuccessesIsReportedAsAnError(): void
    {
        $digest = $this->createBuilder([
            $this->createRun(HealthCheckResult::STATUS_OK, '-2 hours'),
            $this->createRun(HealthCheckResult::STATUS_ERROR, '-20 hours', ['errors' => ['mysqldump failed for table user']]),
        ])->build();

        $this->assertSame(HealthCheckResult::STATUS_ERROR, $digest['status']);
        $this->assertStringContainsString('label.backup_digest_subject_error', $digest['subject']);
        $this->assertStringContainsString('mysqldump failed for table user', $digest['body']);
    }

    public function testAWarnedRunIsReportedAsAWarning(): void
    {
        $digest = $this->createBuilder([
            $this->createRun(HealthCheckResult::STATUS_OK, '-2 hours'),
            $this->createRun(HealthCheckResult::STATUS_WARNING, '-8 hours', ['warnings' => ['Discarded empty file']]),
        ])->build();

        $this->assertSame(HealthCheckResult::STATUS_WARNING, $digest['status']);
        $this->assertStringContainsString('label.backup_digest_subject_warning', $digest['subject']);
    }

    // The case the whole command is for: nothing ran at all, which is exactly what produces no email anywhere else
    public function testAWindowWithoutAnyRunIsReportedAsNone(): void
    {
        $digest = $this->createBuilder([])->build();

        $this->assertSame(BackupDigestBuilder::STATUS_NONE, $digest['status']);
        $this->assertStringContainsString('label.backup_digest_subject_none', $digest['subject']);
        $this->assertStringContainsString('label.backup_digest_none', $digest['body']);
        $this->assertSame(0, $digest['runs']);
    }

    // Runs that all succeeded but stopped three days ago are not an ok week, and every row's own status says otherwise
    public function testRunsThatStoppedPartWayThroughTheWindowAreReportedAsAnError(): void
    {
        $digest = $this->createBuilder([
            $this->createRun(HealthCheckResult::STATUS_OK, '-5 days'),
            $this->createRun(HealthCheckResult::STATUS_OK, '-6 days'),
        ])->build();

        $this->assertSame(HealthCheckResult::STATUS_ERROR, $digest['status']);
        $this->assertStringContainsString('label.backup_digest_gap', $digest['body']);
    }

    // A gap in the middle of an otherwise healthy week is a scheduler that stopped and restarted, and no row records it
    public function testAGapBetweenTwoRunsIsReported(): void
    {
        $digest = $this->createBuilder([
            $this->createRun(HealthCheckResult::STATUS_OK, '-1 hour'),
            $this->createRun(HealthCheckResult::STATUS_OK, '-4 days'),
        ])->build();

        $this->assertSame(HealthCheckResult::STATUS_ERROR, $digest['status']);
        $this->assertStringContainsString('label.backup_digest_gap', $digest['body']);
    }

    // Six hours between two six-hourly runs is the normal state of things, not news
    public function testARegularScheduleReportsNoGap(): void
    {
        $digest = $this->createBuilder([
            $this->createRun(HealthCheckResult::STATUS_OK, '-1 hour'),
            $this->createRun(HealthCheckResult::STATUS_OK, '-7 hours'),
            $this->createRun(HealthCheckResult::STATUS_OK, '-13 hours'),
        ])->build();

        $this->assertStringNotContainsString('label.backup_digest_gap', $digest['body']);
    }

    // Backups that started three days ago haven't skipped the four days before that
    public function testTheStretchBeforeTheFirstRunIsNotCountedAsAGap(): void
    {
        $digest = $this->createBuilder([
            $this->createRun(HealthCheckResult::STATUS_OK, '-1 hour'),
            $this->createRun(HealthCheckResult::STATUS_OK, '-7 hours'),
        ])->build();

        $this->assertSame(HealthCheckResult::STATUS_OK, $digest['status']);
        $this->assertStringNotContainsString('label.backup_digest_gap', $digest['body']);
    }

    // The shrink warning only compares a run against the one before it, so a slow drift is only visible from the two ends of the window
    public function testTheArchiveSizeIsComparedAcrossTheWholeWindow(): void
    {
        $digest = $this->createBuilder([
            $this->createRun(HealthCheckResult::STATUS_OK, '-2 hours', ['sqlBytes' => 2097152]),
            $this->createRun(HealthCheckResult::STATUS_OK, '-8 hours', ['sqlBytes' => 1048576]),
        ])->build();

        $this->assertStringContainsString('label.backup_digest_sql_trend 1.0 MB 2.0 MB', $digest['body']);
    }

    // The same failure repeated every six hours is one problem, and listing it 28 times is how a report stops being read
    public function testTheSameIssueRepeatedAcrossRunsIsListedOnceWithItsCount(): void
    {
        $digest = $this->createBuilder([
            $this->createRun(HealthCheckResult::STATUS_ERROR, '-2 hours', ['errors' => ['mysqldump failed for table user']]),
            $this->createRun(HealthCheckResult::STATUS_ERROR, '-8 hours', ['errors' => ['mysqldump failed for table user']]),
            $this->createRun(HealthCheckResult::STATUS_ERROR, '-14 hours', ['errors' => ['mysqldump failed for table user']]),
        ])->build();

        $this->assertSame(1, substr_count($digest['body'], 'mysqldump failed for table user'));
        $this->assertStringContainsString('label.backup_digest_issue mysqldump failed for table user 3', $digest['body']);
    }

    // What the server still holds, the digest saying a backup happened and this saying it's still there to restore from
    public function testTheRetentionOfTheLastRunIsReported(): void
    {
        $digest = $this->createBuilder([
            $this->createRun(HealthCheckResult::STATUS_OK, '-2 hours', ['retention' => ['days' => 15, 'deleted' => 1, 'runs' => 15, 'oldest' => '2026-07-14']]),
        ])->build();

        $this->assertStringContainsString('label.backup_digest_retention 15 15 2026-07-14', $digest['body']);
    }

    // Rows recorded by an earlier version of the bundle carry no details at all, and must not turn the digest into a crash
    public function testRunsWithoutDetailsAreStillReported(): void
    {
        $run = $this->createRun(HealthCheckResult::STATUS_OK, '-2 hours')->setDetails(null);

        $digest = $this->createBuilder([$run])->build();

        $this->assertSame(HealthCheckResult::STATUS_OK, $digest['status']);
        $this->assertStringContainsString('label.backup_digest_runs 1 1 0 0', $digest['body']);
    }

    // The dashboard alert and the weekly email disagreeing on what "late" means is how one of them ends up ignored
    public function testAnEmptyMaximumAgeFallsBackToTheAlertProvidersDefault(): void
    {
        $digest = $this->createBuilder([
            $this->createRun(HealthCheckResult::STATUS_OK, '-2 hours'),
            $this->createRun(HealthCheckResult::STATUS_OK, '-8 hours'),
        ], ['site-backup-max-age-hours' => ''])->build();

        $this->assertSame(HealthCheckResult::STATUS_OK, $digest['status']);
    }
}
