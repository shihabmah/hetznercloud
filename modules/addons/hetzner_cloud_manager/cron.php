<?php
/**
 * Hetzner Cloud Manager - Direct CLI Cron Runner
 *
 * WHMCS's DailyCronJob hook already triggers stock refresh and metric
 * billing automatically (see hooks.php), but some installs prefer a
 * dedicated CLI entry point that can be scheduled at a different
 * frequency (e.g. pricing sync every 12 hours instead of once daily).
 * Run this from the system crontab, e.g.:
 *
 *   0 star-slash-12 * * *  php /path/to/whmcs/modules/addons/hetzner_cloud_manager/cron.php
 *
 * (replace "star-slash-12" with the literal cron syntax for "every 12 hours")
 *
 * Usage:
 *   php cron.php                 Run all tasks (stock refresh, pricing sync, metric billing)
 *   php cron.php stock           Refresh live stock availability cache only
 *   php cron.php pricing         Run PricingSync::syncAll() only
 *   php cron.php billing         Run MetricBilling::runDaily() only
 */

// Locate and bootstrap the WHMCS application so Capsule/localAPI/etc. are available.
$whmcsRoot = realpath(__DIR__ . '/../../..');
$initFile = $whmcsRoot . '/init.php';

if (!is_file($initFile)) {
    fwrite(STDERR, "Could not locate WHMCS init.php relative to this module (expected at {$initFile}).\n");
    exit(1);
}

define('WHMCS', true);
require_once $initFile;
require_once __DIR__ . '/autoload.php';

use HetznerCloudManager\Api\HetznerClient;
use HetznerCloudManager\Api\StockAvailability;
use HetznerCloudManager\Controller\PricingSync;
use HetznerCloudManager\Helpers\MetricBilling;
use WHMCS\Database\Capsule;

$task = $argv[1] ?? 'all';

function hcm_cron_log(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
}

if ($task === 'all' || $task === 'stock') {
    hcm_cron_log('Refreshing live stock availability...');
    $accounts = Capsule::table('mod_hetzner_cloud_accounts')->where('is_active', 1)->get();
    foreach ($accounts as $account) {
        try {
            $client = HetznerClient::forAccount($account->id);
            $written = (new StockAvailability($client))->refresh();
            hcm_cron_log("  {$account->account_name}: {$written} (server_type, location) rows refreshed.");
        } catch (\Exception $e) {
            hcm_cron_log("  {$account->account_name}: ERROR - {$e->getMessage()}");
        }
    }
}

if ($task === 'all' || $task === 'pricing') {
    hcm_cron_log('Running pricing sync...');
    foreach (PricingSync::syncAll() as $result) {
        if ($result['status'] === 'success') {
            hcm_cron_log("  Product #{$result['product_id']} ({$result['product_name']}): synced.");
        } else {
            hcm_cron_log("  Product #{$result['product_id']}: ERROR - {$result['message']}");
        }
    }
}

if ($task === 'all' || $task === 'billing') {
    hcm_cron_log('Running metric billing...');
    $summary = MetricBilling::runDaily();
    hcm_cron_log("  Processed {$summary['processed']} instance(s), billed {$summary['billed']}.");
    foreach ($summary['errors'] as $error) {
        hcm_cron_log("  ERROR - {$error}");
    }
}

hcm_cron_log('Done.');
