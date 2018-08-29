ConfigBundle
=================

ConfigBundle does the following:

- get/set the config parameters from a yaml file for a Symfony app,
- Provides a Twig extension to get these parameters in a Twi template,

[ConfigBundle dedicated web page](https://975l.com/en/pages/config-bundle).

[ConfigBundle API documentation](https://975l.com/apidoc/c975L/ConfigBundle.html).

Bundle installation
===================

Step 1: Download the Bundle
---------------------------
Use [Composer](https://getcomposer.org) to install the library
```bash
    composer require c975l/config-bundle
```

Step 2: Enable the Bundles
--------------------------
Then, enable the bundle by adding it to the list of registered bundles in the `app/AppKernel.php` file of your project:

```php
<?php
class AppKernel extends Kernel
{
    public function registerBundles()
    {
        $bundles = [
            // ...
            new c975L\ConfigBundle\c975LConfigBundle(),
        ];
    }
}
```

Step 3: Override templates
--------------------------
It is strongly recommended to use the [Override Templates from Third-Party Bundles feature](http://symfony.com/doc/current/templating/overriding.html) to integrate fully with your site.

For this, simply, create the following structure `app/Resources/c975LConfigBundle/views/` in your app and then duplicate the file `layout.html.twig` in it, to override the existing Bundle file.

In `layout.html.twig`, it will mainly consist to extend your layout and define specific variables, i.e. :
```twig
{% extends 'layout.html.twig' %}

{# Defines specific variables #}
{% set title = 'Configuration' %}

{% block content %}
    {% block config_content %}
    {% endblock %}
{% endblock %}
```

How to use
----------

You just need to create a Controller + Voter and that's it!

**Take care to read the comment above `$form` definition in the Controller example below to be warned about where the data will be stored**

```php
<?php
//Your Controller file

namespace App\Bundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use c975L\ConfigBundle\Service\ConfigServiceInterface;

class YourController extends Controller
{
    /**
     * @Route("/your_name/config",
     *      name="your_name_config")
     * @Method({"GET", "HEAD", "POST"})
     */
    public function config(Request $request, ConfigServiceInterface $configService)
    {
        /**
         * Or you can use
         * if (null !== $this->getUser() && $this->get('security.authorization_checker')->isGranted('ROLE_ADMIN'))
         * but Voter are so powerful that you should not ;)
         */
        $this->denyAccessUnlessGranted('config', null);

        /**
         * Defines form
         * First argument is the yaml file that will store the data. You may use a separate file, not config.yaml
         * Just remember to import it in your config.yaml by adding - { resource: your_filename.yaml } at its top
         * As the configuration is now manageable directly from the web,
         * you may have to add your_filename.yaml to your .gitignore (+ .bak as a backup is made when saving)
         * Last argument is the name of your Bundle as defined in your namespace, i.e. c975L\EmailBundle
         */
        $form = $configService->createForm('your_filename.yaml', 'App\Bundle');
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            //Validates config
            $configService->setConfig($form);

            //Redirects
            return $this->redirectToRoute('email_dashboard');
        }

        //Renders the config form
        return $this->render('@c975LConfig/forms/config.html.twig', array(
            'form' => $form->createView(),
            'toolbar' => '@c975LEmail', //set false if you don't use c975L/ToolbarBundle
        ));
    }
```

```php
<?php
//Your Voter file

namespace App\Bundle\Security;

use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class YourVoter extends Voter
{
    private $decisionManager;
    private $roleNeeded; //this value is bind in the services.yml file

    public const CONFIG = 'config';

    private const ATTRIBUTES = array(
        self::CONFIG,
    );

    public function __construct(AccessDecisionManagerInterface $decisionManager, string $roleNeeded)
    {
        $this->decisionManager = $decisionManager;
        $this->roleNeeded = $roleNeeded;
    }

    protected function supports($attribute, $subject)
    {
        //Tests your subject
        if (null !== $subject) {
            return $subject instanceof YourEntityClass && in_array($attribute, self::ATTRIBUTES);
        }

        return in_array($attribute, self::ATTRIBUTES);
    }

    protected function voteOnAttribute($attribute, $subject, TokenInterface $token)
    {
        //Defines access rights
        switch ($attribute) {
            case self::CONFIG:
                return $this->decisionManager->decide($token, array($this->roleNeeded));
        }

        throw new \LogicException('Invalid attribute: ' . $attribute);
    }
}
```

Then call the defined Route in a web browser and set-up (or your user) the configuration parameters. Of course you can still access the file itself.

Read about [Configuration values](https://symfony.com/doc/current/components/config/definition.html) to see all the available options when defining parameter and also check the following code as an example:
```php
<?php
//Your Configuration file

namespace c975L\EmailBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('your_root');

        $rootNode
            ->children()
                ->scalarNode('yourParameter')
                    ->isRequired() //Will mark the field as required
                    ->cannotBeEmpty()
                    ->defaultValue() //Will display the default value in the form and register it in the file.yaml
                    ->info('Parameter information') //Will be displayed in field placeholder + console with config:dump-reference
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}

```

**Important**, when you install your Bundle for the first time, you **MUST** define manually, in your filename.yaml, your parameters that are declared as `isRequired` (even if empty, `defaultValue()` is not enough) and/or `cannotBeEmpty` (unless you provide a `defaultValue()`) as these parameters will be requested by Symfony and will throw an `InvalidConfigurationException` if not found.

Twig Extension
--------------
If you need to acces a parameter inside a Twig template, simply use `{{ config('your_root.pyour_parameter') }}`.

**If this project help you to reduce time to develop, you can [buy me a coffee](https://www.buymeacoffee.com/LaurentMarquet) :)**