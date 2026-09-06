<?php
/**
 * Hetzner Cloud Manager - Dynamic Margin Calculator & WHMCS Pricing Sync
 *
 * Converts Hetzner's net-EUR provider costs into the WHMCS base/each
 * active currency using tblcurrencies exchange rates, applies the
 * per-product percentage markups stored in mod_hetzner_cloud_markup_rules,
 * and writes the resulting customer prices directly into tblpricing for
 * every billing cycle WHMCS supports (Monthly, Quarterly, Semi-Annually,
 * Annually, Biennially, Triennially).
 *
 * Formulas (C_base = Hetzner net EUR cost, M = markup %, R = exchange rate):
 *   Price_Server  = (C_base * (1 + M_server / 100)) * R
 *   Price_Backup  = ((C_base * 0.20) * (1 + M_backup / 100)) * R
 *   Price_Storage = (Size_GB * Cost_per_GB * (1 + M_storage / 100)) * R
 *
 * @package HetznerCloudManager\Controller
 */

namespace HetznerCloudManager\Controller;

use HetznerCloudManager\Api\HetznerClient;
use WHMCS\Database\Capsule;
use Exception;

class PricingSync
{
    /**
     * Monthly price is the canonical unit Hetzner bills in. Other cycles
     * are derived with the standard WHMCS cycle-length multipliers, with
     * a small discount for longer commitments left at 0% by default -
     * admins can still hand-tune tblpricing after a sync if desired.
     */
    protected const CYCLE_MONTHS = [
        'monthly' => 1,
        'quarterly' => 3,
        'semiannually' => 6,
        'annually' => 12,
        'biennially' => 24,
        'triennially' => 36,
    ];

    /**
     * tblpricing column name per cycle.
     */
    protected const CYCLE_COLUMNS = [
        'monthly' => 'monthly',
        'quarterly' => 'quarterly',
        'semiannually' => 'semiannually',
        'annually' => 'annually',
        'biennially' => 'biennially',
        'triennially' => 'triennially',
    ];

    /**
     * Sync pricing for every product that has a markup rule configured.
     * Intended to run from DailyCronJob (every 12h - see hooks.php) or
     * manually via the "Sync Pricing to WHMCS" button on the Products tab.
     *
     * @return array Per-product result summaries
     */
    public static function syncAll(): array
    {
        $results = [];

        $rules = Capsule::table('mod_hetzner_cloud_markup_rules')
            ->where('auto_sync_pricing', 1)
            ->get();

        foreach ($rules as $rule) {
            $results[] = self::syncProduct((int) $rule->product_id, $rule);
        }

        return $results;
    }

