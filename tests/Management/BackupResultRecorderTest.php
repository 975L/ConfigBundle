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
use c975L\ConfigBundle\Management\BackupResultRecorder;
use c975L\ConfigBundle\Repository\HealthCheckResultRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class BackupResultRecorderTest extends TestCase
{
    private ?HealthCheckResult $persisted = null;

    // A run with nothing wrong about it, each test overriding only what it's about
    private function outcome(array $overrides = []): array
    {
        return array_merge([
            'url' => 'https://example.com',
            'database' => 'example_db',
            'tables' => ['expected' => 24, 'dumped' => 24, 'missing' => []],
            'sqlBytes' => 13_000_000,
            'foldersMode' => 'partial',
            'foldersBytes' => 180_000,
            'foldersFiles' => 6,
            'archives' => [['name' => 'MYSQL.tar.bz2', 'bytes' => 13_000_000]],
            'durationSeconds' => 42,
            'retention' => ['days' => 15, 'deleted' => 1, 'freedBytes' => 100, 'runs' => 5, 'bytes' => 500, 'oldest' => '2026-07-14'],
            'errors' => [],
            'warnings' => [],
        ], $overrides);
    }

    // $previousSqlBytes: what the run before this one recorded, null when there is no previous run
    private function createRecorder(?int $previousSqlBytes = null): BackupResultRecorder
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted = $entity;
        });

        $previous = [];
        if (null !== $previousSqlBytes) {
            $previous = [(new HealthCheckResult())
                ->setKind(BackupResultRecorder::KIND)
                ->setUrl('https://example.com')
                ->setStatus(HealthCheckResult::STATUS_OK)
                ->setSummary('previous')
                ->setDetails(['sqlBytes' => $previousSqlBytes])
                ->setCheckedAt(new \DateTime('-1 day'))];
        }

        $repository = $this->createStub(HealthCheckResultRepository::class);
        $repository->method('findLatestByKind')->willReturn($previous);

        // The placeholders live in the translated targets, not in the ids, so the stub appends the parameters instead of substituting them - that's what lets a test assert on the values the summary is built from
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []) => $id . ('' === implode('', $parameters) ? '' : ' [' . implode(' ', $parameters) . ']')
        );

        return new BackupResultRecorder($entityManager, $repository, $translator);
    }

    public function testRecordPersistsABackupRowWithTheRunDetails(): void
    {
        $this->createRecorder()->record($this->outcome());

        $this->assertSame(BackupResultRecorder::KIND, $this->persisted->getKind());
        $this->assertSame('https://example.com', $this->persisted->getUrl());
        $this->assertSame(HealthCheckResult::STATUS_OK, $this->persisted->getStatus());
        $this->assertSame(24, $this->persisted->getDetails()['tables']['dumped']);
    }

    // The numbers a "backup ok" line never gives, and the only ones that tell an empty dump from a real one
    public function testSummaryCarriesTableCountArchiveSizeAndDuration(): void
    {
        $this->createRecorder()->record($this->outcome());

        $summary = $this->persisted->getSummary();
        $this->assertStringContainsString('24', $summary);
        $this->assertStringContainsString('12.4 MB', $summary);
        $this->assertStringContainsString('42 s', $summary);
    }

    public function testAnErroredRunIsRecordedAsError(): void
    {
        $this->createRecorder()->record($this->outcome(['errors' => ['mysqldump failed for table user']]));

        $this->assertSame(HealthCheckResult::STATUS_ERROR, $this->persisted->getStatus());
    }

    public function testAWarnedRunIsRecordedAsWarning(): void
    {
        $this->createRecorder()->record($this->outcome(['warnings' => ['Discarded empty file']]));

        $this->assertSame(HealthCheckResult::STATUS_WARNING, $this->persisted->getStatus());
    }

    // The failure no per-table error ever reports: every table dumped "successfully" into a truncated result
    public function testAnArchiveThatSuddenlyShrankIsRecordedAsWarning(): void
    {
        $this->createRecorder(13_000_000)->record($this->outcome(['sqlBytes' => 2_000_000]));

        $this->assertSame(HealthCheckResult::STATUS_WARNING, $this->persisted->getStatus());
        $this->assertNotEmpty($this->persisted->getDetails()['warnings']);
    }

    // A site does grow and shrink; only a collapse is worth an alert, not the ordinary week-to-week variation
    public function testAnArchiveOfComparableSizeStaysOk(): void
    {
        $this->createRecorder(13_000_000)->record($this->outcome(['sqlBytes' => 12_000_000]));

        $this->assertSame(HealthCheckResult::STATUS_OK, $this->persisted->getStatus());
    }

    // The very first run has nothing to compare against and must not be reported as a collapse
    public function testTheFirstRunEverIsNotComparedAgainstAnything(): void
    {
        $this->createRecorder()->record($this->outcome(['sqlBytes' => 10]));

        $this->assertSame(HealthCheckResult::STATUS_OK, $this->persisted->getStatus());
    }
}
