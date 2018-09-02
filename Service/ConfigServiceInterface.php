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
    public function convertToArray(Config $config);

    /**
     * Call ConfigFormFactory to create config form
     * @return Form
     */
    public function createForm(string $bundle);

    /**
     * Returns config data for specified bundle
     */
    public function getConfig(string $bundle);

    /**
     * Returns the configuration settings defined in the bundle.yaml
     * @return Config
     * @throws \LogicException
     */
    public function getBundleConfig(string $bundle);

    /**
     * Returns the values defined for the configuration of the bundle
     * @return Config|null
     */
    public function getDefinedConfig(string $root);

    /**
     * Returns the global bundles definitions values
     * @return array|null
     */
    public function getGlobalConfig();

    /**
     * Returns the cache folder
     * @return string
     */
    public function getCacheFolder();

    /**
     * Returns the config folder
     * @return string
     */
    public function getConfigFolder();

    /**
     * Returns the value of parameter
     * @return mixed
     * @throws \LogicException
     */
    public function getParameter(string $parameter, string $bundle = null);

    /**
     * Returns the array of bundles parameters from cache file
     * @return array
     * @throws \LogicException
     */
    public function getParametersCacheFile(string $root, string $bundle = null);

    /**
     * Checks if parameter is set
     * @return bool
     */
    public function hasParameter(string $parameter);

    /**
     * Writes config data for specified bundle to yaml file
     */
    public function setConfig($data);

    /**
     * Writes configBundles.php
     */
    public function writePhpFile(array $globalConfig);

    /**
     * Writes config_bundles.yaml
     */
    public function writeYamlFile(array $globalConfig);
}
