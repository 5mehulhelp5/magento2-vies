<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

/*
 * The module is autoloaded through its own vendor/ (standalone checkout, as in CI), or through the
 * vendor/ of the Magento install it sits in: under vendor/ as a package, or under app/code.
 */
$autoloaders = array_filter(
    [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../../../vendor/autoload.php',
        __DIR__ . '/../../../../../vendor/autoload.php',
    ],
    'is_file'
);

if ($autoloaders === []) {
    throw new \RuntimeException(
        'No composer autoloader found. Run `composer update` in the module, or run the suite from a Magento install.'
    );
}

require reset($autoloaders);
