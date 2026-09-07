<?php
/**
 * Resources tab: Firewalls, Floating IPs, Volumes, Networks, SSH Keys, ISOs.
 *
 * Expected variables: $resources (ResourcesController::listAll()), $accounts, $module_link
 */
$accountOptions = function ($accounts) {
    $html = '';
    foreach ($accounts as $account) {
        $html .= '<option value="' . (int) $account->id . '">' . hcm_e($account->account_name) . '</option>';
    }
    return $html;
};
?>
<?php if (!empty($resources['errors'])): ?>
    <div class="hcm-flash error">
        <?php foreach ($resources['errors'] as $err): ?><div><?= hcm_e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="hcm-card" style="margin-bottom:20px;">
    <h4 style="margin-top:0;">🛡️ Firewalls</h4>
    <table class="hcm-table" style="margin-bottom:12px;">
        <thead><tr><th>Account</th><th>Name</th><th>Rules</th><th>Applied To</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($resources['firewalls'] as $fw): ?>
            <tr>
                <td><?= hcm_e($fw['account_name']) ?></td>
                <td><?= hcm_e($fw['name']) ?></td>
                <td><?= count($fw['rules'] ?? []) ?> rule(s)</td>
                <td><?= count($fw['applied_to'] ?? []) ?> resource(s)</td>
                <td>
                    <form method="post" action="<?= hcm_e($module_link) ?>&tab=resources" style="display:inline;" onsubmit="return confirm('Delete this firewall?');">
                        <input type="hidden" name="resource_action" value="firewall_delete">
                        <input type="hidden" name="account_id" value="<?= (int) $fw['account_id'] ?>">
                        <input type="hidden" name="firewall_id" value="<?= (int) $fw['id'] ?>">
                        <button type="submit" style="border:1px solid #f8c9c5; background:#fdeceb; color:#b42318; padding:3px 8px; border-radius:4px; cursor:pointer; font-size:11px;">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($resources['firewalls'])): ?>
            <tr><td colspan="5" style="text-align:center; color:#718096;">No firewalls found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <form method="post" action="<?= hcm_e($module_link) ?>&tab=resources" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
        <input type="hidden" name="resource_action" value="firewall_create">
        <select name="account_id" required style="padding:6px; border:1px solid #ccd2db; border-radius:4px;"><?= $accountOptions($accounts) ?></select>
        <input type="text" name="name" placeholder="Firewall name" required style="padding:6px; border:1px solid #ccd2db; border-radius:4px;">
        <input type="text" name="ports" placeholder="Ports (e.g. 22,80,443)" value="22,80,443" style="padding:6px; border:1px solid #ccd2db; border-radius:4px;">
        <input type="text" name="source_cidr" placeholder="Source CIDR" value="0.0.0.0/0" style="padding:6px; border:1px solid #ccd2db; border-radius:4px;">
        <button type="submit" style="border:none; background:#d50c2d; color:#fff; padding:7px 14px; border-radius:4px; cursor:pointer; font-size:12px;">Create Firewall</button>
    </form>
</div>

