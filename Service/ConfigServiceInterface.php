<?php
/*
 * (c) 2018: 975L <contact@975l.com>
 * (c) 2018: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Service;

use Symfony\Component\Form\Form;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use c975L\ConfigBundle\Entity\Config;

/**
 * Interface to be called for DI for ConfigServiceInterface related services
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2018 975L <contact@975l.com>
 */
interface ConfigServiceInterface
{
    /**
     * Converts Config to array
     * @return array
     */
    public function convertToArray(Config $formaData);

    /**
     * Call ConfigFormFactory to create config form
     * @return Form
     */
    public function createForm(string $filename, string $bundle);

    /**
     * Returns config data for specified bundle
     */
    public function getConfig(string $filename, string $bundle);

    /**
     * Returns the Configuration class
     * @return ConfigurationInterface
     */
    public function getConfigurationClass(string $bundle);

    /**
     * Returns the configuration settings defined in the Configuration class
     * @return array
     */
    public function getConfigurationData(string $bundle);

    /**
     * Returns the name (root node) used for the bundle in configuration file
     * @return string
     */
    public function getConfigurationName(string $bundle);

    /**
     * Returns config data for whole file
     */
    public function getConfigGlobal(string $filename);

    /**
     * Returns the config folder
     * @return string
     */
    public function getFolder();

    /**
     * Writes config data for specified bundle to yaml file
     */
    public function setConfig(Form $form);
}
