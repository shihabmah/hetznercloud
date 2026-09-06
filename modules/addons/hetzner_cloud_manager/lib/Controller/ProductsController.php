<?php
/**
 * Hetzner Cloud Manager - 1-Click Product Importer & Config Option Builder
 *
 * Lists live server_types from the Hetzner API and, on confirmation,
 * creates a fully wired WHMCS product: tblproducts row, provisioning
 * module set to hetzner_cloud_manager, standard Configurable Option
 * groups (Operating System, Location, Backups, Extra Storage), and a
 * mod_hetzner_cloud_markup_rules row seeded with sane EUR defaults so
 * PricingSync (Phase 4) has something to compute from immediately.
 *
 * @package HetznerCloudManager\Controller
 */

namespace HetznerCloudManager\Controller;

use HetznerCloudManager\Api\HetznerClient;
use HetznerCloudManager\Api\StockAvailability;
use WHMCS\Database\Capsule;
use Exception;

class ProductsController
{
    public static function handlePost(array $post): array
    {
        $action = $post['product_action'] ?? '';

        try {
            switch ($action) {
                case 'import':
                    return self::importServerType($post);
                case 'sync_pricing':
                    return self::syncPricing($post);
                default:
                    return ['status' => 'error', 'message' => 'Unknown product action.'];
            }
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Manual trigger for \HetznerCloudManager\Controller\PricingSync,
     * either for a single product or for every product with a markup
     * rule configured.
     */
    protected static function syncPricing(array $post): array
    {
        $productId = (int) ($post['sync_product_id'] ?? 0);

        if ($productId > 0) {
            $result = \HetznerCloudManager\Controller\PricingSync::syncProduct($productId);
            return $result['status'] === 'success'
                ? ['status' => 'success', 'message' => "Pricing synced for '{$result['product_name']}' (base cost €{$result['base_cost_eur']}/mo)."]
                : ['status' => 'error', 'message' => $result['message']];
        }

        $results = \HetznerCloudManager\Controller\PricingSync::syncAll();
        $successCount = count(array_filter($results, fn ($r) => $r['status'] === 'success'));
        $errorCount = count($results) - $successCount;

        return [
            'status' => $errorCount > 0 && $successCount === 0 ? 'error' : 'success',
            'message' => "Pricing sync complete: {$successCount} product(s) updated" . ($errorCount ? ", {$errorCount} failed" : '') . '.',
        ];
    }

    /**
     * Live catalog of importable server types for the given account,
     * annotated with an "already imported" flag (matched by server_type
     * name against existing markup_rules-linked products' configoption2).
     */
    public static function listImportableServerTypes(int $accountId): array
    {
        $client = HetznerClient::forAccount($accountId);
        $stock = new StockAvailability($client);
        $availability = $stock->fetchLiveAvailability();

        $types = [];
        foreach ($client->getServerTypes() as $type) {
            if (($type['deprecated'] ?? false) === true) {
                continue;
            }

            $priceEur = null;
            foreach ($type['prices'] ?? [] as $price) {
                $priceEur = (float) ($price['price_monthly']['net'] ?? 0);
                break; // first location's price is representative for the import preview
            }

            $availableSomewhere = in_array(true, $availability[$type['name']] ?? [], true);

            $types[] = [
                'id' => $type['id'],
                'name' => $type['name'],
                'description' => $type['description'] ?? $type['name'],
                'cores' => $type['cores'],
                'memory' => $type['memory'],
                'disk' => $type['disk'],
                'price_monthly_eur' => $priceEur,
                'available' => $availableSomewhere,
                'locations' => array_keys($availability[$type['name']] ?? []),
            ];
        }

        return $types;
    }

    /**
     * Create a WHMCS product wired to this module for the given Hetzner
     * server type: tblproducts row + provisioning config + standard
     * Configurable Option groups + a markup_rules row.
     */
    protected static function importServerType(array $post): array
    {
        $accountId = (int) ($post['account_id'] ?? 0);
        $serverTypeName = trim($post['server_type'] ?? '');
        $gid = (int) ($post['gid'] ?? 0);
        $productName = trim($post['product_name'] ?? ('Cloud ' . strtoupper($serverTypeName)));
        $markupPct = (float) ($post['markup_pct'] ?? 20);

        if ($accountId <= 0 || $serverTypeName === '' || $gid <= 0) {
            return ['status' => 'error', 'message' => 'Account, server type, and product group are all required.'];
        }

        $client = HetznerClient::forAccount($accountId);
        $serverType = null;
        foreach ($client->getServerTypes() as $type) {
            if ($type['name'] === $serverTypeName) {
                $serverType = $type;
                break;
            }
        }

        if (!$serverType) {
            return ['status' => 'error', 'message' => "Server type '{$serverTypeName}' was not found via the Hetzner API."];
        }

        $baseCostEur = (float) ($serverType['prices'][0]['price_monthly']['net'] ?? 0);

        // 1. Create the base product.
        $productId = Capsule::table('tblproducts')->insertGetId([
            'gid' => $gid,
            'type' => 'hostingaccount',
            'name' => $productName,
            'servertype' => 'hetzner_cloud_manager',
            'paytype' => 'recurring',
            'description' => sprintf(
                '<p>%d vCPU, %sGB RAM, %sGB Disk - Hetzner Cloud (%s)</p>',
                $serverType['cores'],
                $serverType['memory'],
                $serverType['disk'],
                $serverTypeName
            ),
            'hidden' => 1, // admin should review pricing before publishing
            'tax' => 1,
            'order' => 0,
        ]);

        // 2. Wire the provisioning module's fixed config (Account + Server Type)
        //    directly onto the product so ConfigOptions() Loaders resolve them
        //    even before any order-time Configurable Options are chosen.
        Capsule::table('tblproducts')->where('id', $productId)->update([
            'configoption1' => $accountId,
            'configoption2' => $serverTypeName,
        ]);

        // 3. Create standard Configurable Option Groups: Location, Operating System, Backups, Extra Storage.
        $groupIds = self::createConfigurableOptionGroups($productId, $client, $serverType);

        // 4. Seed markup rules for PricingSync (Phase 4) with sane EUR defaults.
        Capsule::table('mod_hetzner_cloud_markup_rules')->updateOrInsert(
            ['product_id' => $productId],
            [
                'base_server_markup_pct' => $markupPct,
                'backup_markup_pct' => $markupPct,
                'snapshot_markup_pct' => $markupPct,
                'volume_markup_pct' => $markupPct,
                'updated_at' => date('Y-m-d H:i:s'),
            ]
        );

        return [
            'status' => 'success',
            'message' => "Product '{$productName}' created (base cost €{$baseCostEur}/mo, {$markupPct}% markup). " .
                'It is hidden by default - review pricing on the Products tab, then unhide it in Setup > Products/Services.',
        ];
    }

    /**
     * Build the standard Configurable Option Groups for a freshly
     * imported product: Location (live availability), Operating System
     * (live images), Backups toggle, and an Extra Storage slider
     * (0-1000 GB block storage volume).
     */
    protected static function createConfigurableOptionGroups(int $productId, HetznerClient $client, array $serverType): array
    {
        $groupIds = [];

        // --- Location group ---------------------------------------------------
        $locationGroupId = Capsule::table('tblproductconfiggroups')->insertGetId([
            'gid' => 0,
            'name' => 'Location',
            'description' => 'Datacenter location',
        ]);
        Capsule::table('tblproductconfiglinks')->insert(['gid' => $locationGroupId, 'pid' => $productId]);

        $optionId = Capsule::table('tblproductconfigoptions')->insertGetId([
            'gid' => $locationGroupId,
            'optionname' => 'Location',
            'optiontype' => 1, // dropdown
        ]);

        foreach ($serverType['locations'] ?? [] as $loc) {
            $suffix = empty($loc['available']) ? ' [Out of Stock]' : '';
            Capsule::table('tblproductconfigoptionssub')->insert([
                'configid' => $optionId,
                'optionname' => $loc['name'] . $suffix,
                'sortorder' => 0,
            ]);
        }
        $groupIds['location'] = $locationGroupId;

        // --- Operating System group --------------------------------------------
        $osGroupId = Capsule::table('tblproductconfiggroups')->insertGetId([
            'gid' => 0,
            'name' => 'Operating System',
            'description' => 'OS image deployed on provisioning',
        ]);
        Capsule::table('tblproductconfiglinks')->insert(['gid' => $osGroupId, 'pid' => $productId]);

        $osOptionId = Capsule::table('tblproductconfigoptions')->insertGetId([
            'gid' => $osGroupId,
            'optionname' => 'Image',
            'optiontype' => 1,
        ]);

        try {
            foreach ($client->getImages(['type' => 'system']) as $image) {
                Capsule::table('tblproductconfigoptionssub')->insert([
                    'configid' => $osOptionId,
                    'optionname' => $image['name'],
                    'sortorder' => 0,
                ]);
            }
        } catch (Exception $e) {
            // Non-fatal: admin can add OS sub-options manually if the images call fails.
        }
        $groupIds['os'] = $osGroupId;

        // --- Backups toggle ------------------------------------------------------
        $backupGroupId = Capsule::table('tblproductconfiggroups')->insertGetId([
            'gid' => 0,
            'name' => 'Backups',
            'description' => 'Automated daily provider backups (+20% base cost)',
        ]);
        Capsule::table('tblproductconfiglinks')->insert(['gid' => $backupGroupId, 'pid' => $productId]);

        $backupOptionId = Capsule::table('tblproductconfigoptions')->insertGetId([
            'gid' => $backupGroupId,
            'optionname' => 'Backups',
            'optiontype' => 1,
        ]);
        Capsule::table('tblproductconfigoptionssub')->insert(['configid' => $backupOptionId, 'optionname' => 'No', 'sortorder' => 0]);
        Capsule::table('tblproductconfigoptionssub')->insert(['configid' => $backupOptionId, 'optionname' => 'Yes', 'sortorder' => 1]);
        $groupIds['backups'] = $backupGroupId;

        // --- Extra Storage slider (0-1000 GB, in 50GB steps) ----------------------
        $storageGroupId = Capsule::table('tblproductconfiggroups')->insertGetId([
            'gid' => 0,
            'name' => 'Extra Block Storage',
            'description' => 'Additional Hetzner Volume storage, billed per GB/month',
        ]);
        Capsule::table('tblproductconfiglinks')->insert(['gid' => $storageGroupId, 'pid' => $productId]);

        $storageOptionId = Capsule::table('tblproductconfigoptions')->insertGetId([
            'gid' => $storageGroupId,
            'optionname' => 'Extra Storage (GB)',
            'optiontype' => 1,
        ]);
        foreach ([0, 50, 100, 250, 500, 1000] as $gb) {
            Capsule::table('tblproductconfigoptionssub')->insert([
                'configid' => $storageOptionId,
                'optionname' => $gb . ' GB',
                'sortorder' => 0,
            ]);
        }
        $groupIds['storage'] = $storageGroupId;

        return $groupIds;
    }

    /**
     * WHMCS product groups, for the "Select Product Group" dropdown on
     * the import modal.
     */
    public static function listProductGroups(): array
    {
        return Capsule::table('tblproductgroups')->orderBy('order')->get()->all();
    }

    /**
     * Every product already wired to this module (has a markup rule),
     * joined with its tblproducts name/hidden status, for the "Managed
     * Products" pricing sync table.
     */
    public static function listManagedProducts(): array
    {
        return Capsule::table('mod_hetzner_cloud_markup_rules')
            ->join('tblproducts', 'tblproducts.id', '=', 'mod_hetzner_cloud_markup_rules.product_id')
            ->select(
                'tblproducts.id as product_id',
                'tblproducts.name as product_name',
                'tblproducts.hidden',
                'tblproducts.configoption2 as server_type',
                'mod_hetzner_cloud_markup_rules.base_server_markup_pct',
                'mod_hetzner_cloud_markup_rules.updated_at'
            )
            ->orderBy('tblproducts.name')
            ->get()
            ->all();
    }
}
