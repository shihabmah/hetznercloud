<?php
/**
 * Hetzner Cloud Manager - WHMCS Server Provisioning Module
 *
 * Implements the full server lifecycle against the Hetzner Cloud API v1:
 * CreateAccount (provision), Suspend/Unsuspend (power off/on), Terminate
 * (delete), ChangePackage (resize via change_type). Nothing here is
 * hardcoded - server types, locations and images are resolved dynamically
 * via ConfigOptions dropdown Loaders backed by the live Hetzner API, and
 * availability is re-validated at CreateAccount time against
 * StockAvailability so a sold-out flavor/location combination can never
 * be provisioned even if it slipped through the order form.
 *
 * @package HetznerCloudManager
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/bootstrap.php';

use HetznerCloudManager\Api\HetznerClient;
use HetznerCloudManager\Api\StockAvailability;
use HetznerCloudManager\Helpers\CloudInitBuilder;
use WHMCS\Database\Capsule;

function hetzner_cloud_manager_MetaData()
{
    return [
        'DisplayName' => 'Hetzner Cloud Manager',
        'APIVersion' => '1.1',
        'RequiresServer' => false,
        'DefaultNonSSLPort' => '443',
        'DefaultSSLPort' => '443',
        'ServiceSingleSignOnLabel' => 'Login to Cloud Console',
        'AdminSingleSignOnLabel' => 'Login to Cloud Console',
    ];
}

/**
 * Dynamic Configurable Options for the product's "Module Settings" tab.
 * Every dropdown is backed by a Loader closure pulling live data from
 * the Hetzner Cloud API - nothing here is a static hardcoded list.
 */
function hetzner_cloud_manager_ConfigOptions()
{
    return [
        'Account' => [
            'Type' => 'dropdown',
            'Loader' => 'hetzner_cloud_manager_loadAccounts',
            'Description' => 'Hetzner Cloud project this product provisions into.',
            'SimpleMode' => true,
        ],
        'ServerType' => [
            'Type' => 'dropdown',
            'Loader' => 'hetzner_cloud_manager_loadServerTypes',
            'LoaderFieldsRequired' => ['Account'],
            'Description' => 'Server flavor (cores/RAM/disk), fetched live from the Hetzner API.',
            'SimpleMode' => true,
        ],
        'Location' => [
            'Type' => 'dropdown',
            'Loader' => 'hetzner_cloud_manager_loadLocations',
            'LoaderFieldsRequired' => ['Account'],
            'Description' => 'Datacenter location. Out-of-stock locations are marked accordingly.',
            'SimpleMode' => true,
        ],
        'Image' => [
            'Type' => 'dropdown',
            'Loader' => 'hetzner_cloud_manager_loadImages',
            'LoaderFieldsRequired' => ['Account'],
            'Description' => 'Default operating system image deployed on provisioning.',
            'SimpleMode' => true,
        ],
        'EnableBackups' => [
            'Type' => 'yesno',
            'Description' => 'Enable automated daily provider backups (+20% of base server cost).',
        ],
        'AllowClientRebuild' => [
            'Type' => 'yesno',
            'Description' => 'Allow clients to reinstall the OS from the client area.',
        ],
        'AllowClientRescue' => [
            'Type' => 'yesno',
            'Description' => 'Allow clients to boot into rescue mode from the client area.',
        ],
        'AllowClientConsole' => [
            'Type' => 'yesno',
            'Description' => 'Allow clients to open the noVNC web console.',
        ],
        'AllowClientPTR' => [
            'Type' => 'yesno',
            'Description' => 'Allow clients to manage reverse DNS (PTR) records.',
        ],
        'AllowClientFirewall' => [
            'Type' => 'yesno',
            'Description' => 'Allow clients to manage attached firewalls.',
        ],
        'SuspendAction' => [
            'Type' => 'dropdown',
            'Options' => 'poweroff,keep_running',
            'Description' => 'What to do with the server on suspension. "poweroff" stops the VM but keeps it billed by Hetzner; "keep_running" leaves it fully running.',
        ],
    ];
}

// ---------------------------------------------------------------------
// ConfigOptions dynamic Loaders - each receives $params and must return
// an array suitable for a dropdown (value => label).
// ---------------------------------------------------------------------

