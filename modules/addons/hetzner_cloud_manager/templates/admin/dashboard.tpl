<?php
/**
 * Dashboard tab: estate metrics, per-account rate-limit headroom,
 * out-of-stock warnings, orphan/stale counts.
 *
 * Expected variables: $metrics (see DashboardController::getMetrics)
 */
$m = $metrics;
?>
<?php if (!empty($m['errors'])): ?>
    <div class="hcm-flash error">
        <?php foreach ($m['errors'] as $err): ?>
            <div><?= hcm_e($err) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="row" style="display:flex; gap:16px; flex-wrap:wrap; margin-bottom:20px;">
    <div class="hcm-card" style="flex:1; min-width:150px;">
        <div class="hcm-metric"><?= (int) $m['servers_total'] ?></div>
        <div class="hcm-metric-label">Total Servers</div>
    </div>
    <div class="hcm-card" style="flex:1; min-width:150px;">
        <div class="hcm-metric" style="color:#14804a;"><?= (int) $m['servers_running'] ?></div>
        <div class="hcm-metric-label">Running</div>
    </div>
    <div class="hcm-card" style="flex:1; min-width:150px;">
        <div class="hcm-metric" style="color:#b42318;"><?= (int) $m['servers_off'] ?></div>
        <div class="hcm-metric-label">Stopped</div>
    </div>
    <div class="hcm-card" style="flex:1; min-width:150px;">
        <div class="hcm-metric"><?= (int) $m['volumes_gb'] ?> GB</div>
        <div class="hcm-metric-label">Block Storage</div>
    </div>
    <div class="hcm-card" style="flex:1; min-width:150px;">
        <div class="hcm-metric"><?= (int) $m['floating_ips'] ?></div>
        <div class="hcm-metric-label">Floating IPs</div>
    </div>
    <div class="hcm-card" style="flex:1; min-width:150px;">
        <div class="hcm-metric">&euro;<?= number_format($m['monthly_cost_eur'], 2) ?></div>
        <div class="hcm-metric-label">Net Monthly Cost</div>
    </div>
</div>

<div style="display:flex; gap:16px; flex-wrap:wrap;">
    <div class="hcm-card" style="flex:2; min-width:320px;">
        <h4 style="margin-top:0;">Account Health</h4>
        <table class="hcm-table">
            <thead><tr><th>Account</th><th>Servers</th><th>Rate Limit Headroom</th></tr></thead>
            <tbody>
            <?php foreach ($m['accounts'] as $acc): ?>
                <tr>
                    <td><?= hcm_e($acc['name']) ?></td>
                    <td><?= (int) $acc['server_count'] ?></td>
                    <td>
                        <?php $pct = $acc['rate_limit_pct']; $cls = $pct < 15 ? 'danger' : ($pct < 40 ? 'warn' : 'ok'); ?>
                        <span class="hcm-badge <?= $cls ?>"><?= (int) $acc['rate_limit_remaining'] ?> / 3600 (<?= $pct ?>%)</span>
                        <?php if (!empty($acc['error'])): ?>
                            <div style="color:#b42318; font-size:11px; margin-top:4px;"><?= hcm_e($acc['error']) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($m['accounts'])): ?>
                <tr><td colspan="3" style="text-align:center; color:#718096;">No active accounts. Add one on the Accounts tab.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="hcm-card" style="flex:1; min-width:260px;">
        <h4 style="margin-top:0;">Capacity Warnings</h4>
        <?php if (empty($m['out_of_stock'])): ?>
            <p style="color:#718096;">No out-of-stock server flavors detected.</p>
        <?php else: ?>
            <ul style="padding-left:18px;">
                <?php foreach ($m['out_of_stock'] as $row): ?>
                    <li><span class="hcm-badge danger">Sold out</span> <?= hcm_e($row->server_type) ?> @ <?= hcm_e($row->location) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <h4>Estate Integrity</h4>
        <p>
            <span class="hcm-badge <?= $m['orphans'] > 0 ? 'warn' : 'ok' ?>"><?= (int) $m['orphans'] ?> orphan server(s)</span><br><br>
            <span class="hcm-badge <?= $m['stale_services'] > 0 ? 'warn' : 'ok' ?>"><?= (int) $m['stale_services'] ?> stale service(s)</span>
        </p>
        <p><a href="<?= hcm_e($module_link) ?>&tab=servers">Review on the Servers tab &rarr;</a></p>
    </div>
</div>
