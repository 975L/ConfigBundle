<?php
/*
 * (c) 2018: 975L <contact@975l.com>
 * (c) 2018: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Service;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Form\Form;
use Symfony\Component\Yaml\Yaml;
use c975L\ServicesBundle\Service\ServiceToolsInterface;
use c975L\ConfigBundle\Entity\Config;
use c975L\ConfigBundle\Form\ConfigFormFactoryInterface;
use c975L\ConfigBundle\Service\ConfigServiceInterface;

/**
 * ConfigService class
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2018 975L <contact@975l.com>
 */
class ConfigService implements ConfigServiceInterface
{
    /**
     * Stores ConfigFormFactoryInterface
     * @var ConfigFormFactoryInterface
     */
    private $configFormFactory;

    /**
     * Stores ContainerInterface
     * @var ContainerInterface
     */
    private $container;

    /**
     * Stores ServiceToolsInterface
     * @var ServiceToolsInterface
     */
    private $serviceTools;

    public function __construct(
        ContainerInterface $container,
        ConfigFormFactoryInterface $configFormFactory,
        ServiceToolsInterface $serviceTools
    )
    {
        $this->configFormFactory = $configFormFactory;
        $this->container = $container;
        $this->serviceTools = $serviceTools;
    }

    /**
     * {@inheritdoc}
     */
    public function convertToArray(Config $formData)
    {
        $values = get_object_vars($formData);
        unset($values['configDataReserved']);

        return $values;
    }

    /**
     * {@inheritdoc}
     */
    public function createForm(string $filename, string $bundle)
    {
        return $this->configFormFactory->create($this->getConfig($filename, $bundle));
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig(string $filename, string $bundle)
    {
        //Initializes config with data defined in Configuration class
        $config = $this->getConfigurationData($bundle);
        $bundleName = $this->getConfigurationName($bundle);

        //Updates config with data defined in yaml file
        $yamlDefinedValues = $this->getConfigGlobal($filename)[$bundleName];
        foreach ($yamlDefinedValues as $key => $value) {
            $config->$key['data'] = $value;
        }

        //Adds data used when writing file
        $config->configDataReserved = array(
            'filename' => $filename,
            'bundle' => $bundle,
            'bundleName' => $bundleName,
            );

        return $config;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfigurationClass(string $bundle)
    {
        $configuration = '\\' . $bundle . '\DependencyInjection\Configuration';
        return new $configuration();
    }

    /**
     * {@inheritdoc}
     */
    public function getConfigurationData(string $bundle)
    {
        $configuration = $this->getConfigurationClass($bundle);

        $definedValues = $configuration->getConfigTreeBuilder()
            ->buildTree()
            ->getChildren();

        $config = new Config();
        foreach ($definedValues as $key => $value) {
            $config->$key = array(
                'type' => str_replace('Symfony\Component\Config\Definition\\', '', get_class($value)),
                'required' => $value->isRequired(),
                'data' => $value->getDefaultValue(),
                'info' => $value->getInfo(),
            );
        }

        return $config;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfigurationName(string $bundle)
    {
        $configuration = $this->getConfigurationClass($bundle);

        return $configuration->getConfigTreeBuilder()
            ->buildTree()
            ->getName();
    }

    /**
     * {@inheritdoc}
     */
    public function getConfigGlobal(string $filename)
    {
        return Yaml::parseFile($this->getFolder() . $filename);
    }

    /**
     * {@inheritdoc}
     */
    public function getFolder()
    {
        return $this->container->getParameter('kernel.root_dir') . '/../app/config/';
    }

    /**
     * {@inheritdoc}
     */
    public function setConfig(Form $form)
    {
        //Defines data
        $formData = $form->getData();
        $filename = $formData->configDataReserved['filename'];
        $bundle = $formData->configDataReserved['bundle'];
        $bundleName = $formData->configDataReserved['bundleName'];

        //Adds new values
        $newDefinedValues = $this->convertToArray($formData);
        $yamlDefinedValues = $this->getConfigGlobal($filename);
        $yamlDefinedValues[$bundleName] = $newDefinedValues;

        //Defines new yaml content
        $yamlContent = Yaml::dump($yamlDefinedValues, 2, 4, Yaml::DUMP_EXCEPTION_ON_INVALID_TYPE);

        //Removes quotes around array otherwise yaml will not see it as an array
        $yamlContent = preg_replace("/'({.*})'/", '$1', $yamlContent);
        $yamlContent = preg_replace("/'(\[.*\])'/", '$1', $yamlContent);

        //Backups old file and writes new file
        $file = $folder = $this->getFolder() . $filename;
        if (is_file($file)) {
            rename($file, $file . '.bak');
        }
        file_put_contents($file, $yamlContent);

        //Creates flash
        $this->serviceTools->createFlash('config', 'text.config_updated');

    }
}