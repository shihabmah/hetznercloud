<?php
/**
 * Hetzner Cloud Manager - WHMCS Hooks
 *
 * Registers:
 *  - AdminAreaHeadOutput: nothing heavy, reserved for future asset injection
 *  - AdminHomepage: capacity/rate-limit warning widget
 *  - DailyCronJob: stock availability refresh + pricing sync + metric billing
 *  - ClientAreaProductDetailsPreOrder / ShoppingCartValidateOrder: live stock
 *    availability enforcement so customers cannot order into an out-of-stock
 *    server_type/location combination.
 *
 * @package HetznerCloudManager
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/autoload.php';

use HetznerCloudManager\Api\HetznerClient;
use HetznerCloudManager\Api\StockAvailability;
use HetznerCloudManager\Controller\PricingSync;
use HetznerCloudManager\Helpers\MetricBilling;
use HetznerCloudManager\Database\Schema;
use WHMCS\Database\Capsule;

/**
 * Daily housekeeping: refresh live stock cache for every active account,
 * run the pricing sync engine (throttled to the configured interval so a
 * daily cron doesn't need to mean a daily price recompute), run metric
 * overage billing, and prune activity log entries older than 90 days.
 */
add_hook('DailyCronJob', 1, function ($vars) {
    $accounts = Capsule::table('mod_hetzner_cloud_accounts')->where('is_active', 1)->get();

    foreach ($accounts as $account) {
        try {
            $client = HetznerClient::forAccount($account->id);
            $stock = new StockAvailability($client);
            $stock->refresh();
        } catch (\Exception $e) {
            logActivity('Hetzner Cloud Manager: stock refresh failed for account ' . $account->account_name . ' - ' . $e->getMessage());
        }
    }

    // Throttle pricing sync to the configured interval (default 12h) by
    // tracking the last run timestamp in mod_hetzner_cloud_settings.
    $intervalHours = (int) Schema::getSetting('pricing_sync_interval_h', 12);
    $lastRun = Schema::getSetting('pricing_sync_last_run_at');
    $dueForSync = !$lastRun || (time() - strtotime($lastRun)) >= ($intervalHours * 3600);

    if ($dueForSync) {
        try {
            foreach (PricingSync::syncAll() as $result) {
                if ($result['status'] === 'error') {
                    logActivity('Hetzner Cloud Manager: pricing sync failed for product #' . $result['product_id'] . ' - ' . $result['message']);
                }
            }
            Schema::setSetting('pricing_sync_last_run_at', date('Y-m-d H:i:s'));
        } catch (\Exception $e) {
            logActivity('Hetzner Cloud Manager: pricing sync run failed - ' . $e->getMessage());
        }
    }

    try {
        $billingSummary = MetricBilling::runDaily();
        foreach ($billingSummary['errors'] as $error) {
            logActivity('Hetzner Cloud Manager: metric billing - ' . $error);
        }
    } catch (\Exception $e) {
        logActivity('Hetzner Cloud Manager: metric billing run failed - ' . $e->getMessage());
    }

    Capsule::table('mod_hetzner_cloud_activity_log')
        ->where('created_at', '<', date('Y-m-d H:i:s', strtotime('-90 days')))
        ->delete();
});

/**
 * Adds a lightweight admin homepage widget summarizing rate-limit
 * headroom and any out-of-stock server/location combinations, so
 * admins see capacity issues without opening the addon.
 */
add_hook('AdminHomeWidgets', 1, function () {
    if (!class_exists('WHMCS\\Admin\\Widget')) {
        return null;
    }

    $outOfStock = StockAvailability::outOfStock(5);
    $lowRateLimit = Capsule::table('mod_hetzner_cloud_accounts')
        ->where('is_active', 1)
        ->where('rate_limit_remaining', '<', 200)
        ->get();

    $html = '<ul class="list-unstyled mb-0">';
    if (empty($outOfStock) && $lowRateLimit->isEmpty()) {
        $html .= '<li class="text-muted">All Hetzner accounts and server stock look healthy.</li>';
    }
    foreach ($outOfStock as $row) {
        $html .= '<li><span class="label label-danger">Out of stock</span> ' . htmlspecialchars($row->server_type) . ' @ ' . htmlspecialchars($row->location) . '</li>';
    }
    foreach ($lowRateLimit as $account) {
        $html .= '<li><span class="label label-warning">Rate limit low</span> ' . htmlspecialchars($account->account_name) . ' (' . (int) $account->rate_limit_remaining . ' remaining)</li>';
    }
    $html .= '</ul>';

    return \WHMCS\Admin\Widget::create('hetzner_cloud_manager_health')
        ->setTitle('Hetzner Cloud Manager')
        ->setWeight(200)
        ->setColSpan(1)
        ->setContent($html);
});

/**
 * Order-form guard: block checkout if the selected server_type/location
 * combination for a Hetzner Cloud Manager product is out of stock.
 * Reads the cached availability table (kept warm by DailyCronJob) so
 * this check is fast and does not call the live API on every page view.
 */
add_hook('ShoppingCartValidateOrder', 1, function ($vars) {
    foreach ($vars['products'] ?? [] as $product) {
        if (empty($product['configoptions'])) {
            continue;
        }

        $serverType = $product['configoptions']['ServerType'] ?? $product['configoptions']['Server Type'] ?? null;
        $location = $product['configoptions']['Location'] ?? null;

        if (!$serverType || !$location) {
            continue;
        }

        $map = StockAvailability::cachedMap();
        $available = $map[$serverType][$location] ?? true; // default open if uncached, StockChecker will block at provisioning time

        if ($available === false) {
            return "Selected server flavor '{$serverType}' is currently out of capacity in location '{$location}'. Please select an alternative location.";
        }
    }

    return '';
});