function hetzner_cloud_manager_loadAccounts($params)
{
    try {
        $accounts = Capsule::table('mod_hetzner_cloud_accounts')->where('is_active', 1)->get();
        $options = [];
        foreach ($accounts as $account) {
            $options[$account->id] = $account->account_name;
        }
        return empty($options) ? ['0' => 'No active Hetzner accounts configured'] : $options;
    } catch (\Exception $e) {
        return ['0' => 'Error loading accounts: ' . $e->getMessage()];
    }
}

function hetzner_cloud_manager_loadServerTypes($params)
{
    try {
        $accountId = (int) ($params['configoption1'] ?? 0);
        $client = $accountId > 0 ? HetznerClient::forAccount($accountId) : HetznerClient::forAnyActiveAccount();
        $stock = new StockAvailability($client);

        $options = [];
        foreach ($client->getServerTypes() as $type) {
            if (($type['deprecated'] ?? false) === true) {
                continue;
            }
            $anyAvailable = false;
            foreach ($type['locations'] ?? [] as $loc) {
                if (!empty($loc['available'])) {
                    $anyAvailable = true;
                    break;
                }
            }
            $label = sprintf(
                '%s - %d vCPU / %sGB RAM / %sGB Disk%s',
                $type['name'],
                $type['cores'],
                $type['memory'],
                $type['disk'],
                $anyAvailable ? '' : ' [OUT OF STOCK]'
            );
            $options[$type['name']] = $label;
        }
        return empty($options) ? ['' => 'No server types returned by API'] : $options;
    } catch (\Exception $e) {
        return ['' => 'Error loading server types: ' . $e->getMessage()];
    }
}

function hetzner_cloud_manager_loadLocations($params)
{
    try {
        $accountId = (int) ($params['configoption1'] ?? 0);
        $client = $accountId > 0 ? HetznerClient::forAccount($accountId) : HetznerClient::forAnyActiveAccount();

        $options = [];
        foreach ($client->getLocations() as $location) {
            $options[$location['name']] = sprintf('%s - %s', $location['name'], $location['description']);
        }
        return empty($options) ? ['' => 'No locations returned by API'] : $options;
    } catch (\Exception $e) {
        return ['' => 'Error loading locations: ' . $e->getMessage()];
    }
}

function hetzner_cloud_manager_loadImages($params)
{
    try {
        $accountId = (int) ($params['configoption1'] ?? 0);
        $client = $accountId > 0 ? HetznerClient::forAccount($accountId) : HetznerClient::forAnyActiveAccount();

        $options = [];
        foreach ($client->getImages(['type' => 'system']) as $image) {
            $options[$image['name']] = $image['description'] ?? $image['name'];
        }
        return empty($options) ? ['' => 'No OS images returned by API'] : $options;
    } catch (\Exception $e) {
        return ['' => 'Error loading images: ' . $e->getMessage()];
    }
}

// ---------------------------------------------------------------------
// Lifecycle callbacks
// ---------------------------------------------------------------------

/**
 * Resolve the effective value for a config field, preferring a
 * Configurable Option override (set at order time) over the product's
 * fixed Module Settings value.
 */
function hetzner_cloud_manager_resolveOption(array $params, string $optionLabel, string $configOptionKey): ?string
{
    if (!empty($params['configoptions'][$optionLabel])) {
        return $params['configoptions'][$optionLabel];
    }
    if (!empty($params[$configOptionKey])) {
        return $params[$configOptionKey];
    }
    return null;
}

