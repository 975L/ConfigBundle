<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\Management;

use c975L\ConfigBundle\Management\ShortcutBuilder;
use c975L\ConfigBundle\Management\ShortcutProviderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class ShortcutBuilderTest extends TestCase
{
    private function createProvider(array $shortcuts): ShortcutProviderInterface
    {
        $provider = $this->createStub(ShortcutProviderInterface::class);
        $provider->method('getShortcuts')->willReturn($shortcuts);

        return $provider;
    }

    // Translator double that returns the translation key untouched, so category order stays assertable
    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id) => $id);

        return $translator;
    }

    public function testGetShortcutsMergesEveryProvider(): void
    {
        $providerA = $this->createProvider([['label' => 'a']]);
        $providerB = $this->createProvider([['label' => 'b']]);
        $builder = new ShortcutBuilder([$providerA, $providerB], $this->createTranslator());

        $this->assertSame(['a', 'b'], array_column($builder->getShortcuts(), 'label'));
    }

    public function testGetShortcutsReturnsAFlatListWithNoCategoryWrapper(): void
    {
        $provider = $this->createProvider([['label' => 'a', 'category' => ShortcutProviderInterface::CATEGORY_EXPORT]]);
        $builder = new ShortcutBuilder([$provider], $this->createTranslator());

        $shortcuts = $builder->getShortcuts();

        $this->assertArrayNotHasKey('shortcuts', $shortcuts[0]);
        $this->assertSame('a', $shortcuts[0]['label']);
    }

    public function testGetShortcutsOrdersByCategoryThenByLabelButStaysFlat(): void
    {
        $provider = $this->createProvider([
            ['label' => 'z', 'category' => ShortcutProviderInterface::CATEGORY_SITE],
            ['label' => 'b', 'category' => ShortcutProviderInterface::CATEGORY_EXPORT],
            ['label' => 'a', 'category' => ShortcutProviderInterface::CATEGORY_EXPORT],
        ]);
        $builder = new ShortcutBuilder([$provider], $this->createTranslator());

        // CATEGORY_EXPORT sorts before CATEGORY_SITE (translated label), and within it "a" before "b" -
        // same-category tiles end up adjacent even though nothing marks the boundary between groups
        $this->assertSame(['a', 'b', 'z'], array_column($builder->getShortcuts(), 'label'));
    }

    public function testGetShortcutsFallsBackToOtherCategoryWhenUnset(): void
    {
        $providerOther = $this->createProvider([['label' => 'no-category']]);
        $providerExport = $this->createProvider([['label' => 'export-one', 'category' => ShortcutProviderInterface::CATEGORY_EXPORT]]);
        $builder = new ShortcutBuilder([$providerOther, $providerExport], $this->createTranslator());

        // "Export" sorts before the fallback "label.shortcuts_category_other" key
        $this->assertSame(['export-one', 'no-category'], array_column($builder->getShortcuts(), 'label'));
    }

    public function testGetShortcutsReturnsEmptyArrayWhenNoProviders(): void
    {
        $builder = new ShortcutBuilder([], $this->createTranslator());

        $this->assertSame([], $builder->getShortcuts());
    }
}
