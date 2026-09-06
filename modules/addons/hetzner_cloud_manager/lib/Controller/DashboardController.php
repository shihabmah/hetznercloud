<?php
/**
 * Hetzner Cloud Manager - Dashboard Controller
 *
 * Aggregates estate metrics across all active Hetzner accounts (server
 * counts, running/stopped states, disk/snapshot totals, IP counts) and
 * cross-references mod_hetzner_cloud_instances against WHMCS tblhosting
 * to surface orphaned servers (exist in Hetzner, no WHMCS service) and
 * stale services (WHMCS service exists, server was deleted in Hetzner).
 *
 * @package HetznerCloudManager\Controller
 */

namespace HetznerCloudManager\Controller;

use HetznerCloudManager\Api\HetznerClient;
use HetznerCloudManager\Api\StockAvailability;
use WHMCS\Database\Capsule;
use Exception;

class DashboardController
{
    /**
     * Build the full metrics payload for dashboard.tpl.
     */
    public static function getMetrics(): array
    {
        $accounts = Capsule::table('mod_hetzner_cloud_accounts')->where('is_active', 1)->get();

        $totals = [
            'servers_total'   => 0,
            'servers_running' => 0,
            'servers_off'     => 0,
            'disk_gb'         => 0,
            'volumes_gb'      => 0,
            'floating_ips'    => 0,
            'monthly_cost_eur' => 0.0,
            'accounts'        => [],
            'errors'          => [],
        ];

        foreach ($accounts as $account) {
            try {
                $client = HetznerClient::forAccount($account->id);
                $servers = $client->getServers();

                $running = 0;
                $off = 0;
                $monthlyCost = 0.0;

                foreach ($servers as $server) {
                    if (($server['status'] ?? '') === 'running') {
                        $running++;
                    } elseif (($server['status'] ?? '') === 'off') {
                        $off++;
                    }
                    $monthlyCost += (float) ($server['server_type']['prices'][0]['price_monthly']['net'] ?? 0);
                }

                $volumes = $client->request('GET', 'volumes')['volumes'] ?? [];
                $floatingIps = $client->request('GET', 'floating_ips')['floating_ips'] ?? [];
                $volumesGb = array_sum(array_column($volumes, 'size'));

                $totals['servers_total'] += count($servers);
                $totals['servers_running'] += $running;
                $totals['servers_off'] += $off;
                $totals['volumes_gb'] += $volumesGb;
                $totals['floating_ips'] += count($floatingIps);
                $totals['monthly_cost_eur'] += $monthlyCost;

                $totals['accounts'][] = [
                    'id'                   => $account->id,
                    'name'                 => $account->account_name,
                    'server_count'         => count($servers),
                    'rate_limit_remaining' => $account->rate_limit_remaining,
                    'rate_limit_pct'       => $account->rate_limit_remaining > 0
                        ? min(100, round(($account->rate_limit_remaining / 3600) * 100))
                        : 0,
                ];
            } catch (Exception $e) {
                $totals['errors'][] = "{$account->account_name}: {$e->getMessage()}";
                $totals['accounts'][] = [
                    'id'                   => $account->id,
                    'name'                 => $account->account_name,
                    'server_count'         => 0,
                    'rate_limit_remaining' => 0,
                    'rate_limit_pct'       => 0,
                    'error'                => $e->getMessage(),
                ];
            }
        }

        $totals['out_of_stock'] = StockAvailability::outOfStock(10);
        $totals['orphans'] = self::findOrphanCount();
        $totals['stale_services'] = self::findStaleServiceCount();

        return $totals;
    }