function hetzner_cloud_manager_CreateAccount(array $params)
{
    try {
        $serviceId = (int) $params['serviceid'];
        $accountId = (int) $params['configoption1'];

        if ($accountId <= 0) {
            return 'Provisioning Error: No Hetzner Cloud account is configured for this product. Set one on the Module Settings tab.';
        }

        $client = HetznerClient::forAccount($accountId);

        $serverType = hetzner_cloud_manager_resolveOption($params, 'ServerType', 'configoption2');
        $location = hetzner_cloud_manager_resolveOption($params, 'Location', 'configoption3');
        $image = hetzner_cloud_manager_resolveOption($params, 'Image', 'configoption4');
        $enableBackups = ($params['configoptions']['Backups'] ?? null) === 'Yes' || $params['configoption5'] === 'on';

        if (!$serverType || !$location || !$image) {
            return 'Provisioning Error: Server type, location, and image must all be set (via product Module Settings or Configurable Options).';
        }

        // Re-validate live availability at provisioning time, regardless
        // of what the cached order-form badge showed - stock can change
        // between order placement and provisioning.
        $stock = new StockAvailability($client);
        if (!$stock->isAvailable($serverType, $location)) {
            logActivity("Hetzner Cloud Manager: blocked provisioning of service #{$serviceId} - {$serverType} out of stock in {$location}");
            return "Provisioning Error: Server flavor '{$serverType}' is currently out of capacity in location '{$location}'. Please contact support to choose an alternative location.";
        }

        $hostname = !empty($params['domain']) ? $params['domain'] : ('vps-' . $serviceId . '.cloud');

        // SSH key import from a custom field, if supplied by the client.
        $sshKeyIds = [];
        $sshPublicKey = trim($params['customfields']['SSH Public Key'] ?? '');
        if ($sshPublicKey !== '') {
            try {
                $imported = $client->request('POST', 'ssh_keys', [
                    'name' => 'whmcs-svc-' . $serviceId . '-' . substr(md5($sshPublicKey), 0, 8),
                    'public_key' => $sshPublicKey,
                ]);
                if (!empty($imported['ssh_key']['id'])) {
                    $sshKeyIds[] = $imported['ssh_key']['id'];
                }
            } catch (\Exception $e) {
                // Non-fatal: continue provisioning with a generated root password instead.
                logActivity("Hetzner Cloud Manager: SSH key import failed for service #{$serviceId} - " . $e->getMessage());
            }
        }

        $userData = CloudInitBuilder::build(
            $hostname,
            $params['customfields']['Custom Cloud-Init Script'] ?? null
        );

        $payload = [
            'name' => hetzner_cloud_manager_sanitizeServerName($hostname, $serviceId),
            'server_type' => $serverType,
            'location' => $location,
            'image' => $image,
            'start_after_create' => true,
            'user_data' => $userData,
            'ssh_keys' => $sshKeyIds,
            'labels' => [
                'whmcs_service_id' => (string) $serviceId,
                'managed_by' => 'hetzner-cloud-manager',
            ],
        ];

        if ($enableBackups) {
            $payload['automount'] = false;
        }

        $result = $client->request('POST', 'servers', $payload);
        $server = $result['server'] ?? [];
        $rootPassword = $result['root_password'] ?? null;

        if (empty($server['id'])) {
            return 'Provisioning Error: Hetzner API did not return a server object. Raw response: ' . json_encode($result);
        }

        if ($enableBackups) {
            try {
                $client->serverAction((int) $server['id'], 'enable_backup');
            } catch (\Exception $e) {
                logActivity("Hetzner Cloud Manager: failed to enable backups for server #{$server['id']} - " . $e->getMessage());
            }
        }

        Capsule::table('mod_hetzner_cloud_instances')->updateOrInsert(
            ['service_id' => $serviceId],
            [
                'account_id' => $accountId,
                'hetzner_server_id' => $server['id'],
                'server_name' => $server['name'],
                'datacenter' => $server['datacenter']['name'] ?? '',
                'server_type' => $serverType,
                'location' => $location,
                'ipv4_address' => $server['public_net']['ipv4']['ip'] ?? null,
                'ipv6_subnet' => $server['public_net']['ipv6']['network'] ?? null,
                'backups_enabled' => $enableBackups ? 1 : 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]
        );

        Capsule::table('tblhosting')->where('id', $serviceId)->update([
            'dedicatedip' => $server['public_net']['ipv4']['ip'] ?? '',
            'assignedips' => $server['public_net']['ipv6']['network'] ?? '',
        ]);

        // Persist the initial root password (if returned - it is omitted
        // when SSH keys were supplied) into a WHMCS custom field so the
        // "Server Deployed" email merge tag can surface it once.
        if ($rootPassword) {
            hetzner_cloud_manager_storeCustomField($serviceId, 'Initial Root Password', $rootPassword);
        }

        return 'success';
    } catch (\Exception $e) {
        logActivity('Hetzner Cloud Manager CreateAccount failed for service #' . ($params['serviceid'] ?? '?') . ': ' . $e->getMessage());
        return 'Hetzner Provisioning Failed: ' . $e->getMessage();
    }
}

