<?php
/**
 * Hetzner Cloud Manager - Minimal PSR-4-ish Autoloader
 *
 * Maps the `HetznerCloudManager\` namespace to this module's `lib/`
 * directory so controllers/services can be added without maintaining
 * a manual list of require_once statements. This keeps the module
 * dependency-free (no Composer autoloader required for the module's
 * own classes) while still allowing Composer's autoloader to supply
 * third-party libraries (e.g. GuzzleHttp) when present.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

// Global template helper functions (hcm_e etc.). These must be loaded
// eagerly and must live in the global namespace, because .tpl files are
// included by TemplateRenderer and execute in the global namespace.
require_once __DIR__ . '/lib/helpers.php';

spl_autoload_register(function (string $class) {
    $prefix = 'HetznerCloudManager\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/lib/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

// Guzzle ships bundled inside WHMCS core's vendor directory and is normally
// already autoloadable. As a safety net, also load a module-local Composer
// autoloader if one has been installed (composer require guzzlehttp/guzzle
// inside this module's directory), so the module keeps working even on
// WHMCS installs that changed their internal dependency layout.
if (!class_exists('GuzzleHttp\\Client') && is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
