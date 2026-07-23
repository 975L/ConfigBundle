<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

use Symfony\Contracts\Translation\TranslatorInterface;

// Merges the dashboard shortcuts contributed by every ShortcutProvider (bundles depending on ConfigBundle) into a single flat, ordered list - grouped by category internally (see ShortcutProviderInterface) purely to order same-themed tiles from different bundles (e.g. every "Export" shortcut) next to each other, with no visual separation between groups so the tiles stay one compact grid
class ShortcutBuilder
{
    // Fallback category for a shortcut not opting into one (see ShortcutProviderInterface::getShortcuts())
    private const OTHER_CATEGORY_LABEL = 'label.shortcuts_category_other';
    private const OTHER_CATEGORY_TRANSLATION_DOMAIN = 'config';

    public function __construct(
        private readonly iterable $shortcutProviders,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // Returns every shortcut, ordered by category (translated label) then by the shortcut's own (already translated) label - flat, so the template can render one grid without a heading per category
    public function getShortcuts(): array
    {
        $shortcuts = ProviderMerger::merge($this->shortcutProviders, fn (ShortcutProviderInterface $provider) => $provider->getShortcuts());

        $categories = [];
        foreach ($shortcuts as $shortcut) {
            $category = $shortcut['category'] ?? ['label' => self::OTHER_CATEGORY_LABEL, 'translation_domain' => self::OTHER_CATEGORY_TRANSLATION_DOMAIN];
            $key = $category['translation_domain'] . '.' . $category['label'];
            $categories[$key] ??= $category + ['shortcuts' => []];
            $categories[$key]['shortcuts'][] = $shortcut;
        }

        uasort($categories, fn (array $a, array $b) => strcasecmp(
            $this->translator->trans($a['label'], [], $a['translation_domain']),
            $this->translator->trans($b['label'], [], $b['translation_domain']),
        ));

        $sortedShortcuts = [];
        foreach ($categories as $category) {
            $categoryShortcuts = $category['shortcuts'];
            uasort($categoryShortcuts, fn (array $a, array $b) => strcasecmp($a['label'], $b['label']));
            array_push($sortedShortcuts, ...array_values($categoryShortcuts));
        }

        return $sortedShortcuts;
    }
}
