# ChangeLog

## v3.0.2

- Added TreeBuilder return type (29/05/2023)

## v3.0.1

- Added missing return type (06/04/2023)

## v3.0

- Changed compatibility to PHP 8 (25/07/2022)

## v2.6

- Made use of ParameterBag instead of container (24/07/2022)

## v2.5.8.1

- Suppressed (missed) use of container (24/04/2022)

## v2.5.8

- Suppressed use of container (24/04/2022)

## v2.5.7

- Changed composer versions constraints (24/07/2022)

## v2.5.6

- Corrected Command return for SF 4 (14/10/2021)

## v2.5.5

- Added return for console Command (08/10/2021)

## v2.5.4

- Added condition test if newDefindeValue is NOT null (20/09/2021)

## v2.5.3

- Added key `$name` (06/09/2021)

## v2.5.2

- Removed `../` after  `kernel.project_dir` (06/09/2021)

## v2.5.1

- Replaced `kernel.root_dir` by `kernel.project_dir` (03/09/2021)

## v2.5

- Removed versions constraints in composer (03/09/2021)

## v2.4.3

- Corrected unneded config in DependencyInjection (04/03/2020)
- Removed switch function to reduce Cyclomatic complexity (05/03/2020)

## v2.4.2

- Cosmetic changes dur to Codacy review (04/03/2020)

## v2.4.1

- Removed composer.lock from Git (19/02/2020)

## v2.4

- Made use of apply spaceless (05/08/2019)

## v2.3.6.1

- Changed Github's author reference url (08/04/2019)

## v2.3.6

- Made use of Twig namespace (11/03/2019)
- Added declaration of $formFactory (11/03/2019)

## v2.3.5

- Removed deprecations for @Method in `README.md` example (13/02/2019)
- Implemented AstractController instead of Controller in `README.md` example (13/02/2019)
- Modified Dependencyinjection rootNode to be not empty (13/02/2019)

## v2.3.4

- Modified required versions in `composer.json` (25/12/2018)

## v2.3.3

- Added missing use (25/12/2018)

## v2.3.2

- Added rector to composer dev part (23/12/2018)
- Modified required versions in composer (23/12/2018)

## v2.3.1

- Corrected error message when config file is not created (04/12/2018)

## v2.3

- Suppressed `ConfigFirstUseCommand` and replaced by `ConfigCreateCommand` (03/12/2018)

## v2.2.5

- Modified versions in `composer.json` (03/12/2018)

## v2.2.4

- Added information in README.md (28/10/2018)
- Added method `getContainerParameter()` (+Twig extension) as a shortcut to avoid injecting container when `ConfigService` is already injected (31/10/2018)

## v2.2.3

- Changed location for config folder for SF4 (18/10/2018)

## v2.2.2

- Fixed `getConfig()` that was setting all defined properties found instead of setting only those defined in bundle (03/09/2018)

## v2.2.1

- Added `date` field (02/09/2018)
- Changed behaviour of `ConfigFirstUseCommand` to also define fields not linked to root and not already defined (to not erase) (02/09/2018)

## v2.2

- Added possibility to define multiple roots in bundle.yaml (02/09/2018)
- Fixed `ConfigFirstUseCommand` to use `setConfig()` method (02/09/2018)

## v2.1.4

- Fixed exportation of arrays (01/09/2018)

## v2.1.3

- Updated composer.json (01/09/2018)
- Added root display in Command (01/09/2018)
- Fixed Twig extension (01/09/2018)

## v2.1.2

- Simplified `getConfigFolder()` (31/08/2018)
- Added `hasParameter()` method (31/08/2018)
- Fixed `getParameter()` (31/08/2018)

## v2.1.1

- Replaced `isset()` by `array_key_exists()` in `getParameter()` (31/08/2018)

## v2.1

- Updated `README.md` (30/08/2018)
- Added console Command to create the config from defaut values, to be used before first use (30/08/2018)

## v2.0.1

- Fixed missing returns of $parameters (30/08/2018)

## v2.0

- Created branch 1.x (30/08/2018)
- Modified files to use own sytem of key-value for config (30/08/2018)

## v1.x

## v1.2.2

- Updated `README.md` (30/08/2018)

## v1.2.1.1

- Updated `README.md` (29/08/2018)

## v1.2.1

- Fixed typo in `README.md` (29/08/2018)
- Added folder for SF4 (29/08/2018)

## v1.2

- Added the info field as title for label + field (29/08/2018)
- Re-designed `ConfigType` in a cleaner way (29/08/2018)

## v1.1.1

- Added test to check if the root node is already defined in the yaml file (28/08/2018)
- Updated `README.md`` (29/08/2018)

## v1.1

- Added core files (27/08/2018)

## v1.0

- Creation of bundle (26/08/2018)