<div class="hcm-card" style="margin-bottom:20px;">
    <h4 style="margin-top:0;">📍 Floating &amp; Primary IPs</h4>
    <table class="hcm-table" style="margin-bottom:12px;">
        <thead><tr><th>Account</th><th>IP</th><th>Type</th><th>Assigned Server</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($resources['floating_ips'] as $ip): ?>
            <tr>
                <td><?= hcm_e($ip['account_name']) ?></td>
                <td><?= hcm_e($ip['ip']) ?></td>
                <td><?= hcm_e($ip['type']) ?></td>
                <td><?= hcm_e($ip['server'] ?? '-') ?></td>
                <td>
                    <form method="post" action="<?= hcm_e($module_link) ?>&tab=resources" style="display:inline;" onsubmit="return confirm('Release this floating IP?');">
                        <input type="hidden" name="resource_action" value="floating_ip_delete">
                        <input type="hidden" name="account_id" value="<?= (int) $ip['account_id'] ?>">
                        <input type="hidden" name="floating_ip_id" value="<?= (int) $ip['id'] ?>">
                        <button type="submit" style="border:1px solid #f8c9c5; background:#fdeceb; color:#b42318; padding:3px 8px; border-radius:4px; cursor:pointer; font-size:11px;">Release</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($resources['floating_ips'])): ?>
            <tr><td colspan="5" style="text-align:center; color:#718096;">No floating IPs found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <form method="post" action="<?= hcm_e($module_link) ?>&tab=resources" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
        <input type="hidden" name="resource_action" value="floating_ip_create">
        <select name="account_id" required style="padding:6px; border:1px solid #ccd2db; border-radius:4px;"><?= $accountOptions($accounts) ?></select>
        <select name="ip_type" style="padding:6px; border:1px solid #ccd2db; border-radius:4px;">
            <option value="ipv4">IPv4</option>
            <option value="ipv6">IPv6</option>
        </select>
        <input type="text" name="home_location" placeholder="Home location (e.g. fsn1)" style="padding:6px; border:1px solid #ccd2db; border-radius:4px;">
        <button type="submit" style="border:none; background:#d50c2d; color:#fff; padding:7px 14px; border-radius:4px; cursor:pointer; font-size:12px;">Create Floating IP</button>
    </form>
</div>

<div class="hcm-card" style="margin-bottom:20px;">
    <h4 style="margin-top:0;">💳 Block Storage Volumes</h4>
    <table class="hcm-table" style="margin-bottom:12px;">
        <thead><tr><th>Account</th><th>Name</th><th>Size</th><th>Attached To</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($resources['volumes'] as $vol): ?>
            <tr>
                <td><?= hcm_e($vol['account_name']) ?></td>
                <td><?= hcm_e($vol['name']) ?></td>
                <td><?= (int) $vol['size'] ?> GB</td>
                <td><?= $vol['server'] ? '#' . (int) $vol['server'] : '-' ?></td>
                <td>
                    <form method="post" action="<?= hcm_e($module_link) ?>&tab=resources" style="display:inline;" onsubmit="return confirm('Delete this volume? Data will be lost.');">
                        <input type="hidden" name="resource_action" value="volume_delete">
                        <input type="hidden" name="account_id" value="<?= (int) $vol['account_id'] ?>">
                        <input type="hidden" name="volume_id" value="<?= (int) $vol['id'] ?>">
                        <button type="submit" style="border:1px solid #f8c9c5; background:#fdeceb; color:#b42318; padding:3px 8px; border-radius:4px; cursor:pointer; font-size:11px;">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($resources['volumes'])): ?>
            <tr><td colspan="5" style="text-align:center; color:#718096;">No volumes found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <form method="post" action="<?= hcm_e($module_link) ?>&tab=resources" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
        <input type="hidden" name="resource_action" value="volume_create">
        <select name="account_id" required style="padding:6px; border:1px solid #ccd2db; border-radius:4px;"><?= $accountOptions($accounts) ?></select>
        <input type="text" name="name" placeholder="Volume name" style="padding:6px; border:1px solid #ccd2db; border-radius:4px;">
        <input type="number" name="size" placeholder="Size GB" min="10" value="50" style="width:90px; padding:6px; border:1px solid #ccd2db; border-radius:4px;">
        <select name="format" style="padding:6px; border:1px solid #ccd2db; border-radius:4px;">
            <option value="ext4">ext4</option>
            <option value="xfs">xfs</option>
        </select>
        <input type="text" name="location" placeholder="Location (if not attaching)" style="padding:6px; border:1px solid #ccd2db; border-radius:4px;">
        <input type="number" name="server_id" placeholder="Server ID (optional)" style="width:120px; padding:6px; border:1px solid #ccd2db; border-radius:4px;">
        <button type="submit" style="border:none; background:#d50c2d; color:#fff; padding:7px 14px; border-radius:4px; cursor:pointer; font-size:12px;">Create Volume</button>
    </form>
</div>

