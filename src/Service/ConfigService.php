<?php
/*
 * (c) 2018: 975L <contact@975l.com>
 * (c) 2018: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\ConfigBundle\Service;

use LogicException;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Finder\Finder;
use c975L\ConfigBundle\Entity\Config;
use Symfony\Component\Filesystem\Filesystem;
use c975L\SiteBundle\Service\ServiceToolsInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * ConfigService class
 * @author Laurent Marquet <laurent.marquet@laposte.net>
 * @copyright 2018 975L <contact@975l.com>
 */
class ConfigService implements ConfigServiceInterface
{
    /**
     * Used for configBundles.php
     * @var string
     */
    final public const CONFIG_FILE_PHP = 'configBundles.php';

    /**
     * Used for config_bundles.yaml
     * @var string
     */
    final public const CONFIG_FILE_YAML = 'config_bundles.yaml';

    public function __construct(
        /**
         * Stores ParameterBagInterface
         */
        private readonly ParameterBagInterface $params,
        /**
         * Stores ServiceToolsInterface
         */
        private readonly ServiceToolsInterface $serviceTools
    )
    {
    }

    /**
     * {@inheritdoc}
     */
    public function convertToArray(Config $config)
    {
        $values = get_object_vars($config);
        unset($values['configDataReserved']);

        //Converts yaml array to php array
        foreach ($values as $key => $value) {
            if (is_string($value) && ('[' == substr($value, 0, 1) || '{' == substr($value, 0, 1))) {
                $values[$key] = explode(',', trim($value, '[]{}'));
            }
        }

        return $values;
    }

    /**
     * {@inheritdoc}
     */
    public function getBundleConfig(string $bundle)
    {
        $file = $this->params->get('kernel.project_dir') . '/vendor/' . $bundle . '/config/bundle.yaml';

        if (is_file($file)) {
            $yamlBundleConfig = Yaml::parseFile($file);
            if (is_array($yamlBundleConfig)) {
                $config = new Config();
                $parameters = [];
                $roots = [];
                //Parses the yaml content
                foreach ($yamlBundleConfig as $rootKey => $rootValue) {
                    foreach ($rootValue as $key => $value) {
                        $config->$key = $value;
                        $config->$key['data'] = $value['default'];
                        $config->$key['root'] = $rootKey;

                        $parameters[$rootKey][] = $key;
                    }
                    $roots[] = $rootKey;
                }

                //Adds data used when writing file
                $config->configDataReserved = ['bundle' => $bundle, 'parameters' => $parameters, 'roots' => $roots];

                return $config;
            }
        }

        throw new LogicException('The bundle "' . $bundle . '" has not been defined. Check its name');
    }

