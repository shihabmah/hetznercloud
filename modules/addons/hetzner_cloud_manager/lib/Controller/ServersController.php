<?php
/**
 * Hetzner Cloud Manager - Servers Tab Controller
 *
 * Thin POST dispatcher for the Servers tab (orphan adoption, quick
 * power actions from the estate list). Read-side listing/orphan/stale
 * detection lives in DashboardController since the Dashboard widget
 * and the Servers tab both need it.
 *
 * @package HetznerCloudManager\Controller
 */

namespace HetznerCloudManager\Controller;

use HetznerCloudManager\Api\HetznerClient;
use WHMCS\Database\Capsule;
use Exception;

class ServersController
{
    public static function handlePost(array $post): array
    {
        $action = $post['server_action'] ?? '';

        try {
            switch ($action) {
                case 'adopt':
                    return DashboardController::adoptOrphan(
                        (int) ($post['account_id'] ?? 0),
                        (int) ($post['hetzner_server_id'] ?? 0),
                        (int) ($post['service_id'] ?? 0)
                    );
                case 'unlink':
                    return self::unlink((int) ($post['service_id'] ?? 0));
                case 'power':
                    return self::power(
                        (int) ($post['service_id'] ?? 0),
                        (string) ($post['power_action'] ?? '')
                    );
                default:
                    return ['status' => 'error', 'message' => 'Unknown server action.'];
            }
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Remove the WHMCS <-> Hetzner mapping without touching the live
     * server. Used to un-adopt a mistaken link.
     */
    protected static function unlink(int $serviceId): array
    {
        $deleted = Capsule::table('mod_hetzner_cloud_instances')->where('service_id', $serviceId)->delete();

        if (!$deleted) {
            return ['status' => 'error', 'message' => 'No linked instance found for that service.'];
        }

        return ['status' => 'success', 'message' => "Service #{$serviceId} unlinked from its Hetzner server (server was not modified)."];
    }

    protected static function power(int $serviceId, string $powerAction): array
    {
        $allowed = ['poweron', 'poweroff', 'shutdown', 'reset', 'reboot'];
        if (!in_array($powerAction, $allowed, true)) {
            return ['status' => 'error', 'message' => 'Unsupported power action.'];
        }

        $instance = Capsule::table('mod_hetzner_cloud_instances')->where('service_id', $serviceId)->first();
        if (!$instance) {
            return ['status' => 'error', 'message' => 'No linked instance found for that service.'];
        }

        $client = HetznerClient::forAccount($instance->account_id);
        $client->serverAction((int) $instance->hetzner_server_id, $powerAction);

        return ['status' => 'success', 'message' => "Action '{$powerAction}' sent to {$instance->server_name}."];
    }

    /**
     * Full estate listing across all active accounts, for the Servers
     * tab's "All Servers" table (distinct from the orphan/stale views).
     */
    public static function listEstate(): array
    {
        $rows = [];
        $instances = Capsule::table('mod_hetzner_cloud_instances')->get()->keyBy('hetzner_server_id');
        $accounts = Capsule::table('mod_hetzner_cloud_accounts')->where('is_active', 1)->get();

        foreach ($accounts as $account) {
            try {
                $client = HetznerClient::forAccount($account->id);
                foreach ($client->getServers() as $server) {
                    $linked = $instances->get($server['id']);
                    $rows[] = [
                        'account_name' => $account->account_name,
                        'server_id' => $server['id'],
                        'server_name' => $server['name'],
                        'status' => $server['status'],
                        'server_type' => $server['server_type']['name'] ?? '',
                        'datacenter' => $server['datacenter']['name'] ?? '',
                        'ipv4' => $server['public_net']['ipv4']['ip'] ?? null,
                        'service_id' => $linked->service_id ?? null,
                    ];
                }
            } catch (Exception $e) {
                continue;
            }
        }

        return $rows;
    }
}