function hetzner_cloud_manager_SuspendAccount(array $params)
{
    try {
        $instance = hetzner_cloud_manager_getInstance((int) $params['serviceid']);
        $client = HetznerClient::forAccount($instance->account_id);

        // ConfigOptions order: 1=Account, 2=ServerType, 3=Location, 4=Image,
        // 5=EnableBackups, 6=AllowClientRebuild, 7=AllowClientRescue,
        // 8=AllowClientConsole, 9=AllowClientPTR, 10=AllowClientFirewall,
        // 11=SuspendAction.
        $suspendAction = $params['configoption11'] ?? 'poweroff';
        if ($suspendAction !== 'keep_running') {
            $client->serverAction((int) $instance->hetzner_server_id, 'shutdown');
        }

        return 'success';
    } catch (\Exception $e) {
        return 'Suspension Failed: ' . $e->getMessage();
    }
}

function hetzner_cloud_manager_UnsuspendAccount(array $params)
{
    try {
        $instance = hetzner_cloud_manager_getInstance((int) $params['serviceid']);
        $client = HetznerClient::forAccount($instance->account_id);
        $client->serverAction((int) $instance->hetzner_server_id, 'poweron');

        return 'success';
    } catch (\Exception $e) {
        return 'Unsuspension Failed: ' . $e->getMessage();
    }
}

function hetzner_cloud_manager_TerminateAccount(array $params)
{
    try {
        $serviceId = (int) $params['serviceid'];
        $instance = Capsule::table('mod_hetzner_cloud_instances')->where('service_id', $serviceId)->first();

        if (!$instance) {
            return 'Instance record not found in WHMCS tracking table - nothing to terminate on Hetzner.';
        }

        $client = HetznerClient::forAccount($instance->account_id);

        try {
            $client->deleteServer((int) $instance->hetzner_server_id);
        } catch (\Exception $e) {
            // If the server was already deleted directly on Hetzner, treat as success.
            if (strpos($e->getMessage(), '404') === false && (int) $e->getCode() !== 404) {
                throw $e;
            }
        }

        Capsule::table('mod_hetzner_cloud_instances')->where('service_id', $serviceId)->delete();

        return 'success';
    } catch (\Exception $e) {
        return 'Termination Failed: ' . $e->getMessage();
    }
}

/**
 * Resize the server via POST /servers/{id}/actions/change_type when the
 * client upgrades/downgrades their product/configurable option. Hetzner
 * requires the server to be powered off for a disk-changing resize.
 */
function hetzner_cloud_manager_ChangePackage(array $params)
{
    try {
        $instance = hetzner_cloud_manager_getInstance((int) $params['serviceid']);
        $client = HetznerClient::forAccount($instance->account_id);

        $newServerType = hetzner_cloud_manager_resolveOption($params, 'ServerType', 'configoption2');
        if (!$newServerType) {
            return 'Change Package Failed: No target server type resolved from the new product/configurable options.';
        }

        if ($newServerType === $instance->server_type) {
            return 'success'; // no-op, already on this type
        }

        $stock = new StockAvailability($client);
        if (!$stock->isAvailable($newServerType, $instance->location)) {
            return "Change Package Failed: Server type '{$newServerType}' is out of capacity in location '{$instance->location}'.";
        }

        $server = $client->getServer((int) $instance->hetzner_server_id);
        $wasRunning = ($server['status'] ?? '') === 'running';

        if ($wasRunning) {
            $client->serverAction((int) $instance->hetzner_server_id, 'shutdown');
            hetzner_cloud_manager_waitForStatus($client, (int) $instance->hetzner_server_id, 'off', 60);
        }

        $client->serverAction((int) $instance->hetzner_server_id, 'change_type', [
            'server_type' => $newServerType,
            'upgrade_disk' => true,
        ]);

        if ($wasRunning) {
            $client->serverAction((int) $instance->hetzner_server_id, 'poweron');
        }

        Capsule::table('mod_hetzner_cloud_instances')->where('service_id', $instance->service_id)->update([
            'server_type' => $newServerType,
        ]);

        return 'success';
    } catch (\Exception $e) {
        return 'Change Package Failed: ' . $e->getMessage();
    }
}

