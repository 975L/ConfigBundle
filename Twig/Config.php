<?php
/*
 * (c) 2018: 975L <contact@975l.com>
 * (c) 2018: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Twig;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension to display the Config parameter using `config('YOUR_PARAMETER_NAME')
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2018 975L <contact@975l.com>
 */
class Config extends AbstractExtension
{
    public function __construct(
        /**
         * Stores ConfigServiceInterface
         */
        private readonly ConfigServiceInterface $configService
    )
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction(
            'config',
            $this->config(...)
        )];
    }

    /**
     * Returns the specified parameter
     * @return string
     */
    public function config($parameter)
    {
        $value = $this->configService->getParameter($parameter);

        return is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value;
    }
}
