<?php
/**
 * Hetzner Cloud Manager - Multi-Project API Accounts Controller
 *
 * Full CRUD for Hetzner Cloud API project tokens (mod_hetzner_cloud_accounts),
 * plus a "Test Connection" action that verifies a token by calling a
 * cheap, read-only endpoint and reporting back the token's scope/health.
 *
 * @package HetznerCloudManager\Controller
 */

namespace HetznerCloudManager\Controller;

use HetznerCloudManager\Api\HetznerClient;
use WHMCS\Database\Capsule;
use Exception;

class AccountsController
{
    /**
     * Dispatch admin POST actions (create/update/delete/test) and return
     * a redirect-safe status message. GET rendering is handled separately
     * by index().
     */
    public static function handlePost(array $post): array
    {
        $action = $post['account_action'] ?? '';

        try {
            switch ($action) {
                case 'create':
                    return self::create($post);
                case 'update':
                    return self::update($post);
                case 'delete':
                    return self::delete($post);
                case 'toggle':
                    return self::toggleActive($post);
                case 'test':
                    return self::testConnection((int) ($post['account_id'] ?? 0));
                default:
                    return ['status' => 'error', 'message' => 'Unknown account action.'];
            }
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    protected static function create(array $post): array
    {
        $name = trim($post['account_name'] ?? '');
        $token = trim($post['api_token'] ?? '');

        if ($name === '' || $token === '') {
            return ['status' => 'error', 'message' => 'Account name and API token are required.'];
        }

        $id = Capsule::table('mod_hetzner_cloud_accounts')->insertGetId([
            'account_name' => $name,
            'api_token'    => $token,
            'is_active'    => 1,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        $test = self::testConnection($id);

        return [
            'status'  => 'success',
            'message' => 'Account created.' . ($test['status'] === 'error' ? ' Warning: ' . $test['message'] : ' Connection verified.'),
        ];
    }

    protected static function update(array $post): array
    {
        $id = (int) ($post['account_id'] ?? 0);
        $account = Capsule::table('mod_hetzner_cloud_accounts')->where('id', $id)->first();
        if (!$account) {
            return ['status' => 'error', 'message' => 'Account not found.'];
        }

        $update = [
            'account_name' => trim($post['account_name'] ?? $account->account_name),
            'updated_at'   => date('Y-m-d H:i:s'),
        ];

        if (!empty($post['api_token'])) {
            $update['api_token'] = trim($post['api_token']);
        }

        Capsule::table('mod_hetzner_cloud_accounts')->where('id', $id)->update($update);

        return ['status' => 'success', 'message' => 'Account updated.'];
    }

    protected static function delete(array $post): array
    {
        $id = (int) ($post['account_id'] ?? 0);

        $inUse = Capsule::table('mod_hetzner_cloud_instances')->where('account_id', $id)->count();
        if ($inUse > 0) {
            return [
                'status'  => 'error',
                'message' => "Cannot delete: {$inUse} provisioned server(s) still reference this account. Reassign or terminate them first.",
            ];
        }

        Capsule::table('mod_hetzner_cloud_accounts')->where('id', $id)->delete();

        return ['status' => 'success', 'message' => 'Account removed.'];
    }

    protected static function toggleActive(array $post): array
    {
        $id = (int) ($post['account_id'] ?? 0);
        $account = Capsule::table('mod_hetzner_cloud_accounts')->where('id', $id)->first();
        if (!$account) {
            return ['status' => 'error', 'message' => 'Account not found.'];
        }

        Capsule::table('mod_hetzner_cloud_accounts')->where('id', $id)->update([
            'is_active'  => $account->is_active ? 0 : 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return ['status' => 'success', 'message' => $account->is_active ? 'Account disabled.' : 'Account enabled.'];
    }

    /**
     * Verify a token by calling GET /v1/locations (cheap, always available,
     * requires a valid bearer token, and confirms outbound connectivity).
     */
    public static function testConnection(int $accountId): array
    {
        try {
            $client = HetznerClient::forAccount($accountId);
            $locations = $client->getLocations();

            Capsule::table('mod_hetzner_cloud_accounts')->where('id', $accountId)->update([
                'last_test_status'  => 'success',
                'last_test_message' => count($locations) . ' locations reachable at ' . date('Y-m-d H:i:s'),
            ]);

            return [
                'status'  => 'success',
                'message' => 'Connection OK: ' . count($locations) . ' datacenter locations visible to this token.',
            ];
        } catch (Exception $e) {
            Capsule::table('mod_hetzner_cloud_accounts')->where('id', $accountId)->update([
                'last_test_status'  => 'error',
                'last_test_message' => $e->getMessage(),
            ]);

            return ['status' => 'error', 'message' => 'Connection failed: ' . $e->getMessage()];
        }
    }

    /**
     * Fetch all accounts with derived display fields (masked token,
     * rate-limit percentage) for the accounts.tpl listing.
     */
    public static function listAccounts(): array
    {
        $accounts = Capsule::table('mod_hetzner_cloud_accounts')->orderBy('account_name')->get();

        return array_map(function ($account) {
            $account->masked_token = self::maskToken($account->api_token);
            $account->rate_limit_pct = $account->rate_limit_remaining > 0
                ? min(100, round(($account->rate_limit_remaining / 3600) * 100))
                : 0;
            return $account;
        }, $accounts->all());
    }

    protected static function maskToken(string $token): string
    {
        if (strlen($token) <= 8) {
            return '••••••••';
        }
        return substr($token, 0, 4) . '••••••••••••' . substr($token, -4);
    }
}
