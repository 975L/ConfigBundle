<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\ConfigBundle\Management;

interface ProcedureProviderInterface
{
    // Each entry: ['slug' => string, 'title' => string, 'body' => string] - documents an admin workflow (e.g. "creer-page") for the dashboard AI assistant (see App\Service\AiHelpContextBuilder in the consuming app). 'slug' must be unique across every bundle contributing procedures
    public function getProcedures(): array;
}
