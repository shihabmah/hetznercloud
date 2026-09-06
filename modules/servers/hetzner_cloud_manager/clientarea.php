<?php
/**
 * Hetzner Cloud Manager - Client Area AJAX Controller
 *
 * Standalone endpoint invoked directly by clientarea.tpl's JavaScript
 * (fetch/XHR) rather than through WHMCS's templatefile mechanism, so
 * power actions, the noVNC console, rescue mode, OS rebuilds, PTR
 * updates, firewall toggles, and snapshot management can all respond
 * without a full page reload.
 *
 * Security model:
 *  - Bootstraps the WHMCS application directly (same pattern WHMCS uses
 *    for its own AJAX endpoints) so we get session handling for free.
 *  - Requires an authenticated client session OR an admin impersonation
 *    session; the client must own the requested service (or be staff).
 *  - Requires a valid per-session CSRF token on every POST.
 *  - Every destructive action is re-validated against the feature
 *    toggles configured on the product (AllowClientRebuild, etc.)
 *    server-side - the UI hiding a button is a convenience, not the
 *    security boundary.
 *
 * @package HetznerCloudManager
 */

$whmcsRoot = realpath(__DIR__ . '/../../..');
$initFile = $whmcsRoot . '/init.php';

if (!is_file($initFile)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'WHMCS bootstrap not found.']);
    exit;
}

define('WHMCS', true);
require_once $initFile;
require_once __DIR__ . '/lib/bootstrap.php';

use HetznerCloudManager\Api\HetznerClient;
use HetznerCloudManager\Controller\SnapshotsController;
use HetznerCloudManager\Controller\ResourcesController;
use WHMCS\Database\Capsule;
use WHMCS\Session;

header('Content-Type: application/json');

function hcm_ajax_respond(array $payload, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($payload);
    exit;
}

// ---------------------------------------------------------------------
// Authentication: client must be logged in, or staff must be logged in.
// ---------------------------------------------------------------------
$isClient = (bool) (Session::get('uid') ?? 0);
$isAdmin = (bool) (Session::get('adminid') ?? 0);

if (!$isClient && !$isAdmin) {
    hcm_ajax_respond(['status' => 'error', 'message' => 'Authentication required.'], 401);
}

// ---------------------------------------------------------------------
// CSRF protection on state-changing requests.
// ---------------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'];
$input = $method === 'POST' ? $_POST : $_GET;

if ($method === 'POST') {
    $token = $input['csrf_token'] ?? '';
    $sessionToken = Session::get('hcm_csrf_token');
    if (!$sessionToken || !hash_equals((string) $sessionToken, (string) $token)) {
        hcm_ajax_respond(['status' => 'error', 'message' => 'Invalid or expired security token. Please reload the page.'], 403);
    }
}

$serviceId = (int) ($input['serviceid'] ?? 0);
if ($serviceId <= 0) {
    hcm_ajax_respond(['status' => 'error', 'message' => 'A service ID is required.'], 400);
}

$service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
if (!$service) {
    hcm_ajax_respond(['status' => 'error', 'message' => 'Service not found.'], 404);
}

// A logged-in client may only act on their own service; staff bypass this check.
if ($isClient && !$isAdmin && (int) $service->userid !== (int) Session::get('uid')) {
    hcm_ajax_respond(['status' => 'error', 'message' => 'You do not have permission to manage this service.'], 403);
}

$instance = Capsule::table('mod_hetzner_cloud_instances')->where('service_id', $serviceId)->first();
if (!$instance) {
    hcm_ajax_respond(['status' => 'error', 'message' => 'No Hetzner server is linked to this service yet.'], 404);
}

$product = Capsule::table('tblproducts')->where('id', $service->packageid)->first();

/**
 * Re-check a feature toggle (product ConfigOptions 6-10) before allowing
 * a destructive client-initiated action, regardless of what the UI showed.
 */
function hcm_feature_allowed(int $serviceId, int $configOptionIndex, bool $isAdmin): bool
{
    if ($isAdmin) {
        return true; // staff always retain full control regardless of client-facing toggles
    }

    // Feature toggles are Module Settings (fixed per-product), not
    // per-order Configurable Options, so read them from tblproducts.
    $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
    if (!$service) {
        return false;
    }
    $product = Capsule::table('tblproducts')->where('id', $service->packageid)->first();
    if (!$product) {
        return false;
    }

    $column = 'configoption' . $configOptionIndex;
    return ($product->{$column} ?? 'off') === 'on';
}

