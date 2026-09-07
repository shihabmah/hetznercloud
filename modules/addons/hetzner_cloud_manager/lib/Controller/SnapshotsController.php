<?php
/**
 * Hetzner Cloud Manager - Snapshots & Backup Lifecycle Controller
 *
 * Estate-wide snapshot inspector across all active accounts, with
 * per-server filtering, aggregate storage totals (for the Dashboard's
 * disk-GB metric and future per-GB billing), manual snapshot creation,
 * restore-to-new-server, and "publish as template" (making a private
 * snapshot reusable as a base OS image across future provisions).
 *
 * @package HetznerCloudManager\Controller
 */

namespace HetznerCloudManager\Controller;

use HetznerCloudManager\Api\HetznerClient;
use WHMCS\Database\Capsule;
use Exception;

class SnapshotsController
{
    public static function handlePost(array $post): array
    {
        $action = $post['snapshot_action'] ?? '';
        $accountId = (int) ($post['account_id'] ?? 0);

        try {
            if ($accountId <= 0) {
                return ['status' => 'error', 'message' => 'An account must be specified for this action.'];
            }

            $client = HetznerClient::forAccount($accountId);

            switch ($action) {
                case 'create':
                    return self::create($client, $post);
                case 'delete':
                    return self::delete($client, $post);
                case 'restore':
                    return self::restore($client, $post);
                case 'publish_template':
                    return self::publishAsTemplate($client, $post);
                case 'toggle_auto_backup':
                    return self::toggleAutoBackup($client, $post);
                default:
                    return ['status' => 'error', 'message' => 'Unknown snapshot action.'];
            }
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * All image-type "snapshot" entries across every active account,
     * annotated with the account and (if known) the WHMCS service it
     * belongs to, plus aggregate storage-consumed totals.
     */
    public static function listAll(): array
    {
        $snapshots = [];
        $totalGb = 0.0;
        $errors = [];

        $accounts = Capsule::table('mod_hetzner_cloud_accounts')->where('is_active', 1)->get();
        $instancesByServerId = Capsule::table('mod_hetzner_cloud_instances')->get()->keyBy('hetzner_server_id');

        foreach ($accounts as $account) {
            try {
                $client = HetznerClient::forAccount($account->id);
                foreach ($client->getImages(['type' => 'snapshot']) as $image) {
                    $sizeGb = (float) ($image['image_size'] ?? $image['disk_size'] ?? 0);
                    $totalGb += $sizeGb;

                    $createdFromServer = $image['created_from']['id'] ?? null;
                    $linked = $createdFromServer ? $instancesByServerId->get($createdFromServer) : null;

                    $snapshots[] = [
                        'account_id' => $account->id,
                        'account_name' => $account->account_name,
                        'id' => $image['id'],
                        'description' => $image['description'] ?? ('snapshot-' . $image['id']),
                        'size_gb' => $sizeGb,
                        'created' => $image['created'] ?? null,
                        'created_from_server_id' => $createdFromServer,
                        'created_from_server_name' => $image['created_from']['name'] ?? null,
                        'linked_service_id' => $linked->service_id ?? null,
                        'status' => $image['status'] ?? 'available',
                    ];
                }
            } catch (Exception $e) {
                $errors[] = "{$account->account_name}: {$e->getMessage()}";
            }
        }

        return ['snapshots' => $snapshots, 'total_gb' => $totalGb, 'errors' => $errors];
    }

    /**
     * Snapshots belonging to a single server, for the client-area
     * Snapshots tab (also usable from admin with a server filter).
     */
    public static function listForServer(int $accountId, int $serverId): array
    {
        $client = HetznerClient::forAccount($accountId);
        $all = $client->getImages(['type' => 'snapshot']);

        return array_values(array_filter($all, function ($image) use ($serverId) {
            return ($image['created_from']['id'] ?? null) === $serverId;
        }));
    }

    protected static function create(HetznerClient $client, array $post): array
    {
        $serverId = (int) ($post['server_id'] ?? 0);
        $description = trim($post['description'] ?? '') ?: ('manual-snapshot-' . date('Y-m-d-His'));

        if ($serverId <= 0) {
            return ['status' => 'error', 'message' => 'A server must be selected to snapshot.'];
        }

        // Enforce the per-instance snapshot_limit tracked in mod_hetzner_cloud_instances,
        // if a linked WHMCS service caps how many manual snapshots a client may keep.
        $instance = Capsule::table('mod_hetzner_cloud_instances')->where('hetzner_server_id', $serverId)->first();
        if ($instance) {
            $currentCount = count(self::listForServer($instance->account_id, $serverId));
            if ($currentCount >= (int) $instance->snapshot_limit) {
                return [
                    'status' => 'error',
                    'message' => "Snapshot limit reached ({$instance->snapshot_limit}). Delete an existing snapshot before creating a new one.",
                ];
            }
        }

        $client->serverAction($serverId, 'create_image', ['description' => $description, 'type' => 'snapshot']);

        return ['status' => 'success', 'message' => "Snapshot '{$description}' is being created."];
    }

    protected static function delete(HetznerClient $client, array $post): array
    {
        $id = (int) ($post['snapshot_id'] ?? 0);
        $client->request('DELETE', "images/{$id}");
        return ['status' => 'success', 'message' => 'Snapshot deleted.'];
    }

    /**
     * Restore = rebuild the *original* server from the snapshot image
     * (destructive) when restore_target is 'same', or create a brand
     * new server from the snapshot when restore_target is 'new'.
     */
    protected static function restore(HetznerClient $client, array $post): array
    {
        $snapshotId = (int) ($post['snapshot_id'] ?? 0);
        $target = $post['restore_target'] ?? 'same';

        if ($target === 'new') {
            $result = $client->request('POST', 'servers', [
                'name' => $post['new_server_name'] ?? ('restored-' . time()),
                'server_type' => $post['server_type'] ?? '',
                'location' => $post['location'] ?? '',
                'image' => $snapshotId,
            ]);

            return [
                'status' => 'success',
                'message' => 'New server provisioned from snapshot: ' . ($result['server']['name'] ?? ''),
            ];
        }

        $serverId = (int) ($post['server_id'] ?? 0);
        if ($serverId <= 0) {
            return ['status' => 'error', 'message' => 'Original server ID is required to restore in place.'];
        }

        $client->serverAction($serverId, 'rebuild', ['image' => $snapshotId]);

        return ['status' => 'success', 'message' => 'Server rebuild from snapshot initiated (destructive - existing disk contents are replaced).'];
    }

    /**
     * "Publish as template" simply updates the snapshot's description
     * with a recognizable prefix so it is easy to find and reuse as an
     * OS image option in future product imports. Hetzner treats all
     * snapshots as private images already scoped to the project.
     */
    protected static function publishAsTemplate(HetznerClient $client, array $post): array
    {
        $id = (int) ($post['snapshot_id'] ?? 0);
        $label = trim($post['template_label'] ?? '');

        if ($label === '') {
            return ['status' => 'error', 'message' => 'A template label is required.'];
        }

        $client->request('POST', "images/{$id}", ['description' => '[Template] ' . $label]);

        return ['status' => 'success', 'message' => "Snapshot published as reusable template '{$label}'."];
    }

    protected static function toggleAutoBackup(HetznerClient $client, array $post): array
    {
        $serverId = (int) ($post['server_id'] ?? 0);
        $enable = ($post['enable'] ?? '1') === '1';

        if ($enable) {
            $client->serverAction($serverId, 'enable_backup');
        } else {
            $client->serverAction($serverId, 'disable_backup');
        }

        $instance = Capsule::table('mod_hetzner_cloud_instances')->where('hetzner_server_id', $serverId)->first();
        if ($instance) {
            Capsule::table('mod_hetzner_cloud_instances')->where('id', $instance->id)->update([
                'backups_enabled' => $enable ? 1 : 0,
            ]);
        }

        return ['status' => 'success', 'message' => $enable ? 'Automated backups enabled.' : 'Automated backups disabled.'];
    }
}
