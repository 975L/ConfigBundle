<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\GuidedProjectKeyGenerator;
use c975L\ConfigBundle\Management\GuidedProjectMountBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class GuidedProjectMountBuilderTest extends TestCase
{
    private function createBuilder(string $key, string $url = '/management/guided-project/__SLUG__'): GuidedProjectMountBuilder
    {
        $keyGenerator = $this->createStub(GuidedProjectKeyGenerator::class);
        $keyGenerator->method('getKey')->willReturn($key);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn($url);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(fn (string $id) => 'translated:' . $id);

        return new GuidedProjectMountBuilder($keyGenerator, $urlGenerator, $translator);
    }

    public function testGetHtmlReturnsNothingWithoutALoggedInUser(): void
    {
        $this->assertSame('', $this->createBuilder('')->getHtml());
    }

    public function testGetHtmlMountsTheControllerCarryingTheKey(): void
    {
        $html = $this->createBuilder('a3f1c8d90b2e4f67')->getHtml();

        $this->assertStringContainsString('data-controller="guided-project"', $html);
        $this->assertStringContainsString('data-guided-project-key-value="a3f1c8d90b2e4f67"', $html);
    }

    // The panel only learns which project to fetch once it has read the browser's own storage, so the url ships with the slug left as a placeholder
    public function testGetHtmlKeepsTheSlugPlaceholderInTheStepsUrl(): void
    {
        $html = $this->createBuilder('a3f1c8d90b2e4f67')->getHtml();

        $this->assertStringContainsString('data-guided-project-url-value="/management/guided-project/' . GuidedProjectMountBuilder::SLUG_PLACEHOLDER . '"', $html);
    }

    // The panel is built in JavaScript, so its own chrome has to be translated on this side and travel with it
    public function testGetHtmlCarriesTheTranslatedPanelLabels(): void
    {
        $html = $this->createBuilder('a3f1c8d90b2e4f67')->getHtml();

        preg_match('/data-guided-project-labels-value="([^"]*)"/', $html, $matches);
        $labels = json_decode(htmlspecialchars_decode($matches[1], ENT_QUOTES), true);

        $this->assertSame('translated:label.guided_project_goto', $labels['goto']);
        $this->assertSame('translated:label.onboarding_next', $labels['next']);
        $this->assertArrayHasKey('resume', $labels);
        $this->assertArrayHasKey('replay', $labels);
    }

    // The labels ride in an HTML attribute, so their JSON has to come out escaped or a single quote in a translation would close it early
    public function testGetHtmlEscapesTheLabelsJsonForAnAttribute(): void
    {
        $keyGenerator = $this->createStub(GuidedProjectKeyGenerator::class);
        $keyGenerator->method('getKey')->willReturn('a3f1c8d90b2e4f67');

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/management/guided-project/__SLUG__');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('L\'"écran"');

        $html = (new GuidedProjectMountBuilder($keyGenerator, $urlGenerator, $translator))->getHtml();

        // Reading the attribute back up to its own closing quote proves it wasn't cut short by the one inside the label
        preg_match('/data-guided-project-labels-value="([^"]*)"/', $html, $matches);
        $labels = json_decode(htmlspecialchars_decode($matches[1], ENT_QUOTES), true);

        $this->assertSame('L\'"écran"', $labels['goto']);
    }
}
