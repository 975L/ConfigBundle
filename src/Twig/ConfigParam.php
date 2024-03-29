<?php
/*
 * (c) 2018: 975L <contact@975l.com>
 * (c) 2018: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Twig;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension to display the Container's parameter using `configParam('YOUR_PARAMETER_NAME')
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2018 975L <contact@975l.com>
 */
class ConfigParam extends AbstractExtension
{

    public function __construct(
        /**
         * Stores ParameterBagInterface
         */
        private readonly ParameterBagInterface $params
    )
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction(
            'configParam',
            $this->configParam(...)
        )];
    }

    /**
     * Returns the specified container's parameter
     * @return string
     */
    public function configParam($parameter)
    {
        $value = $this->container->getParameter($parameter);

        return is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value;
    }
}