try {
    $client = HetznerClient::forAccount($instance->account_id);
    $serverId = (int) $instance->hetzner_server_id;
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'status':
            $server = $client->getServer($serverId);
            hcm_ajax_respond([
                'status' => 'success',
                'server' => [
                    'name' => $server['name'] ?? '',
                    'status' => $server['status'] ?? 'unknown',
                    'ipv4' => $server['public_net']['ipv4']['ip'] ?? null,
                    'ipv6' => $server['public_net']['ipv6']['network'] ?? null,
                    'cores' => $server['server_type']['cores'] ?? null,
                    'memory' => $server['server_type']['memory'] ?? null,
                    'disk' => $server['server_type']['disk'] ?? null,
                    'datacenter' => $server['datacenter']['name'] ?? null,
                    'backup_window' => $server['backup_window'] ?? null,
                ],
            ]);
            break;

        case 'poweron':
            $client->serverAction($serverId, 'poweron');
            hcm_ajax_respond(['status' => 'success', 'message' => 'Start command sent.']);
            break;

        case 'shutdown':
            $client->serverAction($serverId, 'shutdown');
            hcm_ajax_respond(['status' => 'success', 'message' => 'Graceful shutdown command sent.']);
            break;

        case 'poweroff':
            $client->serverAction($serverId, 'poweroff');
            hcm_ajax_respond(['status' => 'success', 'message' => 'Power off command sent.']);
            break;

        case 'reboot':
            $client->serverAction($serverId, 'reboot');
            hcm_ajax_respond(['status' => 'success', 'message' => 'Soft reboot command sent.']);
            break;

        case 'reset':
            $client->serverAction($serverId, 'reset');
            hcm_ajax_respond(['status' => 'success', 'message' => 'Hard reset command sent.']);
            break;

        case 'console':
            if (!hcm_feature_allowed($serviceId, 8, $isAdmin)) {
                hcm_ajax_respond(['status' => 'error', 'message' => 'The web console is disabled for this product.'], 403);
            }
            $result = $client->serverAction($serverId, 'request_console');
            hcm_ajax_respond([
                'status' => 'success',
                'wss_url' => $result['wss_url'] ?? ($result['action']['resources'][0]['url'] ?? null),
                'password' => $result['password'] ?? ($result['action']['password'] ?? null),
            ]);
            break;

        case 'enable_rescue':
            if (!hcm_feature_allowed($serviceId, 7, $isAdmin)) {
                hcm_ajax_respond(['status' => 'error', 'message' => 'Rescue mode is disabled for this product.'], 403);
            }
            $result = $client->serverAction($serverId, 'enable_rescue', ['type' => $input['rescue_type'] ?? 'linux64']);
            if (!empty($result['root_password']) && function_exists('localAPI')) {
                localAPI('SendEmail', [
                    'messagename' => 'Hetzner Cloud - Rescue Mode Enabled',
                    'id' => $serviceId,
                    'customtype' => 'product',
                    'customvars' => base64_encode(serialize([
                        'server_ip' => $instance->ipv4_address,
                        'rescue_password' => $result['root_password'],
                    ])),
                ]);
            }
            hcm_ajax_respond(['status' => 'success', 'rescue_password' => $result['root_password'] ?? null]);
            break;

        case 'disable_rescue':
            if (!hcm_feature_allowed($serviceId, 7, $isAdmin)) {
                hcm_ajax_respond(['status' => 'error', 'message' => 'Rescue mode is disabled for this product.'], 403);
            }
            $client->serverAction($serverId, 'disable_rescue');
            hcm_ajax_respond(['status' => 'success', 'message' => 'Rescue mode disabled.']);
            break;

        case 'rebuild':
            if (!hcm_feature_allowed($serviceId, 6, $isAdmin)) {
                hcm_ajax_respond(['status' => 'error', 'message' => 'OS reinstall is disabled for this product.'], 403);
            }
            $image = trim($input['image'] ?? '');
            if ($image === '') {
                hcm_ajax_respond(['status' => 'error', 'message' => 'An image must be specified.'], 400);
            }
            $result = $client->serverAction($serverId, 'rebuild', ['image' => $image]);
            if (!empty($result['root_password']) && function_exists('localAPI')) {
                localAPI('SendEmail', [
                    'messagename' => 'Hetzner Cloud - Server Rebuilt',
                    'id' => $serviceId,
                    'customtype' => 'product',
                    'customvars' => base64_encode(serialize([
                        'server_ip' => $instance->ipv4_address,
                        'rebuilt_os_name' => $image,
                        'new_root_password' => $result['root_password'],
                    ])),
                ]);
            }
            hcm_ajax_respond(['status' => 'success', 'root_password' => $result['root_password'] ?? null, 'message' => "Server is being rebuilt with {$image}."]);
            break;

        case 'images':
            // Available OS images for the rebuild dropdown.
            hcm_ajax_respond(['status' => 'success', 'images' => $client->getImages(['type' => 'system'])]);
            break;

        case 'update_ptr':
            if (!hcm_feature_allowed($serviceId, 9, $isAdmin)) {
                hcm_ajax_respond(['status' => 'error', 'message' => 'Reverse DNS management is disabled for this product.'], 403);
            }
            $ip = trim($input['ip'] ?? '');
            $ptr = trim($input['ptr'] ?? '');
            if ($ip === '' || $ptr === '') {
                hcm_ajax_respond(['status' => 'error', 'message' => 'Both IP and PTR hostname are required.'], 400);
            }
            $client->serverAction($serverId, 'change_dns_ptr', ['ip' => $ip, 'dns_ptr' => $ptr]);
            hcm_ajax_respond(['status' => 'success', 'message' => "PTR record for {$ip} updated to {$ptr}."]);
            break;

        case 'firewalls_list':
            if (!hcm_feature_allowed($serviceId, 10, $isAdmin)) {
                hcm_ajax_respond(['status' => 'error', 'message' => 'Firewall management is disabled for this product.'], 403);
            }
            $server = $client->getServer($serverId);
            hcm_ajax_respond(['status' => 'success', 'applied' => $server['public_net']['firewalls'] ?? [], 'available' => $client->requestAll('firewalls', 'firewalls')]);
            break;

        case 'firewall_toggle':
            if (!hcm_feature_allowed($serviceId, 10, $isAdmin)) {
                hcm_ajax_respond(['status' => 'error', 'message' => 'Firewall management is disabled for this product.'], 403);
            }
            $firewallId = (int) ($input['firewall_id'] ?? 0);
            $apply = ($input['apply'] ?? '1') === '1';
            $endpoint = $apply ? 'apply_to_resources' : 'remove_from_resources';
            $client->request('POST', "firewalls/{$firewallId}/actions/{$endpoint}", [
                'apply_to' => [['type' => 'server', 'server' => ['id' => $serverId]]],
            ]);
            hcm_ajax_respond(['status' => 'success', 'message' => $apply ? 'Firewall applied.' : 'Firewall removed.']);
            break;

        case 'snapshots_list':
            hcm_ajax_respond(['status' => 'success', 'snapshots' => SnapshotsController::listForServer($instance->account_id, $serverId), 'limit' => (int) $instance->snapshot_limit]);
            break;

        case 'snapshot_create':
            $result = SnapshotsController::handlePost([
                'snapshot_action' => 'create',
                'account_id' => $instance->account_id,
                'server_id' => $serverId,
                'description' => $input['description'] ?? '',
            ]);
            hcm_ajax_respond($result);
            break;

        case 'snapshot_delete':
            $result = SnapshotsController::handlePost([
                'snapshot_action' => 'delete',
                'account_id' => $instance->account_id,
                'snapshot_id' => (int) ($input['snapshot_id'] ?? 0),
            ]);
            hcm_ajax_respond($result);
            break;

        case 'snapshot_restore':
            $result = SnapshotsController::handlePost([
                'snapshot_action' => 'restore',
                'account_id' => $instance->account_id,
                'server_id' => $serverId,
                'snapshot_id' => (int) ($input['snapshot_id'] ?? 0),
                'restore_target' => 'same',
            ]);
            hcm_ajax_respond($result);
            break;

        case 'backups_toggle':
            $result = SnapshotsController::handlePost([
                'snapshot_action' => 'toggle_auto_backup',
                'account_id' => $instance->account_id,
                'server_id' => $serverId,
                'enable' => ($input['enable'] ?? '1'),
            ]);
            hcm_ajax_respond($result);
            break;

        case 'volumes_list':
            $server = $client->getServer($serverId);
            $volumes = [];
            foreach ($server['volumes'] ?? [] as $volumeId) {
                try {
                    $volumes[] = $client->request('GET', "volumes/{$volumeId}")['volume'] ?? [];
                } catch (\Exception $e) {
                    continue;
                }
            }
            hcm_ajax_respond(['status' => 'success', 'volumes' => $volumes]);
            break;

        case 'traffic':
            $metrics = $client->request('GET', "servers/{$serverId}/metrics", [
                'type' => 'network',
                'start' => date('c', strtotime('-7 days')),
                'end' => date('c'),
            ]);
            hcm_ajax_respond(['status' => 'success', 'metrics' => $metrics['metrics'] ?? []]);
            break;

        default:
            hcm_ajax_respond(['status' => 'error', 'message' => 'Unsupported action.'], 400);
    }
} catch (\Exception $e) {
    hcm_ajax_respond(['status' => 'error', 'message' => $e->getMessage()], 500);
}
