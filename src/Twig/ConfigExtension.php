<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Twig;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ConfigExtension extends AbstractExtension
{
    public function __construct(
        private readonly ConfigServiceInterface $configsService,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('config', [$this, 'getConfig']),
        ];
    }

    public function getConfig(string $slug): mixed
    {
        return $this->configsService->get($slug);
    }
}