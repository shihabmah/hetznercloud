<?php
/**
 * Hetzner Cloud Manager - Custom Metric Billing Engine
 *
 * Daily job that reads each provisioned server's Hetzner metrics
 * (network egress) plus its snapshot/volume/floating-IP footprint,
 * compares against the product's included allowances, and writes
 * overage charges directly onto the client's next invoice via the
 * WHMCS Local API's AddBillableItem call.
 *
 * @package HetznerCloudManager\Helpers
 */

namespace HetznerCloudManager\Helpers;

use HetznerCloudManager\Api\HetznerClient;
use HetznerCloudManager\Controller\PricingSync;
use WHMCS\Database\Capsule;
use Exception;

class MetricBilling
{
    /**
     * Run the full daily metric billing pass across every tracked
     * instance. Returns a summary array for cron logging.
     */
    public static function runDaily(): array
    {
        $period = date('Y-m-01'); // billed monthly, keyed by the first of the current month
        $summary = ['processed' => 0, 'billed' => 0, 'errors' => []];

        $instances = Capsule::table('mod_hetzner_cloud_instances')->get();

        foreach ($instances as $instance) {
            try {
                $usage = self::collectUsage($instance);
                self::recordUsage($instance->service_id, $period, $usage);
                $summary['processed']++;

                if (self::billOverage($instance, $period, $usage)) {
                    $summary['billed']++;
                }
            } catch (Exception $e) {
                $summary['errors'][] = "Service #{$instance->service_id}: {$e->getMessage()}";
            }
        }

        return $summary;
    }

    /**
     * Pull current bandwidth/volume/floating-ip usage for a single
     * instance from the Hetzner API.
     */
    protected static function collectUsage($instance): array
    {
        $client = HetznerClient::forAccount($instance->account_id);

        $bandwidthOutGb = 0.0;
        try {
            $metrics = $client->request('GET', "servers/{$instance->hetzner_server_id}/metrics", [
                'type' => 'network',
                'start' => date('c', strtotime('-1 day')),
                'end' => date('c'),
            ]);
            $series = $metrics['metrics']['time_series']['network.0.outbound.pps']['values'] ?? [];
            // outbound.pps is packets, not bytes; use the byte-oriented series when available.
            $byteSeries = $metrics['metrics']['time_series']['network.0.outbound.bytes']['values'] ?? [];
            foreach ($byteSeries as $point) {
                $bandwidthOutGb += ((float) ($point[1] ?? 0)) / (1024 ** 3);
            }
        } catch (Exception $e) {
            // Metrics can be temporarily unavailable for a freshly created server; not fatal.
        }

        $snapshotGb = 0.0;
        try {
            $images = $client->getImages(['type' => 'snapshot']);
            foreach ($images as $image) {
                if (($image['created_from']['id'] ?? null) === (int) $instance->hetzner_server_id) {
                    $snapshotGb += (float) ($image['image_size'] ?? $image['disk_size'] ?? 0);
                }
            }
        } catch (Exception $e) {
            // ignore
        }

        $volumeGb = 0.0;
        $floatingIps = 0;
        try {
            $server = $client->getServer((int) $instance->hetzner_server_id);
            foreach ($server['volumes'] ?? [] as $volumeId) {
                try {
                    $volume = $client->request('GET', "volumes/{$volumeId}")['volume'] ?? [];
                    $volumeGb += (float) ($volume['size'] ?? 0);
                } catch (Exception $e) {
                    continue;
                }
            }
            $floatingIps = count($server['public_net']['floating_ips'] ?? []);
        } catch (Exception $e) {
            // ignore
        }

        return [
            'bandwidth_out_gb' => $bandwidthOutGb,
            'snapshot_gb' => $snapshotGb,
            'volume_gb' => $volumeGb,
            'floating_ips' => $floatingIps,
        ];
    }

