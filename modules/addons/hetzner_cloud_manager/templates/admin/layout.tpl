<?php
/**
 * Shared admin layout shell: branding header, tab navigation, flash
 * message banner, and the active tab's rendered content.
 *
 * Expected variables: $module_link, $active_tab, $content, $flash
 */
$tabs = [
    'dashboard' => ['label' => 'Dashboard', 'icon' => 'fa-tachometer'],
    'accounts'  => ['label' => 'Accounts', 'icon' => 'fa-key'],
    'servers'   => ['label' => 'Servers', 'icon' => 'fa-server'],
    'products'  => ['label' => 'Products', 'icon' => 'fa-cubes'],
    'resources' => ['label' => 'Resources', 'icon' => 'fa-sitemap'],
    'snapshots' => ['label' => 'Snapshots', 'icon' => 'fa-camera'],
];
?>
<div class="hcm-wrap">
    <style>
        .hcm-wrap { font-family: -apple-system, "Segoe UI", Roboto, sans-serif; }
        .hcm-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; background: linear-gradient(135deg,#d50c2d 0%,#8f0a20 100%); color: #fff; border-radius: 6px 6px 0 0; }
        .hcm-header h2 { margin: 0; font-size: 20px; font-weight: 600; }
        .hcm-header small { opacity: .85; }
        .hcm-tabs { display: flex; gap: 4px; background: #fff; border-bottom: 1px solid #e2e5ec; padding: 0 12px; }
        .hcm-tabs a { padding: 12px 16px; color: #4a5568; text-decoration: none; font-size: 13px; font-weight: 600; border-bottom: 3px solid transparent; }
        .hcm-tabs a.active { color: #d50c2d; border-bottom-color: #d50c2d; }
        .hcm-tabs a:hover { color: #d50c2d; }
        .hcm-body { background: #fff; border: 1px solid #e2e5ec; border-top: none; border-radius: 0 0 6px 6px; padding: 20px; }
        .hcm-flash { padding: 10px 14px; border-radius: 4px; margin-bottom: 16px; font-size: 13px; }
        .hcm-flash.success { background: #e6f7ee; color: #14804a; border: 1px solid #b7ebce; }
        .hcm-flash.error { background: #fdeceb; color: #b42318; border: 1px solid #f8c9c5; }
        .hcm-card { border: 1px solid #e2e5ec; border-radius: 6px; padding: 16px; background: #fafbfc; }
        .hcm-metric { font-size: 26px; font-weight: 700; color: #1a202c; }
        .hcm-metric-label { font-size: 12px; color: #718096; text-transform: uppercase; letter-spacing: .04em; }
        table.hcm-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.hcm-table th { text-align: left; background: #f7f8fa; padding: 8px 10px; border-bottom: 2px solid #e2e5ec; font-size: 11px; text-transform: uppercase; color: #718096; }
        table.hcm-table td { padding: 8px 10px; border-bottom: 1px solid #eef0f3; }
        .hcm-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .hcm-badge.ok { background: #e6f7ee; color: #14804a; }
        .hcm-badge.warn { background: #fff6e6; color: #b7791f; }
        .hcm-badge.danger { background: #fdeceb; color: #b42318; }
    </style>

    <div class="hcm-header">
        <div>
            <h2>Hetzner Cloud Manager</h2>
            <small>Dynamic provisioning &middot; live stock &middot; automated pricing sync</small>
        </div>
        <small>v<?= hcm_e($module_version ?? '1.0.0') ?></small>
    </div>

    <div class="hcm-tabs">
        <?php foreach ($tabs as $key => $tabInfo): ?>
            <a href="<?= hcm_e($module_link) ?>&tab=<?= $key ?>" class="<?= $active_tab === $key ? 'active' : '' ?>">
                <i class="fa <?= hcm_e($tabInfo['icon']) ?>"></i> <?= hcm_e($tabInfo['label']) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="hcm-body">
        <?php if (!empty($flash)): ?>
            <div class="hcm-flash <?= $flash['status'] === 'success' ? 'success' : 'error' ?>">
                <?= hcm_e($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?= $content ?>
    </div>
</div>
