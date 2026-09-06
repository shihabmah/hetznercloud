<?php
/**
 * Hetzner Cloud Manager - Resource Management Controller
 *
 * Cross-account management of Firewalls, Floating/Primary IPs, Block
 * Storage Volumes, Private Networks, SSH Keys, and ISOs. Each resource
 * type is listed across every active Hetzner account and tagged with
 * its owning account so the admin can act on it without switching
 * project context manually.
 *
 * @package HetznerCloudManager\Controller
 */

namespace HetznerCloudManager\Controller;

use HetznerCloudManager\Api\HetznerClient;
use WHMCS\Database\Capsule;
use Exception;

class ResourcesController
{
    public static function handlePost(array $post): array
    {
        $action = $post['resource_action'] ?? '';
        $accountId = (int) ($post['account_id'] ?? 0);

        try {
            if ($accountId <= 0) {
                return ['status' => 'error', 'message' => 'An account must be specified for this action.'];
            }

            $client = HetznerClient::forAccount($accountId);

            switch ($action) {
                case 'firewall_create':
                    return self::createFirewall($client, $post);
                case 'firewall_delete':
                    return self::deleteFirewall($client, $post);
                case 'firewall_apply':
                    return self::applyFirewall($client, $post);
                case 'floating_ip_create':
                    return self::createFloatingIp($client, $post);
                case 'floating_ip_ptr':
                    return self::setFloatingIpPtr($client, $post);
                case 'floating_ip_delete':
                    return self::deleteFloatingIp($client, $post);
                case 'volume_create':
                    return self::createVolume($client, $post);
                case 'volume_attach':
                    return self::attachVolume($client, $post);
                case 'volume_detach':
                    return self::detachVolume($client, $post);
                case 'volume_resize':
                    return self::resizeVolume($client, $post);
                case 'volume_delete':
                    return self::deleteVolume($client, $post);
                case 'network_create':
                    return self::createNetwork($client, $post);
                case 'network_delete':
                    return self::deleteNetwork($client, $post);
                case 'ssh_key_create':
                    return self::createSshKey($client, $post);
                case 'ssh_key_delete':
                    return self::deleteSshKey($client, $post);
                default:
                    return ['status' => 'error', 'message' => 'Unknown resource action.'];
            }
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------
    // Aggregated listings across all active accounts
    // -------------------------------------------------------------------

    public static function listAll(): array
    {
        $result = [
            'firewalls' => [],
            'floating_ips' => [],
            'volumes' => [],
            'networks' => [],
            'ssh_keys' => [],
            'isos' => [],
            'errors' => [],
        ];

        $accounts = Capsule::table('mod_hetzner_cloud_accounts')->where('is_active', 1)->get();

        foreach ($accounts as $account) {
            try {
                $client = HetznerClient::forAccount($account->id);

                foreach ($client->requestAll('firewalls', 'firewalls') as $fw) {
                    $fw['account_id'] = $account->id;
                    $fw['account_name'] = $account->account_name;
                    $result['firewalls'][] = $fw;
                }
                foreach ($client->requestAll('floating_ips', 'floating_ips') as $ip) {
                    $ip['account_id'] = $account->id;
                    $ip['account_name'] = $account->account_name;
                    $result['floating_ips'][] = $ip;
                }
                foreach ($client->requestAll('volumes', 'volumes') as $vol) {
                    $vol['account_id'] = $account->id;
                    $vol['account_name'] = $account->account_name;
                    $result['volumes'][] = $vol;
                }
                foreach ($client->requestAll('networks', 'networks') as $net) {
                    $net['account_id'] = $account->id;
                    $net['account_name'] = $account->account_name;
                    $result['networks'][] = $net;
                }
                foreach ($client->requestAll('ssh_keys', 'ssh_keys') as $key) {
                    $key['account_id'] = $account->id;
                    $key['account_name'] = $account->account_name;
                    $result['ssh_keys'][] = $key;
                }
                // ISOs are a distinct endpoint from images - kept separate so
                // mountable ISOs are never conflated with OS template images.
                foreach ($client->requestAll('isos', 'isos') as $iso) {
                    $iso['account_id'] = $account->id;
                    $iso['account_name'] = $account->account_name;
                    $result['isos'][] = $iso;
                }
            } catch (Exception $e) {
                $result['errors'][] = "{$account->account_name}: {$e->getMessage()}";
            }
        }

        return $result;
    }

    /**
     * Servers for a given account, used to populate "attach to server" /
     * "apply firewall to" selects in the Resources tab forms.
     */
    public static function listServersForAccount(int $accountId): array
    {
        try {
            return HetznerClient::forAccount($accountId)->getServers();
        } catch (Exception $e) {
            return [];
        }
    }

    // -------------------------------------------------------------------
    // Firewalls
    // -------------------------------------------------------------------

    protected static function createFirewall(HetznerClient $client, array $post): array
    {
        $name = trim($post['name'] ?? '');
        if ($name === '') {
            return ['status' => 'error', 'message' => 'Firewall name is required.'];
        }

        $rules = [];
        $ports = explode(',', $post['ports'] ?? '22,80,443');
        foreach ($ports as $port) {
            $port = trim($port);
            if ($port === '') {
                continue;
            }
            $rules[] = [
                'direction' => 'in',
                'protocol' => 'tcp',
                'port' => $port,
                'source_ips' => [$post['source_cidr'] ?? '0.0.0.0/0', '::/0'],
            ];
        }

        $client->request('POST', 'firewalls', ['name' => $name, 'rules' => $rules]);

        return ['status' => 'success', 'message' => "Firewall '{$name}' created with " . count($rules) . ' rule(s).'];
    }

    protected static function deleteFirewall(HetznerClient $client, array $post): array
    {
        $id = (int) ($post['firewall_id'] ?? 0);
        $client->request('DELETE', "firewalls/{$id}");
        return ['status' => 'success', 'message' => 'Firewall deleted.'];
    }

    protected static function applyFirewall(HetznerClient $client, array $post): array
    {
        $id = (int) ($post['firewall_id'] ?? 0);
        $serverId = (int) ($post['server_id'] ?? 0);

        $client->request('POST', "firewalls/{$id}/actions/apply_to_resources", [
            'apply_to' => [['type' => 'server', 'server' => ['id' => $serverId]]],
        ]);

        return ['status' => 'success', 'message' => 'Firewall applied to server.'];
    }

    // -------------------------------------------------------------------
    // Floating / Primary IPs
    // -------------------------------------------------------------------

    protected static function createFloatingIp(HetznerClient $client, array $post): array
    {
        $type = $post['ip_type'] ?? 'ipv4';
        $homeLocation = $post['home_location'] ?? null;
        $serverId = (int) ($post['server_id'] ?? 0);

        $payload = ['type' => $type, 'description' => $post['description'] ?? 'WHMCS managed floating IP'];
        if ($serverId > 0) {
            $payload['server'] = $serverId;
        } elseif ($homeLocation) {
            $payload['home_location'] = $homeLocation;
        } else {
            return ['status' => 'error', 'message' => 'Either a server or a home location is required to create a floating IP.'];
        }

        $result = $client->request('POST', 'floating_ips', $payload);

        return ['status' => 'success', 'message' => 'Floating IP created: ' . ($result['floating_ip']['ip'] ?? '')];
    }

    protected static function setFloatingIpPtr(HetznerClient $client, array $post): array
    {
        $id = (int) ($post['floating_ip_id'] ?? 0);
        $ip = $post['ip'] ?? '';
        $ptr = $post['ptr'] ?? '';

        $client->request('POST', "floating_ips/{$id}/actions/change_dns_ptr", ['ip' => $ip, 'dns_ptr' => $ptr]);

        return ['status' => 'success', 'message' => "PTR record for {$ip} updated to {$ptr}."];
    }

    protected static function deleteFloatingIp(HetznerClient $client, array $post): array
    {
        $id = (int) ($post['floating_ip_id'] ?? 0);
        $client->request('DELETE', "floating_ips/{$id}");
        return ['status' => 'success', 'message' => 'Floating IP released.'];
    }

    // -------------------------------------------------------------------
    // Block Storage Volumes
    // -------------------------------------------------------------------

    protected static function createVolume(HetznerClient $client, array $post): array
    {
        $size = (int) ($post['size'] ?? 0);
        $location = $post['location'] ?? null;
        $serverId = (int) ($post['server_id'] ?? 0);
        $format = $post['format'] ?? 'ext4';

        if ($size < 10) {
            return ['status' => 'error', 'message' => 'Volume size must be at least 10 GB.'];
        }

        $payload = [
            'size' => $size,
            'name' => $post['name'] ?? ('vol-' . time()),
            'format' => $format,
            'automount' => $serverId > 0,
        ];
        if ($serverId > 0) {
            $payload['server'] = $serverId;
        } elseif ($location) {
            $payload['location'] = $location;
        } else {
            return ['status' => 'error', 'message' => 'Either a server or a location is required to create a volume.'];
        }

        $client->request('POST', 'volumes', $payload);

        return ['status' => 'success', 'message' => "Volume created ({$size} GB, {$format})."];
    }

    protected static function attachVolume(HetznerClient $client, array $post): array
    {
        $id = (int) ($post['volume_id'] ?? 0);
        $serverId = (int) ($post['server_id'] ?? 0);

        $client->request('POST', "volumes/{$id}/actions/attach", ['server' => $serverId, 'automount' => true]);

        return ['status' => 'success', 'message' => 'Volume attached.'];
    }

    protected static function detachVolume(HetznerClient $client, array $post): array
    {
        $id = (int) ($post['volume_id'] ?? 0);
        $client->request('POST', "volumes/{$id}/actions/detach");
        return ['status' => 'success', 'message' => 'Volume detached.'];
    }

    protected static function resizeVolume(HetznerClient $client, array $post): array
    {
        $id = (int) ($post['volume_id'] ?? 0);
        $size = (int) ($post['size'] ?? 0);

        if ($size < 10) {
            return ['status' => 'error', 'message' => 'Volume size must be at least 10 GB.'];
        }

        $client->request('POST', "volumes/{$id}/actions/resize", ['size' => $size]);

        return ['status' => 'success', 'message' => "Volume resized to {$size} GB (grow-only, per Hetzner API constraints)."];
    }

    protected static function deleteVolume(HetznerClient $client, array $post): array
    {
        $id = (int) ($post['volume_id'] ?? 0);
        $client->request('DELETE', "volumes/{$id}");
        return ['status' => 'success', 'message' => 'Volume deleted.'];
    }

    // -------------------------------------------------------------------
    // Private Networks
    // -------------------------------------------------------------------

    protected static function createNetwork(HetznerClient $client, array $post): array
    {
        $name = trim($post['name'] ?? '');
        $ipRange = trim($post['ip_range'] ?? '10.0.0.0/16');

        if ($name === '') {
            return ['status' => 'error', 'message' => 'Network name is required.'];
        }

        $client->request('POST', 'networks', [
            'name' => $name,
            'ip_range' => $ipRange,
            'subnets' => [[
                'type' => 'cloud',
                'ip_range' => $ipRange,
                'network_zone' => $post['network_zone'] ?? 'eu-central',
            ]],
        ]);

        return ['status' => 'success', 'message' => "Network '{$name}' created ({$ipRange})."];
    }

    protected static function deleteNetwork(HetznerClient $client, array $post): array
    {
        $id = (int) ($post['network_id'] ?? 0);
        $client->request('DELETE', "networks/{$id}");
        return ['status' => 'success', 'message' => 'Network deleted.'];
    }

    // -------------------------------------------------------------------
    // SSH Keys
    // -------------------------------------------------------------------

    protected static function createSshKey(HetznerClient $client, array $post): array
    {
        $name = trim($post['name'] ?? '');
        $publicKey = trim($post['public_key'] ?? '');

        if ($name === '' || $publicKey === '') {
            return ['status' => 'error', 'message' => 'Key name and public key content are both required.'];
        }

        $client->request('POST', 'ssh_keys', ['name' => $name, 'public_key' => $publicKey]);

        return ['status' => 'success', 'message' => "SSH key '{$name}' added."];
    }

    protected static function deleteSshKey(HetznerClient $client, array $post): array
    {
        $id = (int) ($post['ssh_key_id'] ?? 0);
        $client->request('DELETE', "ssh_keys/{$id}");
        return ['status' => 'success', 'message' => 'SSH key removed.'];
    }
}
