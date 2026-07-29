<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

// Merges every provider's admin procedures, sorted by slug for an order independent of registration
class ProcedureBuilder
{
    public function __construct(
        private readonly iterable $procedureProviders,
    ) {
    }

    public function getAll(): array
    {
        $procedures = ProviderMerger::merge($this->procedureProviders, fn (ProcedureProviderInterface $provider) => $provider->getProcedures());
        usort($procedures, fn (array $a, array $b) => $a['slug'] <=> $b['slug']);

        return $procedures;
    }
}
