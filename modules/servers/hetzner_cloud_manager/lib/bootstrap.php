<?php
/**
 * Hetzner Cloud Manager - Provisioning Module Bootstrap
 *
 * The Server Provisioning Module and the Addon Module share the same
 * class library (Api\HetznerClient, Api\StockAvailability, Helpers\CloudInitBuilder)
 * to avoid duplicating the Hetzner API client and its rate-limit logic.
 * This file locates the addon's autoloader (which registers the
 * `HetznerCloudManager\` namespace against modules/addons/hetzner_cloud_manager/lib)
 * and includes it, so this module keeps working even if the addon has
 * not been activated yet (activation only runs DB migrations, it is
 * not required for the classes themselves to load).
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

$addonAutoload = __DIR__ . '/../../../addons/hetzner_cloud_manager/autoload.php';

if (is_file($addonAutoload)) {
    require_once $addonAutoload;
} else {
    throw new \Exception(
        'Hetzner Cloud Manager: required addon module files were not found at ' .
        'modules/addons/hetzner_cloud_manager/. Please ensure the addon module ' .
        'is installed alongside the server provisioning module.'
    );
}

// Ensure the schema exists even if the addon has not been activated yet
// (e.g. a fresh install where the admin configured the server module first).
try {
    \HetznerCloudManager\Database\Schema::migrate();
} catch (\Exception $e) {
    logActivity('Hetzner Cloud Manager: schema migration failed during provisioning bootstrap - ' . $e->getMessage());
}
