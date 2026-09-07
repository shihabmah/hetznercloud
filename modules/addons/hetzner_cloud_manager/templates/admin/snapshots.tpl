<?php
/**
 * Snapshots tab: estate snapshot inspector and lifecycle management.
 *
 * Expected variables: $snapshot_data (SnapshotsController::listAll()), $accounts, $module_link
 */
$snaps = $snapshot_data['snapshots'] ?? [];
?>
<?php if (!empty($snapshot_data['errors'])): ?>
    <div class="hcm-flash error">
        <?php foreach ($snapshot_data['errors'] as $err): ?><div><?= hcm_e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="hcm-card" style="margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center;">
        <h4 style="margin-top:0;">📷 Snapshot Estate</h4>
        <div class="hcm-metric" style="font-size:18px;"><?= number_format($snapshot_data['total_gb'] ?? 0, 1) ?> GB total</div>
    </div>
    <table class="hcm-table">
        <thead><tr><th>Account</th><th>Description</th><th>Size</th><th>Source Server</th><th>Linked Service</th><th>Created</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($snaps as $snap): ?>
            <tr>
                <td><?= hcm_e($snap['account_name']) ?></td>
                <td><?= hcm_e($snap['description']) ?></td>
                <td><?= number_format($snap['size_gb'], 1) ?> GB</td>
                <td><?= hcm_e($snap['created_from_server_name'] ?? '-') ?></td>
                <td><?= $snap['linked_service_id'] ? '#' . (int) $snap['linked_service_id'] : '-' ?></td>
                <td><?= hcm_e($snap['created'] ?? '-') ?></td>
                <td>
                    <form method="post" action="<?= hcm_e($module_link) ?>&tab=snapshots" style="display:inline;">
                        <input type="hidden" name="snapshot_action" value="publish_template">
                        <input type="hidden" name="account_id" value="<?= (int) $snap['account_id'] ?>">
                        <input type="hidden" name="snapshot_id" value="<?= (int) $snap['id'] ?>">
                        <input type="text" name="template_label" placeholder="Template label" style="width:110px; padding:3px; border:1px solid #ccd2db; border-radius:4px; font-size:11px;">
                        <button type="submit" style="border:1px solid #ccd2db; background:#fff; padding:3px 8px; border-radius:4px; cursor:pointer; font-size:11px;">Publish</button>
                    </form>
                    <form method="post" action="<?= hcm_e($module_link) ?>&tab=snapshots" style="display:inline;" onsubmit="return confirm('Delete this snapshot?');">
                        <input type="hidden" name="snapshot_action" value="delete">
                        <input type="hidden" name="account_id" value="<?= (int) $snap['account_id'] ?>">
                        <input type="hidden" name="snapshot_id" value="<?= (int) $snap['id'] ?>">
                        <button type="submit" style="border:1px solid #f8c9c5; background:#fdeceb; color:#b42318; padding:3px 8px; border-radius:4px; cursor:pointer; font-size:11px;">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($snaps)): ?>
            <tr><td colspan="7" style="text-align:center; color:#718096;">No snapshots found across active accounts.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="hcm-card">
    <h4 style="margin-top:0;">➕ Create Manual Snapshot</h4>
    <form method="post" action="<?= hcm_e($module_link) ?>&tab=snapshots" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
        <input type="hidden" name="snapshot_action" value="create">
        <div>
            <label style="display:block; font-size:11px; font-weight:600;">Account</label>
            <select name="account_id" required style="padding:6px; border:1px solid #ccd2db; border-radius:4px;">
                <?php foreach ($accounts as $account): ?>
                    <option value="<?= (int) $account->id ?>"><?= hcm_e($account->account_name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="display:block; font-size:11px; font-weight:600;">Server ID</label>
            <input type="number" name="server_id" required style="padding:6px; border:1px solid #ccd2db; border-radius:4px;">
        </div>
        <div>
            <label style="display:block; font-size:11px; font-weight:600;">Description</label>
            <input type="text" name="description" placeholder="Optional description" style="padding:6px; border:1px solid #ccd2db; border-radius:4px;">
        </div>
        <button type="submit" style="border:none; background:#d50c2d; color:#fff; padding:7px 14px; border-radius:4px; cursor:pointer; font-size:12px;">Create Snapshot</button>
    </form>
</div>
