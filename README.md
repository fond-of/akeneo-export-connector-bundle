# fond-of-akeneo/export-connector-bundle
[![PHP from Travis config](https://img.shields.io/travis/php-v/symfony/symfony.svg)](https://php.net/)
[![license](https://img.shields.io/github/license/mashape/apistatus.svg)](https://packagist.org/packages/fond-of-akeneo/export-connector-bundle)

FOND OF bundle to export products with localized attribute option labels.

## Install

Install bundle with composer:
```
composer require fond-of-akeneo/export-connector-bundle:VERSION_CONSTRAINT
```

Enable the bundle in ```app/AppKernel.php``` file, in the ```registerBundles``` function, before the line ```return $bundles```:
```php
$bundles[] = new FondOfAkeneo\Bundle\ExportConnectorBundle\FondOfAkeneoExportConnectorBundle();
```
