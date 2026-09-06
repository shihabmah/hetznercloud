<?php
/**
 * Hetzner Cloud Manager - WHMCS Addon Module
 *
 * Registers the addon, runs database auto-migration on activation, and
 * dispatches admin page requests to the tab controllers (Dashboard,
 * Accounts, Servers, Products, Resources, Snapshots). 100% open-source,
 * no ionCube, PHP 8.1+/WHMCS 8.x-9.x.
 *
 * @package HetznerCloudManager
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/autoload.php';

use HetznerCloudManager\Database\Schema;
use HetznerCloudManager\Controller\AccountsController;
use HetznerCloudManager\Controller\DashboardController;
use HetznerCloudManager\View\TemplateRenderer;

function hetzner_cloud_manager_config()
{
    return [
        'name' => 'Hetzner Cloud Manager',
        'description' => 'Dynamic Hetzner Cloud provisioning, live stock availability, and automated pricing sync for WHMCS. 100% open-source, no ionCube.',
        'version' => '1.0.0',
        'author' => 'Hetzner Cloud Manager Contributors',
        'language' => 'english',
        'fields' => [
            'default_exchange_note' => [
                'FriendlyName' => 'Currency Notes',
                'Type' => 'text',
                'Size' => '60',
                'Default' => 'Prices are converted from Hetzner net EUR using WHMCS exchange rates.',
                'Description' => 'Informational only - displayed on the Pricing tab.',
            ],
        ],
    ];
}

/**
 * Runs on Setup > Addon Modules > Activate. Creates/updates all
 * mod_hetzner_cloud_* tables. Idempotent - safe to re-run on upgrade.
 */
function hetzner_cloud_manager_activate()
{
    try {
        $actions = Schema::migrate();

        return [
            'status' => 'success',
            'description' => 'Hetzner Cloud Manager activated. ' . (empty($actions)
                ? 'Schema already up to date.'
                : implode('; ', $actions) . '.'),
        ];
    } catch (\Exception $e) {
        return [
            'status' => 'error',
            'description' => 'Activation failed: ' . $e->getMessage(),
        ];
    }
}

function hetzner_cloud_manager_deactivate()
{
    return [
        'status' => 'success',
        'description' => 'Hetzner Cloud Manager deactivated. Data tables were preserved; use the Uninstall action to remove them permanently.',
    ];
}

/**
 * Admin area entry point. Renders the tabbed dashboard and dispatches
 * POSTed actions from any tab to the relevant controller.
 */
function hetzner_cloud_manager_output($vars)
{
    // Keep schema current across upgrades without requiring re-activation.
    try {
        Schema::migrate();
    } catch (\Exception $e) {
        echo '<div class="alert alert-danger">Hetzner Cloud Manager: schema migration failed - ' . htmlspecialchars($e->getMessage()) . '</div>';
        return;
    }

    $moduleLink = $vars['modulelink'];
    $tab = $_REQUEST['tab'] ?? 'dashboard';
    $flash = null;

    // Central POST dispatch, one case per tab that accepts writes.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        switch ($tab) {
            case 'accounts':
                $flash = AccountsController::handlePost($_POST);
                break;
        }
    }

    $renderer = new TemplateRenderer(__DIR__ . '/templates');
    $renderer->setGlobals([
        'module_link' => $moduleLink,
        'active_tab' => $tab,
        'whmcs_version' => $vars['version'] ?? '',
        'module_version' => $vars['version'] ?? '1.0.0',
        'flash' => $flash,
    ]);

    try {
        switch ($tab) {
            case 'accounts':
                echo $renderer->renderPage('accounts', 'admin/accounts', [
                    'accounts' => AccountsController::listAccounts(),
                ]);
                break;

            case 'servers':
                echo $renderer->renderPage('servers', 'admin/servers', [
                    'orphans' => DashboardController::findOrphanServers(),
                    'stale' => DashboardController::findStaleServices(),
                ]);
                break;

            case 'products':
                echo $renderer->renderPage('products', 'admin/products', []);
                break;

            case 'resources':
                echo $renderer->renderPage('resources', 'admin/resources', []);
                break;

            case 'snapshots':
                echo $renderer->renderPage('snapshots', 'admin/snapshots', []);
                break;

            case 'dashboard':
            default:
                echo $renderer->renderPage('dashboard', 'admin/dashboard', [
                    'metrics' => DashboardController::getMetrics(),
                ]);
                break;
        }
    } catch (\Exception $e) {
        echo '<div class="alert alert-danger">Hetzner Cloud Manager error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}