    /**
     * Sync a single product's pricing. $rule may be pre-fetched by the
     * caller (syncAll) or will be looked up here (manual single sync).
     */
    public static function syncProduct(int $productId, $rule = null): array
    {
        try {
            $product = Capsule::table('tblproducts')->where('id', $productId)->first();
            if (!$product) {
                return ['product_id' => $productId, 'status' => 'error', 'message' => 'Product not found.'];
            }

            $rule = $rule ?: Capsule::table('mod_hetzner_cloud_markup_rules')->where('product_id', $productId)->first();
            if (!$rule) {
                return ['product_id' => $productId, 'status' => 'error', 'message' => 'No markup rule configured for this product.'];
            }

            $accountId = (int) $product->configoption1;
            $serverTypeName = $product->configoption2;

            if (!$accountId || !$serverTypeName) {
                return ['product_id' => $productId, 'status' => 'error', 'message' => 'Product is missing Hetzner account/server type configuration.'];
            }

            $client = HetznerClient::forAccount($accountId);
            $baseCostEur = self::resolveBaseCostEur($client, $serverTypeName);

            if ($baseCostEur === null) {
                return ['product_id' => $productId, 'status' => 'error', 'message' => "Server type '{$serverTypeName}' not found via API."];
            }

            $currencies = Capsule::table('tblcurrencies')->get();
            $written = [];

            foreach ($currencies as $currency) {
                $serverPrice = self::calculateServerPrice($baseCostEur, (float) $rule->base_server_markup_pct, (float) $currency->rate);
                $backupPrice = self::calculateBackupPrice($baseCostEur, (float) $rule->backup_markup_pct, (float) $currency->rate);

                // WHMCS's tblpricing 'type' column uses 'product' for hosting/server
                // products (historically named after the tblproducts table it prices).
                self::writeTblPricing('product', $productId, $currency->id, $serverPrice);

                $written[$currency->code] = [
                    'server_monthly' => round($serverPrice, 2),
                    'backup_monthly' => round($backupPrice, 2),
                ];
            }

            return [
                'product_id' => $productId,
                'product_name' => $product->name,
                'status' => 'success',
                'base_cost_eur' => $baseCostEur,
                'prices' => $written,
            ];
        } catch (Exception $e) {
            return ['product_id' => $productId, 'status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Look up the representative monthly net-EUR cost for a server type
     * (first location's price - Hetzner prices are near-uniform across
     * EU locations; US/Asia locations can carry a small surcharge that
     * admins may account for with a higher per-product markup).
     */
    protected static function resolveBaseCostEur(HetznerClient $client, string $serverTypeName): ?float
    {
        foreach ($client->getServerTypes() as $type) {
            if ($type['name'] === $serverTypeName) {
                return (float) ($type['prices'][0]['price_monthly']['net'] ?? 0);
            }
        }
        return null;
    }

    public static function calculateServerPrice(float $baseCostEur, float $markupPct, float $exchangeRate): float
    {
        return ($baseCostEur * (1 + $markupPct / 100)) * $exchangeRate;
    }

    /**
     * Backup cost basis is 20% of the base server cost (Hetzner's
     * standard backup pricing), with its own markup percentage on top.
     */
    public static function calculateBackupPrice(float $baseCostEur, float $markupPct, float $exchangeRate): float
    {
        return (($baseCostEur * 0.20) * (1 + $markupPct / 100)) * $exchangeRate;
    }

    public static function calculatePerGbPrice(float $costPerGbEur, float $markupPct, float $exchangeRate, float $sizeGb = 1.0): float
    {
        return ($sizeGb * $costPerGbEur * (1 + $markupPct / 100)) * $exchangeRate;
    }

    public static function calculateFlatPrice(float $costEur, float $markupPct, float $exchangeRate): float
    {
        return ($costEur * (1 + $markupPct / 100)) * $exchangeRate;
    }

    /**
     * Write the monthly price (and its derived cycle prices) into
     * tblpricing for the given type ('server'/'configoption'/'addon'),
     * relid (product id or config option sub id), and currency.
     */
    protected static function writeTblPricing(string $type, int $relId, int $currencyId, float $monthlyPrice): void
    {
        $cycles = [];
        foreach (self::CYCLE_MONTHS as $cycle => $months) {
            $column = self::CYCLE_COLUMNS[$cycle];
            $cycles[$column] = round($monthlyPrice * $months, 2);
        }

        $existing = Capsule::table('tblpricing')
            ->where('type', $type)
            ->where('relid', $relId)
            ->where('currency', $currencyId)
            ->first();

        if ($existing) {
            Capsule::table('tblpricing')->where('id', $existing->id)->update($cycles);
        } else {
            Capsule::table('tblpricing')->insert(array_merge([
                'type' => $type,
                'relid' => $relId,
                'currency' => $currencyId,
            ], $cycles));
        }
    }

    /**
     * Write a price for a specific Configurable Option Sub-Option (used
     * for per-GB storage sliders and backup toggles created by
     * ProductsController::createConfigurableOptionGroups).
     */
    public static function writeConfigOptionPricing(int $configOptionSubId, int $currencyId, float $monthlyPrice): void
    {
        self::writeTblPricing('configoptions', $configOptionSubId, $currencyId, $monthlyPrice);
    }
}