<div class="hcm-card" style="margin-bottom:20px;">
    <h4 style="margin-top:0;">🔀 Private Networks</h4>
    <table class="hcm-table" style="margin-bottom:12px;">
        <thead><tr><th>Account</th><th>Name</th><th>IP Range</th><th>Servers</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($resources['networks'] as $net): ?>
            <tr>
                <td><?= hcm_e($net['account_name']) ?></td>
                <td><?= hcm_e($net['name']) ?></td>
                <td><?= hcm_e($net['ip_range']) ?></td>
                <td><?= count($net['servers'] ?? []) ?></td>
                <td>
                    <form method="post" action="<?= hcm_e($module_link) ?>&tab=resources" style="display:inline;" onsubmit="return confirm('Delete this network?');">
                        <input type="hidden" name="resource_action" value="network_delete">
                        <input type="hidden" name="account_id" value="<?= (int) $net['account_id'] ?>">
                        <input type="hidden" name="network_id" value="<?= (int) $net['id'] ?>">
                        <button type="submit" style="border:1px solid #f8c9c5; background:#fdeceb; color:#b42318; padding:3px 8px; border-radius:4px; cursor:pointer; font-size:11px;">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($resources['networks'])): ?>
            <tr><td colspan="5" style="text-align:center; color:#718096;">No private networks found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <form method="post" action="<?= hcm_e($module_link) ?>&tab=resources" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
        <input type="hidden" name="resource_action" value="network_create">
        <select name="account_id" required style="padding:6px; border:1px solid #ccd2db; border-radius:4px;"><?= $accountOptions($accounts) ?></select>
        <input type="text" name="name" placeholder="Network name" required style="padding:6px; border:1px solid #ccd2db; border-radius:4px;">
        <input type="text" name="ip_range" placeholder="10.0.0.0/16" value="10.0.0.0/16" style="padding:6px; border:1px solid #ccd2db; border-radius:4px;">
        <button type="submit" style="border:none; background:#d50c2d; color:#fff; padding:7px 14px; border-radius:4px; cursor:pointer; font-size:12px;">Create Network</button>
    </form>
</div>

<div class="hcm-card">
    <h4 style="margin-top:0;">🔑 SSH Keys</h4>
    <table class="hcm-table" style="margin-bottom:12px;">
        <thead><tr><th>Account</th><th>Name</th><th>Fingerprint</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($resources['ssh_keys'] as $key): ?>
            <tr>
                <td><?= hcm_e($key['account_name']) ?></td>
                <td><?= hcm_e($key['name']) ?></td>
                <td><code><?= hcm_e($key['fingerprint']) ?></code></td>
                <td>
                    <form method="post" action="<?= hcm_e($module_link) ?>&tab=resources" style="display:inline;" onsubmit="return confirm('Delete this SSH key?');">
                        <input type="hidden" name="resource_action" value="ssh_key_delete">
                        <input type="hidden" name="account_id" value="<?= (int) $key['account_id'] ?>">
                        <input type="hidden" name="ssh_key_id" value="<?= (int) $key['id'] ?>">
                        <button type="submit" style="border:1px solid #f8c9c5; background:#fdeceb; color:#b42318; padding:3px 8px; border-radius:4px; cursor:pointer; font-size:11px;">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($resources['ssh_keys'])): ?>
            <tr><td colspan="4" style="text-align:center; color:#718096;">No SSH keys found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <form method="post" action="<?= hcm_e($module_link) ?>&tab=resources" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
        <input type="hidden" name="resource_action" value="ssh_key_create">
        <select name="account_id" required style="padding:6px; border:1px solid #ccd2db; border-radius:4px;"><?= $accountOptions($accounts) ?></select>
        <input type="text" name="name" placeholder="Key name" required style="padding:6px; border:1px solid #ccd2db; border-radius:4px;">
        <input type="text" name="public_key" placeholder="ssh-ed25519 AAAA... " required style="flex:1; min-width:280px; padding:6px; border:1px solid #ccd2db; border-radius:4px;">
        <button type="submit" style="border:none; background:#d50c2d; color:#fff; padding:7px 14px; border-radius:4px; cursor:pointer; font-size:12px;">Add Key</button>
    </form>
</div>
