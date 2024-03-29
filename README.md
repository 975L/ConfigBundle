# ConfigBundle

Please not that **this Bundle >= v2.0 doesn't use the `Configuration` class to build the modify form for parameters, but its own defined system of key-value. See branch 1.x for the use case with `Configuration` class.**

ConfigBundle does the following:

- Gets the config parameters definition from a yaml file for a Symfony app,
- Build a form to allow end-user to modify these parameters (acces-rights are checked on your side),
- Provides a Twig extension to get these parameters values in a Twig template,

[ConfigBundle dedicated web page](https://975l.com/en/pages/config-bundle).

[ConfigBundle API documentation](https://975l.com/apidoc/c975L/ConfigBundle.html).

## Bundle installation

### Step 1: Download the Bundle

Use [Composer](https://getcomposer.org) to install the library

```bash
    composer require c975l/config-bundle
```

### Step 2: Enable the Bundles

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

### Step 3: Override templates

It is strongly recommended to use the [Override Templates from Third-Party Bundles feature](http://symfony.com/doc/current/templating/overriding.html) to integrate fully with your site.

For this, simply, create the following structure `templates/bundles/c975LConfigBundle/views/` in your app and then duplicate the file `layout.html.twig` in it, to override the existing Bundle file.

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

### How to use

In your Bundle, you need to create a file `/config/bundle.yaml` (description of the needed fields) + Controller (Route to access config form) + Voter (Checking for access rights) and that's it! Code examples are given below.

Before the first use, if parameters are requested you must use the console command `php bin/console config:create` to create the config files from default data of the bundle.

When updating the configuration, two files are created:

- `config/config_bundles.yaml` that contains the values for defined fields, **You must add this file to your `.gitignore` to ensure not storing data, like API keys, to a public/private repository**
- `cache/dev|prod|test/configBundles.php` that contains an associative array of the fields `'yourRoot.yourParameter' => 'value'`.

```yml
#Your config/bundle.yaml
#Example of definition for parameter c975LEmail.roleNeeded
yourRoot: #Name of your bundle without its 'Bundle' part, but including its vendor one, to keep its uniqueness, i.e. c975LEmail
    yourParameter: #The name or your parameter i.e. roleNeeded
        type: string #|bool|int|float|array|date
        required: true #|false
        default: "Your default value" #|null
        info: "Your description to help filling this parameter" #|null
#The following options are specific for date type to define its range of years
        #startYear: 2010|current|null(or not set)
        #endYear: 2010|current|null(or not set)
#In case you need to have common data shared, you can also add other roots with the scheme
#yourCommonRoot
    #yourCommonParameter
        #...
```

Then your Controller file:

```php
<?php
//Your Controller file

namespace App\Bundle\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use c975L\ConfigBundle\Service\ConfigServiceInterface;

class YourController extends AbstractController
{
    /**
     * @Route("/your_name/config",
     *    name="your_name_config",
     *    methods={"HEAD", "GET", "POST"})
     */
    public function config(Request $request, ConfigServiceInterface $configService)
    {
        //Add the case to your Voter
        $this->denyAccessUnlessGranted('config', 'yourDataIfNeeded');

        $form = $configService->createForm('vendor/bundle-name');//As defined in your composer.json
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            //Validates config
            $configService->setConfig($form);

            //Redirects
            return $this->redirectToRoute('the_route_you_want_to_redirect_to');
        }

        //Renders the config form
        return $this->render('@c975LConfig/forms/config.html.twig', array(
            'form' => $form->createView(),
            'toolbar' => '@c975LEmail', //set false (or remove) if you don't use c975L/ToolbarBundle
        ));
    }
```

Then call the defined Route in a web browser and set-up (or your user) the configuration parameters.

### Get parameter inside a class

To get a parameter inside a class, use the following code:

```php
<?php
namespace Your\NameSpace;

use c975L\ConfigBundle\Service\ConfigServiceInterface;

class YourClass
{
    protected function yourMethod(ConfigServiceInterface $configService)
    {
        $parameter = $configService->getParameter('yourRoot.yourParameter');
        /**
         * You can also get parameter using the bundle name as defined in your composer.json.
         * This case is used when the files "config_bundles.yaml" and "configBundles.php" are not yet created.
         * For example, the first time you use the config Route and your Voter needs to check with a parameter defined using ConfigBundle.
         * Using this optional variable will make ConfigBundle creating the requested config files, based on default values in "bundle.yaml".
         */
        $parameter = $configService->getParameter('yourRoot.yourParameter', 'vendor/bundle-name');
    }
}
```

### Check if parameter is defined inside a class

To check if a parameter has been defined, use `$configService->hasParameter('yourRoot.yourParameter')`.

### Get Container's paramaters

You can use `$configService->getContainerParameter('parameter')` to access container's parameters and avoid injecting `Container` when `ConfigService` is already injected.

### Twig Extensions

If you need to access a parameter inside a Twig template, simply use `{{ config('yourRoot.yourParameter') }}`.

If you need to access a container's parameter inside a Twig template, simply use `{{ configParam('parameter') }}`.

If this project **help you to reduce time to develop**, you can sponsor me via the "Sponsor" button at the top :)