    /**
     * {@inheritdoc}
     */
    public function getBundles()
    {
        $folder = $this->params->get('kernel.project_dir') . '/vendor/*/*';

        $bundlesConfigFiles = new Finder();
        $bundlesConfigFiles
            ->files()
            ->name('bundle.yaml')
            ->in($folder)
            ->sortByName()
        ;

        //Creates the bundles array
        $bundles = [];
        foreach ($bundlesConfigFiles as $bundleConfigFile) {
            $filename = $bundleConfigFile->getRealPath();
            $bundle = substr($filename, 0, strpos($filename, '/config'));
            $bundle = substr($bundle, strpos($bundle, 'vendor/') + 7);

            $bundles[$bundle] = $filename;
        }

        return $bundles;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig(string $bundle)
    {
        //Initializes config with data defined in bundle.yaml
        $config = $this->getBundleConfig($bundle);

        //Updates config with data defined in config_bundles.yaml
        $roots = $config->configDataReserved['roots'];
        foreach ($roots as $root) {
            $definedConfig = $this->getDefinedConfig($root);
            if (null !== $definedConfig) {
                foreach ($definedConfig as $key => $value) {
                    if (property_exists($config, $key)) {
                        $config->$key['data'] = $value;
                    }
                }
            }
        }

        return $config;
    }

    /**
     * {@inheritdoc}
     */
    public function getDefinedConfig(string $root)
    {
        $globalConfig = $this->getGlobalConfig();

        if (null !== $globalConfig && array_key_exists($root, $globalConfig)) {
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
        $file = $this->getConfigFolder() . self::CONFIG_FILE_YAML;
        if (is_file($file)) {
            $globalConfig = Yaml::parseFile($file, Yaml::PARSE_DATETIME);

            return $globalConfig;
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getCacheFolder()
    {
        return $this->params->get('kernel.cache_dir') . '/';
    }

    /**
     * {@inheritdoc}
     */
    public function getConfigFolder()
    {
        return $this->params->get('kernel.project_dir') . '/config/';
    }

    /**
     * {@inheritdoc}
     */
    public function getContainerParameter(string $parameter)
    {
        return $this->params->get($parameter);
    }

    /**
     * {@inheritdoc}
     */
    public function getParameter(string $parameter, ?string $bundle = null)
    {
        if (strpos($parameter, '.')) {
            $paramArray = explode('.', $parameter);
            $parameters = $this->getParametersCacheFile($paramArray[0], $bundle);

            if (null !== $parameters) {
                if (array_key_exists($paramArray[0], $parameters) && array_key_exists($paramArray[1], $parameters[$paramArray[0]])) {
                    return $parameters[$paramArray[0]][$paramArray[1]];
                }
            }
        }

        throw new LogicException('Parameter "' . $parameter . '" defined using c975L/ConfigBundle is not defined! Try to use the config Route');
    }

    /**
     * {@inheritdoc}
     */
    public function getParametersCacheFile(string $root, ?string $bundle = null)
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

                $defaultConfig = [];
                foreach ($bundleDefaultConfig as $key => $value) {
                    $defaultConfig[$key] = $value['default'];
                }
                $parameters = [$root => $defaultConfig];
                $this->writeYamlFile($parameters);
                $this->writePhpFile($parameters);

                return $parameters;
            //No bundle name provided
            } else {
                throw new LogicException("The config files are not created you should use `php bin/console config:create`");
            }

            //Wrong bundle name
            throw new LogicException('The file ' . $bundle . '/config/bundle.yaml could not be found!');
        }

        $parameters = include_once($file);

        return $parameters;
    }

    /**
     * {@inheritdoc}
     */
    public function hasParameter(string $parameter)
    {
        if (strpos($parameter, '.')) {
            $paramArray = explode('.', $parameter);
            $parameters = $this->getParametersCacheFile($paramArray[0]);

            if (null !== $parameters) {
                if (array_key_exists($paramArray[0], $parameters) && array_key_exists($paramArray[1], $parameters[$paramArray[0]])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function setConfig($data)
    {
        if ($data instanceof Form) {
            $data = $data->getData();
        }

        //Adds new values
        $newDefinedValues = $this->convertToArray($data);
        $globalConfig = $this->getGlobalConfig();
        $parameters = $data->configDataReserved['parameters'];
        foreach ($parameters as $key => $values) {
            if (is_array($values)) {
                foreach ($values as $value) {
                    if (array_key_exists($value, $newDefinedValues) && null !== $newDefinedValues[$value]) {
                        $globalConfig[$key][$value] = $newDefinedValues[$value];
                    }
                }
            }
        }

        //Writes files
        $this->writeYamlFile($globalConfig);
        $this->writePhpFile($globalConfig);

        //Creates flash
        $this->serviceTools->createFlash('text.config_updated', 'config');
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
        $yamlContent = str_replace(['\'"', '"\''], "'", $yamlContent);

        $fs = new Filesystem();
        $file = $this->getConfigFolder() . self::CONFIG_FILE_YAML;
        $fs->remove($file . '.bak');
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
        $phpContent = str_replace(['\'"', '"\''], "'", $phpContent);

        $fs->dumpFile($file, $phpContent);
    }
}
