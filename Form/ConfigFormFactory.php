<?php
/*
 * (c) 2018: 975L <contact@975l.com>
 * (c) 2018: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Form;

use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormFactoryInterface;
use c975L\ConfigBundle\Entity\Config;
use c975L\ConfigBundle\Form\ConfigType;
use c975L\ConfigBundle\Form\ConfigFormFactoryInterface;

/**
 * ConfigFormFactoryInterface class
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2018 975L <contact@975l.com>
 */
class ConfigFormFactory implements ConfigFormFactoryInterface
{
    public function __construct(FormFactoryInterface $formFactory)
    {
        $this->formFactory = $formFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function create(Config $config)
    {
        return $this->formFactory->create(ConfigType::class, $config);
    }
}