    protected static function recordUsage(int $serviceId, string $period, array $usage): void
    {
        $existing = Capsule::table('mod_hetzner_cloud_metric_usage')
            ->where('service_id', $serviceId)
            ->where('period', $period)
            ->first();

        // Bandwidth accumulates across cron runs within the same billing
        // period (each run measures only the last 24h window); the other
        // metrics are point-in-time snapshots, so they are simply overwritten.
        $bandwidthTotal = $usage['bandwidth_out_gb'] + ($existing->bandwidth_out_gb ?? 0);

        Capsule::table('mod_hetzner_cloud_metric_usage')->updateOrInsert(
            ['service_id' => $serviceId, 'period' => $period],
            [
                'bandwidth_out_gb' => $bandwidthTotal,
                'snapshot_gb' => $usage['snapshot_gb'],
                'volume_gb' => $usage['volume_gb'],
                'floating_ips' => $usage['floating_ips'],
            ]
        );
    }

    /**
     * Compare accumulated usage against the product's markup-rule
     * defined allowances and write an AddBillableItem overage charge
     * if any threshold was exceeded. Only bills once per period per
     * metric (billed flag) to avoid duplicate charges on repeated cron runs.
     */
    protected static function billOverage($instance, string $period, array $usage): bool
    {
        $usageRow = Capsule::table('mod_hetzner_cloud_metric_usage')
            ->where('service_id', $instance->service_id)
            ->where('period', $period)
            ->first();

        if (!$usageRow || $usageRow->billed) {
            return false;
        }

        $service = Capsule::table('tblhosting')->where('id', $instance->service_id)->first();
        if (!$service) {
            return false;
        }

        $rule = Capsule::table('mod_hetzner_cloud_markup_rules')->where('product_id', $service->packageid)->first();
        if (!$rule) {
            return false;
        }

        $currency = Capsule::table('tblclients')->where('id', $service->userid)->value('currency');
        $exchangeRate = (float) Capsule::table('tblcurrencies')->where('id', $currency)->value('rate') ?: 1.0;

        $billed = false;

        // Bandwidth overage: billed per TB above zero-rated allowance is
        // intentionally left to the admin's product description; here we
        // bill the raw measured overage in whole TB increments.
        $bandwidthTb = $usage['bandwidth_out_gb'] / 1024;
        if ($bandwidthTb >= 1) {
            $amount = PricingSync::calculateFlatPrice((float) $rule->bandwidth_per_tb_eur * $bandwidthTb, 0, $exchangeRate);
            self::addBillableItem($service, 'Bandwidth Overage', $bandwidthTb . ' TB', $amount);
            $billed = true;
        }

        if ($usage['floating_ips'] > 0) {
            $amount = PricingSync::calculateFlatPrice((float) $rule->floating_ipv4_monthly_eur * $usage['floating_ips'], 0, $exchangeRate);
            self::addBillableItem($service, 'Floating IP Allocation', $usage['floating_ips'] . ' IP(s)', $amount);
            $billed = true;
        }

        if ($usage['snapshot_gb'] > 0) {
            $amount = PricingSync::calculatePerGbPrice((float) $rule->snapshot_per_gb_eur, (float) $rule->snapshot_markup_pct, $exchangeRate, $usage['snapshot_gb']);
            self::addBillableItem($service, 'Snapshot Storage', round($usage['snapshot_gb'], 1) . ' GB', $amount);
            $billed = true;
        }

        if ($billed) {
            Capsule::table('mod_hetzner_cloud_metric_usage')
                ->where('service_id', $instance->service_id)
                ->where('period', $period)
                ->update(['billed' => 1]);
        }

        return $billed;
    }

    protected static function addBillableItem($service, string $description, string $detail, float $amount): void
    {
        if ($amount <= 0 || !function_exists('localAPI')) {
            return;
        }

        localAPI('AddBillableItem', [
            'userid' => $service->userid,
            'description' => "{$description} ({$detail})",
            'amount' => round($amount, 2),
            'recurring' => false,
            'invoiceaction' => 'nextinvoice',
            'relid' => $service->id,
        ]);
    }
}
