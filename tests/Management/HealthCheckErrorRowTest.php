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
use c975L\ConfigBundle\Management\HealthCheckErrorRow;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class HealthCheckErrorRowTest extends TestCase
{
    // Echoes back what it was asked to translate, so the test can assert on the id, the parameters and the domain the row was built with
    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = [], ?string $domain = null): string => $domain . '|' . $id . '|' . ($parameters['%message%'] ?? ''),
        );

        return $translator;
    }

    public function testBuildReturnsAnErrorRowCarryingTheMessage(): void
    {
        $row = HealthCheckErrorRow::build(
            $this->createTranslator(),
            'site',
            'https://example.com/page',
            'Home',
            'label.my_check_failed',
            'Connection timed out',
        );

        $this->assertSame('https://example.com/page', $row['url']);
        $this->assertSame('Home', $row['label']);
        $this->assertSame(HealthCheckResult::STATUS_ERROR, $row['status']);
        $this->assertSame(['error' => 'Connection timed out'], $row['details']);
        $this->assertNull($row['editUrl']);
    }

    // The summary is the calling bundle's wording, so the domain is a parameter rather than this bundle's own
    public function testBuildTranslatesTheSummaryInTheGivenDomain(): void
    {
        $row = HealthCheckErrorRow::build(
            $this->createTranslator(),
            'my-domain',
            'https://example.com',
            null,
            'label.my_check_failed',
            'Boom',
        );

        $this->assertSame('my-domain|label.my_check_failed|Boom', $row['summary']);
    }

    public function testBuildKeepsTheEditUrlWhenOneIsGiven(): void
    {
        $row = HealthCheckErrorRow::build(
            $this->createTranslator(),
            'config',
            'https://example.com',
            'Home',
            'label.my_check_failed',
            'Boom',
            '/management/config',
        );

        $this->assertSame('/management/config', $row['editUrl']);
    }
}
