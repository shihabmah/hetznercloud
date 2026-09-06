<?php
/**
 * Servers tab: live estate list, orphan audit, stale service detection.
 *
 * Expected variables: $orphans, $stale, $module_link
 */
?>
<h4 style="margin-top:0;">🔍 Orphan Audit</h4>
<p style="color:#718096; font-size:13px;">Servers found on Hetzner with no corresponding WHMCS service. Adopt them into an existing service to avoid unbilled provider costs.</p>
<table class="hcm-table" style="margin-bottom:24px;">
    <thead><tr><th>Account</th><th>Server</th><th>Type</th><th>Location</th><th>Status</th><th>IPv4</th><th>Created</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($orphans as $o): ?>
        <tr>
            <td><?= hcm_e($o['account_name']) ?></td>
            <td><?= hcm_e($o['server_name']) ?> <small>(#<?= (int) $o['server_id'] ?>)</small></td>
            <td><?= hcm_e($o['server_type']) ?></td>
            <td><?= hcm_e($o['datacenter']) ?></td>
            <td><span class="hcm-badge <?= $o['status'] === 'running' ? 'ok' : 'warn' ?>"><?= hcm_e($o['status']) ?></span></td>
            <td><?= hcm_e($o['ipv4'] ?? '-') ?></td>
            <td><?= hcm_e($o['created'] ?? '-') ?></td>
            <td>
                <form method="post" action="<?= hcm_e($module_link) ?>&tab=servers" style="display:flex; gap:4px;">
                    <input type="hidden" name="server_action" value="adopt">
                    <input type="hidden" name="account_id" value="<?= (int) $o['account_id'] ?>">
                    <input type="hidden" name="hetzner_server_id" value="<?= (int) $o['server_id'] ?>">
                    <input type="number" name="service_id" placeholder="Service ID" required style="width:90px; padding:4px; border:1px solid #ccd2db; border-radius:4px;">
                    <button type="submit" style="border:none; background:#d50c2d; color:#fff; padding:4px 10px; border-radius:4px; cursor:pointer; font-size:11px;">🔗 Assign</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($orphans)): ?>
        <tr><td colspan="8" style="text-align:center; color:#718096;">No orphan servers found. Estate is clean.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<h4>⚠️ Stale Services</h4>
<p style="color:#718096; font-size:13px;">WHMCS services still linked to a Hetzner server that was deleted outside of WHMCS.</p>
<table class="hcm-table">
    <thead><tr><th>Service ID</th><th>Domain</th><th>Status</th><th>Last Known Server</th></tr></thead>
    <tbody>
    <?php foreach ($stale as $s): ?>
        <tr>
            <td><?= (int) $s['service_id'] ?></td>
            <td><?= hcm_e($s['domain'] ?? '-') ?></td>
            <td><?= hcm_e($s['status']) ?></td>
            <td><?= hcm_e($s['server_name']) ?> (#<?= (int) $s['hetzner_server_id'] ?>)</td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($stale)): ?>
        <tr><td colspan="4" style="text-align:center; color:#718096;">No stale services detected.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
