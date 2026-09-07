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
 * Order-form guard: block checkout if the selected server_type/location
 * combination for a Hetzner Cloud Manager product is out of stock.
 * Reads the cached availability table (kept warm by DailyCronJob) so
 * this check is fast and does not call the live API on every page view.
 *
 * Returns an array of error strings (WHMCS's expected format) - or
 * nothing at all when the cart is fine, so an empty value is never
 * mistaken for a validation error.
 */
add_hook('ShoppingCartValidateOrder', 1, function ($vars) {
    $errors = [];

    try {
        $map = null;

        foreach ($vars['products'] ?? [] as $product) {
            if (empty($product['configoptions']) || !is_array($product['configoptions'])) {
                continue;
            }

            $serverType = $product['configoptions']['ServerType']
                ?? $product['configoptions']['Server Type']
                ?? null;
            $location = $product['configoptions']['Location'] ?? null;

            if (!$serverType || !$location) {
                continue;
            }

            // Load the cache lazily so carts with no Hetzner products cost
            // nothing, and strip any "[Out of Stock]" suffix the product
            // importer may have appended to a sub-option label.
            $map = $map ?? StockAvailability::cachedMap();
            $serverType = trim(preg_replace('/\s*\[.*?\]\s*$/', '', $serverType));
            $location = trim(preg_replace('/\s*\[.*?\]\s*$/', '', $location));

            // Default to allowing the order when we have no cached data yet -
            // CreateAccount re-validates against the live API before it
            // provisions anything, so nothing can actually be deployed into
            // a sold-out pool.
            $available = $map[$serverType][$location] ?? true;

            if ($available === false) {
                $errors[] = "Selected server flavor '{$serverType}' is currently out of capacity in location '{$location}'. Please select an alternative location.";
            }
        }
    } catch (\Exception $e) {
        // Never block a customer's checkout because our stock cache had a
        // problem; log it and let provisioning-time validation catch it.
        logActivity('Hetzner Cloud Manager: cart stock validation skipped - ' . $e->getMessage());
        return;
    }

    if (!empty($errors)) {
        return $errors;
    }
});
