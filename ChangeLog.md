# Changelog

v2.2.1
------
- Added `date` field (02/09/2018)
- Changed behaviour of `ConfigFirstUseCommand` to also define fields not linked to root and not already defined (to not erase) (02/09/2018)

v2.2
----
- Added possibility to define multiple roots in bundle.yaml (02/09/2018)
- Fixed `ConfigFirstUseCommand` to use `setConfig()` method (02/09/2018)

v2.1.4
------
- Fixed exportation of arrays (01/09/2018)

v2.1.3
------
- Updated composer.json (01/09/2018)
- Added root display in Command (01/09/2018)
- Fixed Twig extension (01/09/2018)

v2.1.2
------
- Simplified `getConfigFolder()` (31/08/2018)
- Added `hasParameter()` method (31/08/2018)
- Fixed `getParameter()` (31/08/2018)

v2.1.1
------
- Replaced `isset()` by `array_key_exists()` in `getParameter()` (31/08/2018)

v2.1
----
- Updated `README.md` (30/08/2018)
- Added console Command to create the config from defaut values, to be used before first use (30/08/2018)

v2.0.1
------
- Fixed missing returns of $parameters (30/08/2018)

v2.0
----
- Created branch 1.x (30/08/2018)
- Modified files to use own sytem of key-value for config (30/08/2018)


v1.x
====

v1.2.2
------
- Updated `README.md` (30/08/2018)

v1.2.1.1
--------
- Updated `README.md` (29/08/2018)

v1.2.1
------
- Fixed typo in `README.md` (29/08/2018)
- Added folder for SF4 (29/08/2018)

v1.2
----
- Added the info field as title for label + field (29/08/2018)
- Re-designed `ConfigType` in a cleaner way (29/08/2018)

v1.1.1
------
- Added test to check if the root node is already defined in the yaml file (28/08/2018)
- Updated `README.md`` (29/08/2018)

v1.1
----
- Added core files (27/08/2018)

v1.0
----
- Creation of bundle (26/08/2018)