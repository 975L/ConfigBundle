<?php
/*
 * (c) 2018: 975L <contact@975l.com>
 * (c) 2018: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Twig;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Twig extension to display the Config parameter using `config('YOUR_PARAMETER_NAME')
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2018 975L <contact@975l.com>
 */
class Config extends \Twig_Extension
{
    /**
     * Stores ContainerInterface
     * @var ContainerInterface
     */
    private $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function getFunctions()
    {
        return array(
            new \Twig_SimpleFunction(
                'config',
                array($this, 'config')
            )
        );
    }

    /**
     * Returns the specifid parameter
     * @return string
     */
    public function config($parameter)
    {
        if ($this->container->hasParameter($parameter)) {
            return is_array($this->container->getParameter($parameter)) ? json_encode($this->container->getParameter($parameter)) : $this->container->getParameter($parameter);
        }
    }
}