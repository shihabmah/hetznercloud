<?php
/**
 * Accounts tab: CRUD for Hetzner Cloud API project tokens.
 *
 * Expected variables: $accounts, $module_link
 */
?>
<div style="display:flex; gap:20px; flex-wrap:wrap;">
    <div class="hcm-card" style="flex:1; min-width:320px; max-width:420px;">
        <h4 style="margin-top:0;">Add Hetzner Project</h4>
        <form method="post" action="<?= hcm_e($module_link) ?>&tab=accounts">
            <input type="hidden" name="account_action" value="create">
            <div style="margin-bottom:10px;">
                <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">Account Name</label>
                <input type="text" name="account_name" class="form-control" placeholder="e.g. Production Project" required style="width:100%; padding:6px; border:1px solid #ccd2db; border-radius:4px;">
            </div>
            <div style="margin-bottom:10px;">
                <label style="display:block; font-size:12px; font-weight:600; margin-bottom:4px;">API Token</label>
                <input type="password" name="api_token" class="form-control" placeholder="Hetzner Cloud API token" required style="width:100%; padding:6px; border:1px solid #ccd2db; border-radius:4px;">
            </div>
            <button type="submit" class="btn btn-primary" style="background:#d50c2d; color:#fff; border:none; padding:8px 16px; border-radius:4px; cursor:pointer;">Add &amp; Test Connection</button>
        </form>
    </div>

    <div class="hcm-card" style="flex:2; min-width:400px;">
        <h4 style="margin-top:0;">Linked Projects</h4>
        <table class="hcm-table">
            <thead>
                <tr><th>Name</th><th>Token</th><th>Status</th><th>Last Test</th><th>Rate Limit</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($accounts as $account): ?>
                <tr>
                    <td><?= hcm_e($account->account_name) ?></td>
                    <td><code><?= hcm_e($account->masked_token) ?></code></td>
                    <td>
                        <?php if ($account->is_active): ?>
                            <span class="hcm-badge ok">Active</span>
                        <?php else: ?>
                            <span class="hcm-badge danger">Disabled</span>
                        <?php endif; ?>
                    </td>
                    <td style="max-width:220px;">
                        <?php if ($account->last_test_status === 'success'): ?>
                            <span class="hcm-badge ok">OK</span>
                        <?php elseif ($account->last_test_status === 'error'): ?>
                            <span class="hcm-badge danger">Failed</span>
                        <?php else: ?>
                            <span class="hcm-badge warn">Untested</span>
                        <?php endif; ?>
                        <div style="font-size:11px; color:#718096; margin-top:2px;"><?= hcm_e($account->last_test_message ?? '') ?></div>
                    </td>
                    <td><?= (int) $account->rate_limit_remaining ?> / 3600 (<?= (int) $account->rate_limit_pct ?>%)</td>
                    <td>
                        <form method="post" action="<?= hcm_e($module_link) ?>&tab=accounts" style="display:inline;">
                            <input type="hidden" name="account_action" value="test">
                            <input type="hidden" name="account_id" value="<?= (int) $account->id ?>">
                            <button type="submit" style="border:1px solid #ccd2db; background:#fff; padding:4px 8px; border-radius:4px; cursor:pointer; font-size:11px;">Test</button>
                        </form>
                        <form method="post" action="<?= hcm_e($module_link) ?>&tab=accounts" style="display:inline;">
                            <input type="hidden" name="account_action" value="toggle">
                            <input type="hidden" name="account_id" value="<?= (int) $account->id ?>">
                            <button type="submit" style="border:1px solid #ccd2db; background:#fff; padding:4px 8px; border-radius:4px; cursor:pointer; font-size:11px;"><?= $account->is_active ? 'Disable' : 'Enable' ?></button>
                        </form>
                        <form method="post" action="<?= hcm_e($module_link) ?>&tab=accounts" style="display:inline;" onsubmit="return confirm('Delete this account? Servers already provisioned under it will keep running on Hetzner but will no longer sync.');">
                            <input type="hidden" name="account_action" value="delete">
                            <input type="hidden" name="account_id" value="<?= (int) $account->id ?>">
                            <button type="submit" style="border:1px solid #f8c9c5; background:#fdeceb; color:#b42318; padding:4px 8px; border-radius:4px; cursor:pointer; font-size:11px;">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($accounts)): ?>
                <tr><td colspan="6" style="text-align:center; color:#718096;">No accounts yet. Add your first Hetzner Cloud project token above.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
