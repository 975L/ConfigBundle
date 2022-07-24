<?php
/*
 * (c) 2018: 975L <contact@975l.com>
 * (c) 2018: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension to display the Container's parameter using `configParam('YOUR_PARAMETER_NAME')
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2018 975L <contact@975l.com>
 */
class ConfigParam extends AbstractExtension
{
    public function getFunctions()
    {
        return array(
            new TwigFunction(
                'configParam',
                array($this, 'configParam')
            )
        );
    }

    /**
     * Returns the specified container's parameter
     * @return string
     */
    public function configParam($parameter)
    {
        $value = $this->container->getParameter($parameter);

        return is_array($value) ? json_encode($value) : $value;
    }
}
