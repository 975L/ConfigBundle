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
use c975L\ConfigBundle\Entity\Config;

/**
 * Interface to be called for DI for ConfigFormFactoryInterface related services
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2018 975L <contact@975l.com>
 */
interface ConfigFormFactoryInterface
{
    /**
     * Returns the config form
     * @return Form
     */
    public function create(Config $config);
}
