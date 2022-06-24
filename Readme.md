# Asset usage tracking for Neos CMS

This package provides a replacement for the asset usage calculation
in [Neos CMS](https://www.neos.io) 5.x, 7.x and 8.x.

Neos always calculated the asset usage when the information was requested. 
This could take quite long depending on the number of nodes and assets in a project.

This package solves the problem by using the registration service of `Flowpack.EntityUsage`
to register a usage if an asset is referenced and unregister when the asset is not referenced
by a node anymore.
The data is stored in a database table and can be efficiently queried and accessed by the provided service.

Additionally, the package provides a replacement for the `AssetUsageInNodePropertiesStrategy`
of Neos CMS. The core strategy is disabled via AOP.

## TLDR

Viewing asset usages in Neos CMS will be super fast when this package is installed.

Using it with `Flowpack.Media.Ui` will also allow enabling additional features 
like filtering for unused assets and delete button are disabled if an asset is used.

## Compatibility

Neos 5.2, Neos 7.x + Neos 8.x

## Installation

Add the package, and the storage as dependency in your site package:

    composer require --no-update flowpack/neos-asset-usage flowpack/entity-usage-databasestorage

The run `composer update` in your project root.

Finally you need to run the command to build the initial usage index:

    ./flow assetusage:update

This will store all usages in your database. If you deploy your project
on another system you have to make sure that you run this command there.

It is recommended to run the command from time to make sure that no 
usages are in the database that don't exist anymore or usages are missing.
When that happens, it is important that you try to find out where they come from.
If you think this happened due to an error in this package, please open an issue 
with as much information as you can give.

## Related packages

* [Flowpack.EntityUsage](https://github.com/Flowpack/Flowpack.EntityUsage) the generic usage implementation
* [Flowpack.EntityUsage.DatabaseStorage](https://github.com/Flowpack/Flowpack.EntityUsage.DatabaseStorage) for storing usages in the database

## Development goal

This functionality will at some point be integrated into the core of a future Neos CMS version.
