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
use Symfony\Component\Filesystem\Filesystem;
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

    /**
     * Used for configBundles.php
     * @var string
     */
    public const CONFIG_FILE_PHP = 'configBundles.php';

    /**
     * Used for config_bundles.yaml
     * @var string
     */
    public const CONFIG_FILE_YAML = 'config_bundles.yaml';

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
    public function createForm(string $bundle)
    {
        $config = $this->getConfig($bundle);

        return $this->configFormFactory->create($config);
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig(string $bundle)
    {
        //Initializes config with data defined in bundle.yaml
        $config = $this->getBundleConfig($bundle);

        //Updates config with data defined in config_bundles.yaml
        $definedConfig = $this->getDefinedConfig($config->configDataReserved['root']);
        if (null !== $definedConfig) {
            foreach ($definedConfig as $key => $value) {
                $config->$key['data'] = $value;
            }
        }

        return $config;
    }

    /**
     * {@inheritdoc}
     */
    public function getBundleConfig(string $bundle)
    {
        $file = $this->container->getParameter('kernel.root_dir') . '/../vendor/' . $bundle . '/Resources/config/bundle.yaml';

        if (is_file($file)) {
            $yamlBundleConfig = Yaml::parseFile($file);
            if (is_array($yamlBundleConfig)) {
                reset($yamlBundleConfig);
                $root = key($yamlBundleConfig);

                //Defines config
                $config = new Config();
                foreach ($yamlBundleConfig[$root] as $key => $value) {
                    $config->$key = $value;
                    $config->$key['data'] = $value['default'];
                }

                //Adds data used when writing file
                $config->configDataReserved = array(
                    'bundle' => $bundle,
                    'root' => $root,
                );

                return $config;
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getDefinedConfig(string $root)
    {
        static $definedConfig;

        if (null !== $definedConfig) {
            return $definedConfig;
        }

        $globalConfig = $this->getGlobalConfig();

        if (null !== $globalConfig && isset($globalConfig[$root])) {
            $definedConfig = $globalConfig[$root];

            return $definedConfig;
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getGlobalConfig()
    {
        static $globalConfig;

        if (null !== $globalConfig) {
            return $globalConfig;
        }

        $file = $this->getConfigFolder() . self::CONFIG_FILE_YAML;
        if (is_file($file)) {
            $globalConfig = Yaml::parseFile($file);

            return $globalConfig;
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getCacheFolder()
    {
        return $this->container->getParameter('kernel.cache_dir') . '/';
    }

    /**
     * {@inheritdoc}
     */
    public function getConfigFolder()
    {
        if ('4' === substr(\Symfony\Component\HttpKernel\Kernel::VERSION, 0, 1)) {
            return $this->container->getParameter('kernel.root_dir') . '/../config/packages/';
        }

        return $this->container->getParameter('kernel.root_dir') . '/../app/config/';
    }

    /**
     * {@inheritdoc}
     */
    public function getParameter(string $parameter, string $bundle = null)
    {
        if (strpos($parameter, '.')) {
            $paramArray = explode('.', $parameter);
            $parameters = $this->getParametersCacheFile($paramArray[0], $bundle);

            if (null !== $parameters) {
                if (isset($parameters[$paramArray[0]][$paramArray[1]])) {
                    return $parameters[$paramArray[0]][$paramArray[1]];
                }
            }
        }

        throw new \LogicException('Parameter "' . $parameter . '" defined using c975L/ConfigBundle is not defined!');
    }

    /**
     * {@inheritdoc}
     */
    public function getParametersCacheFile(string $root, string $bundle = null)
    {
        static $parameters;

        if (null !== $parameters) {
            return $parameters;
        }

        //Creates cache file if not existing
        $file = $this->getCacheFolder() . self::CONFIG_FILE_PHP;
        if (!is_file($file)) {
            //Gets data from config_bundles.yaml
            $globalConfig = $this->getGlobalConfig();
            if (is_array($globalConfig)) {
                $this->writePhpFile($globalConfig);
                $parameters = $globalConfig;

                return $parameters;
            //Gets data from bundle.yaml
            } elseif (null !== $bundle) {
                $bundleDefaultConfig = $this->convertToArray($this->getBundleConfig($bundle));

                $defaultConfig = array();
                foreach ($bundleDefaultConfig as $key => $value) {
                    $defaultConfig[$key] = $value['default'];
                }
                $parameters = array($root => $defaultConfig);
                $this->writeYamlFile($parameters);
                $this->writePhpFile($parameters);

                return $parameters;
            //No bundle name provided
            } else {
                throw new \LogicException("The config files are not created you should use `getParameter('yourRoot.yourParameter', 'vendor/bundle-name')`");
            }

            //Wrong bundle name
            throw new \LogicException('The file ' . $bundle . '/Resources/config/bundle.yaml could not be found!');
        }

        $parameters = include_once($file);

        return $parameters;
    }

    /**
     * {@inheritdoc}
     */
    public function setConfig(Form $form)
    {
        $formData = $form->getData();

        //Adds new values
        $newDefinedValues = $this->convertToArray($formData);
        $globalConfig = $this->getGlobalConfig();
        if (null !== $globalConfig) {
            $globalConfig[$formData->configDataReserved['root']] = $newDefinedValues;
        } else {
            $globalConfig = $newDefinedValues;
        }

        //Writes files
        $this->writeYamlFile($globalConfig);
        $this->writePhpFile($globalConfig);

        //Creates flash
        $this->serviceTools->createFlash('config', 'text.config_updated');
    }

    /**
     * {@inheritdoc}
     */
    public function writeYamlFile(array $globalConfig)
    {
        $yamlContent = Yaml::dump($globalConfig, 2, 4, Yaml::DUMP_EXCEPTION_ON_INVALID_TYPE);

        //Removes quotes around array otherwise yaml will not see it as an array
        $yamlContent = preg_replace("/'({.*})'/", '$1', $yamlContent);
        $yamlContent = preg_replace("/'(\[.*\])'/", '$1', $yamlContent);

        $fs = new Filesystem();
        $file = $this->getConfigFolder() . self::CONFIG_FILE_YAML;
        if ($fs->exists($file)) {
            $fs->rename($file, $file . '.bak');
        }
        $fs->dumpFile($file, $yamlContent);
    }

    /**
     * {@inheritdoc}
     */
    public function writePhpFile(array $globalConfig)
    {
        $fs = new Filesystem();
        $file = $this->getCacheFolder() . self::CONFIG_FILE_PHP;
        $phpContent = "<?php\nreturn " . var_export($globalConfig, true) . ';';
        $fs->dumpFile($file, $phpContent);
    }
}