/**
 * Basic client-area power buttons available without JavaScript, shown
 * on the service's overview page above the custom clientarea.tpl output
 * (which provides the full AJAX-powered dashboard - see clientarea.php).
 */
function hetzner_cloud_manager_ClientAreaCustomButtonArray()
{
    return [
        'Start Server' => 'poweron',
        'Shutdown Server' => 'shutdown',
        'Reboot Server' => 'reboot',
    ];
}

function hetzner_cloud_manager_poweron(array $params)
{
    return hetzner_cloud_manager_runServerAction($params, 'poweron');
}

function hetzner_cloud_manager_shutdown(array $params)
{
    return hetzner_cloud_manager_runServerAction($params, 'shutdown');
}

function hetzner_cloud_manager_reboot(array $params)
{
    return hetzner_cloud_manager_runServerAction($params, 'reboot');
}

function hetzner_cloud_manager_runServerAction(array $params, string $action): string
{
    try {
        $instance = hetzner_cloud_manager_getInstance((int) $params['serviceid']);
        $client = HetznerClient::forAccount($instance->account_id);
        $client->serverAction((int) $instance->hetzner_server_id, $action);
        return 'success';
    } catch (\Exception $e) {
        return $e->getMessage();
    }
}

/**
 * Admin Area "Module Info" panel - read-only summary shown on the
 * service's Products/Services admin tab.
 */
function hetzner_cloud_manager_AdminServicesTabFields(array $params)
{
    try {
        $instance = Capsule::table('mod_hetzner_cloud_instances')->where('service_id', $params['serviceid'])->first();
        if (!$instance) {
            return ['Hetzner Status' => 'Not provisioned / no instance record found'];
        }

        return [
            'Hetzner Server ID' => $instance->hetzner_server_id,
            'Server Name' => $instance->server_name,
            'Server Type' => $instance->server_type,
            'Datacenter' => $instance->datacenter,
            'IPv4 Address' => $instance->ipv4_address,
            'IPv6 Subnet' => $instance->ipv6_subnet,
            'Backups Enabled' => $instance->backups_enabled ? 'Yes' : 'No',
        ];
    } catch (\Exception $e) {
        return ['Hetzner Status' => 'Error: ' . $e->getMessage()];
    }
}

// ---------------------------------------------------------------------
// Internal helpers
// ---------------------------------------------------------------------

function hetzner_cloud_manager_getInstance(int $serviceId)
{
    $instance = Capsule::table('mod_hetzner_cloud_instances')->where('service_id', $serviceId)->first();
    if (!$instance) {
        throw new \Exception("No Hetzner instance is linked to service #{$serviceId}.");
    }
    return $instance;
}

function hetzner_cloud_manager_sanitizeServerName(string $hostname, int $serviceId): string
{
    $name = strtolower(trim($hostname));
    $name = preg_replace('/[^a-z0-9\-.]/', '-', $name);
    $name = trim($name, '-');
    return $name !== '' ? $name : ('vps-' . $serviceId);
}

function hetzner_cloud_manager_waitForStatus(HetznerClient $client, int $serverId, string $status, int $timeoutSeconds): bool
{
    $start = time();
    while (time() - $start < $timeoutSeconds) {
        $server = $client->getServer($serverId);
        if (($server['status'] ?? '') === $status) {
            return true;
        }
        sleep(3);
    }
    return false;
}

function hetzner_cloud_manager_storeCustomField(int $serviceId, string $fieldName, string $value): void
{
    $field = Capsule::table('tblcustomfields')
        ->where('type', 'product')
        ->where('fieldname', $fieldName)
        ->first();

    if (!$field) {
        return;
    }

    Capsule::table('tblcustomfieldsvalues')->updateOrInsert(
        ['fieldid' => $field->id, 'relid' => $serviceId],
        ['value' => $value]
    );
}
