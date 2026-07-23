<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

// Merges the essential actions contributed by every EssentialActionProvider (ConfigBundle's own core actions, plus any bundle satellite adding its own), sorted by "order" - a deliberate sequence, not the alphabetical merge MenuBuilder/AlertBuilder use
class EssentialActionBuilder
{
    public function __construct(
        private readonly iterable $essentialActionProviders,
    ) {
    }

    // Every action, across every provider, sorted by "order"
    public function getActions(): array
    {
        $actions = ProviderMerger::merge($this->essentialActionProviders, fn (EssentialActionProviderInterface $provider) => $provider->getEssentialActions());

        usort($actions, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return $actions;
    }

    // {done: int, total: int} - drives the dashboard panel's collapsed/expanded state, it's never hidden entirely (see management/_essential_actions.html.twig)
    public function getProgress(): array
    {
        $actions = $this->getActions();

        return [
            'done' => \count(array_filter($actions, fn (array $action) => $action['isDone'])),
            'total' => \count($actions),
        ];
    }
}
