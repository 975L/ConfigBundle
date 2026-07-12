<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Tests\DependencyInjection\Compiler;

// Fixture NOT implementing AlertProviderInterface, used by TaggedInterfacePassTest to verify the
// pass leaves it untagged - see TaggedInterfacePassFixtureProvider for why this is its own file
class TaggedInterfacePassFixtureOther
{
}