    /**
     * Servers that exist on Hetzner but have no corresponding WHMCS
     * hosting service, OR whose linked service has been terminated/cancelled.
     * Returns full rows for the Orphan Audit tab.
     */
    public static function findOrphanServers(): array
    {
        $orphans = [];
        $accounts = Capsule::table('mod_hetzner_cloud_accounts')->where('is_active', 1)->get();

        $trackedServerIds = Capsule::table('mod_hetzner_cloud_instances')->pluck('hetzner_server_id')->all();

        foreach ($accounts as $account) {
            try {
                $client = HetznerClient::forAccount($account->id);
                foreach ($client->getServers() as $server) {
                    if (!in_array($server['id'], $trackedServerIds)) {
                        $orphans[] = [
                            'account_id'   => $account->id,
                            'account_name' => $account->account_name,
                            'server_id'    => $server['id'],
                            'server_name'  => $server['name'],
                            'status'       => $server['status'],
                            'ipv4'         => $server['public_net']['ipv4']['ip'] ?? null,
                            'created'      => $server['created'] ?? null,
                            'server_type'  => $server['server_type']['name'] ?? null,
                            'datacenter'   => $server['datacenter']['name'] ?? null,
                        ];
                    }
                }
            } catch (Exception $e) {
                continue;
            }
        }

        return $orphans;
    }

    protected static function findOrphanCount(): int
    {
        return count(self::findOrphanServers());
    }

    /**
     * WHMCS services that are Active/Pending but whose linked Hetzner
     * server no longer exists (deleted outside of WHMCS).
     */
    public static function findStaleServices(): array
    {
        $stale = [];
        $instances = Capsule::table('mod_hetzner_cloud_instances')->get();

        $liveIdsByAccount = [];

        foreach ($instances as $instance) {
            if (!isset($liveIdsByAccount[$instance->account_id])) {
                try {
                    $client = HetznerClient::forAccount($instance->account_id);
                    $liveIdsByAccount[$instance->account_id] = array_column($client->getServers(), 'id');
                } catch (Exception $e) {
                    $liveIdsByAccount[$instance->account_id] = null; // unknown - skip account on error
                }
            }

            $liveIds = $liveIdsByAccount[$instance->account_id];
            if ($liveIds === null) {
                continue;
            }

            if (!in_array($instance->hetzner_server_id, $liveIds)) {
                $service = Capsule::table('tblhosting')->where('id', $instance->service_id)->first();
                $stale[] = [
                    'service_id'        => $instance->service_id,
                    'server_name'       => $instance->server_name,
                    'hetzner_server_id' => $instance->hetzner_server_id,
                    'domain'            => $service->domain ?? null,
                    'status'            => $service->domainstatus ?? 'Unknown',
                ];
            }
        }

        return $stale;
    }

    protected static function findStaleServiceCount(): int
    {
        return count(self::findStaleServices());
    }

    /**
     * Link an orphaned Hetzner server to an existing WHMCS hosting service,
     * writing the mapping row without touching the live server.
     */
    public static function adoptOrphan(int $accountId, int $hetznerServerId, int $serviceId): array
    {
        $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
        if (!$service) {
            return ['status' => 'error', 'message' => 'WHMCS service not found.'];
        }

        $alreadyLinked = Capsule::table('mod_hetzner_cloud_instances')->where('service_id', $serviceId)->exists();
        if ($alreadyLinked) {
            return ['status' => 'error', 'message' => 'That service is already linked to a different server.'];
        }

        try {
            $client = HetznerClient::forAccount($accountId);
            $server = $client->getServer($hetznerServerId);
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => 'Could not verify server on Hetzner: ' . $e->getMessage()];
        }

        Capsule::table('mod_hetzner_cloud_instances')->insert([
            'service_id'        => $serviceId,
            'account_id'        => $accountId,
            'hetzner_server_id' => $hetznerServerId,
            'server_name'       => $server['name'] ?? ('server-' . $hetznerServerId),
            'datacenter'        => $server['datacenter']['name'] ?? '',
            'server_type'       => $server['server_type']['name'] ?? '',
            'location'          => $server['datacenter']['location']['name'] ?? '',
            'ipv4_address'      => $server['public_net']['ipv4']['ip'] ?? null,
            'ipv6_subnet'       => $server['public_net']['ipv6']['network'] ?? null,
            'created_at'        => date('Y-m-d H:i:s'),
        ]);

        return ['status' => 'success', 'message' => "Server '{$server['name']}' adopted into service #{$serviceId}."];
    }
}
