<?php
/**
 * Products tab: 1-Click Server Import & Config Option builder.
 *
 * Expected variables: $accounts, $selected_account_id, $server_types,
 *                      $product_groups, $module_link
 */
?>
<div class="hcm-card" style="margin-bottom:20px;">
    <h4 style="margin-top:0;">📦 1-Click Product Creator</h4>
    <p style="color:#718096; font-size:13px;">Pick a Hetzner project below to list its live server types. Importing creates a WHMCS product wired to this module, with Location / Operating System / Backups / Extra Storage Configurable Option groups and a markup rule seeded for the Pricing Sync engine. New products are created hidden - review and unhide them from Setup &gt; Products/Services once pricing looks right.</p>

    <form method="get" action="<?= hcm_e($module_link) ?>" style="margin-bottom:16px;">
        <input type="hidden" name="tab" value="products">
        <label style="font-size:12px; font-weight:600; margin-right:8px;">Hetzner Project:</label>
        <select name="account_id" onchange="this.form.submit()" style="padding:6px; border:1px solid #ccd2db; border-radius:4px;">
            <?php foreach ($accounts as $account): ?>
                <option value="<?= (int) $account->id ?>" <?= $account->id == $selected_account_id ? 'selected' : '' ?>><?= hcm_e($account->account_name) ?></option>
            <?php endforeach; ?>
        </select>
    </form>

    <?php if (empty($accounts)): ?>
        <p style="color:#b42318;">No active Hetzner accounts configured. Add one on the Accounts tab first.</p>
    <?php elseif (empty($server_types)): ?>
        <p style="color:#718096;">No server types returned by the API for this account.</p>
    <?php else: ?>
    <table class="hcm-table">
        <thead><tr><th>Server Type</th><th>Specs</th><th>Base Cost (EUR/mo)</th><th>Stock</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($server_types as $type): ?>
            <tr>
                <td><strong><?= hcm_e($type['name']) ?></strong><br><small style="color:#718096;"><?= hcm_e($type['description']) ?></small></td>
                <td><?= (int) $type['cores'] ?> vCPU / <?= hcm_e($type['memory']) ?>GB RAM / <?= hcm_e($type['disk']) ?>GB Disk</td>
                <td>&euro;<?= number_format((float) $type['price_monthly_eur'], 2) ?></td>
                <td><span class="hcm-badge <?= $type['available'] ? 'ok' : 'danger' ?>"><?= $type['available'] ? 'In Stock' : 'Sold Out' ?></span></td>
                <td>
                    <button type="button" style="border:none; background:#d50c2d; color:#fff; padding:5px 10px; border-radius:4px; cursor:pointer; font-size:11px;"
                            onclick="var f=document.getElementById('import-form-<?= hcm_e($type['name']) ?>'); f.style.display = f.style.display === 'none' ? 'table-row' : 'none';">
                        📥 Import
                    </button>
                </td>
            </tr>
            <tr id="import-form-<?= hcm_e($type['name']) ?>" style="display:none; background:#fafbfc;">
                <td colspan="5">
                    <form method="post" action="<?= hcm_e($module_link) ?>&tab=products" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; padding:8px 0;">
                        <input type="hidden" name="product_action" value="import">
                        <input type="hidden" name="account_id" value="<?= (int) $selected_account_id ?>">
                        <input type="hidden" name="server_type" value="<?= hcm_e($type['name']) ?>">
                        <div>
                            <label style="display:block; font-size:11px; font-weight:600;">Product Name</label>
                            <input type="text" name="product_name" value="Cloud <?= hcm_e(strtoupper($type['name'])) ?>" style="padding:5px; border:1px solid #ccd2db; border-radius:4px;">
                        </div>
                        <div>
                            <label style="display:block; font-size:11px; font-weight:600;">Product Group</label>
                            <select name="gid" style="padding:5px; border:1px solid #ccd2db; border-radius:4px;">
                                <?php foreach ($product_groups as $group): ?>
                                    <option value="<?= (int) $group->id ?>"><?= hcm_e($group->name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; font-size:11px; font-weight:600;">Markup %</label>
                            <input type="number" name="markup_pct" value="20" min="0" step="0.5" style="width:70px; padding:5px; border:1px solid #ccd2db; border-radius:4px;">
                        </div>
                        <button type="submit" style="border:none; background:#14804a; color:#fff; padding:6px 14px; border-radius:4px; cursor:pointer; font-size:12px;">Create Product</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
