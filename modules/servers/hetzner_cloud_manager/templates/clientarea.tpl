{* Hetzner Cloud Manager - Client Area Dashboard
   AJAX-powered (Bootstrap 5 compatible markup, no framework hard dependency)
   All state-changing actions call modules/servers/hetzner_cloud_manager/clientarea.php
   directly via fetch(), passing the CSRF token issued by ClientArea(). *}

{if $error_message}
<div class="alert alert-danger">Hetzner Cloud Manager: {$error_message}</div>
{elseif !$has_instance}
<div class="alert alert-warning">This service has not been provisioned yet, or is not linked to a Hetzner server.</div>
{else}

<div id="hcm-app" class="hcm-client-app" data-serviceid="{$serviceid}" data-csrf="{$csrf_token}" data-ajax-url="{$ajax_url}">
    <style>
        .hcm-client-app { font-family: -apple-system, "Segoe UI", Roboto, sans-serif; }
        .hcm-panel { border: 1px solid #e2e5ec; border-radius: 8px; padding: 18px; margin-bottom: 16px; background: #fff; }
        .hcm-panel h5 { margin-top: 0; font-weight: 700; }
        .hcm-status-dot { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:6px; }
        .hcm-status-dot.running { background:#22c55e; }
        .hcm-status-dot.off { background:#ef4444; }
        .hcm-status-dot.other { background:#f59e0b; }
        .hcm-btn { border:none; padding:8px 16px; border-radius:6px; cursor:pointer; font-size:13px; font-weight:600; margin-right:8px; margin-bottom:8px; }
        .hcm-btn-primary { background:#d50c2d; color:#fff; }
        .hcm-btn-secondary { background:#f1f3f6; color:#1a202c; }
        .hcm-btn-danger { background:#fdeceb; color:#b42318; }
        .hcm-ip-copy { cursor:pointer; border-bottom:1px dashed #718096; }
        .hcm-tabs { display:flex; gap:4px; border-bottom:1px solid #e2e5ec; margin-bottom:16px; flex-wrap: wrap; }
        .hcm-tab { padding:8px 14px; cursor:pointer; font-size:13px; font-weight:600; color:#718096; border-bottom:2px solid transparent; }
        .hcm-tab.active { color:#d50c2d; border-bottom-color:#d50c2d; }
        .hcm-tabpanel { display:none; }
        .hcm-tabpanel.active { display:block; }
        .hcm-log { font-size:12px; color:#718096; margin-top:8px; }
        .hcm-modal-backdrop { position:fixed; inset:0; background:rgba(0,0,0,.5); display:none; align-items:center; justify-content:center; z-index:1000; }
        .hcm-modal-backdrop.open { display:flex; }
        .hcm-modal { background:#fff; border-radius:8px; padding:20px; max-width:640px; width:90%; max-height:85vh; overflow:auto; }
        .hcm-console-frame { width:100%; height:520px; border:1px solid #333; background:#000; }
    </style>

    <div class="hcm-panel">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap;">
            <div>
                <h5><span class="hcm-status-dot other" id="hcm-status-dot"></span><span id="hcm-server-name">{$server_name}</span></h5>
                <div style="font-size:13px; color:#718096;">
                    {$server_type} &middot; {$datacenter}<br>
                    IPv4: <span class="hcm-ip-copy" id="hcm-ipv4" onclick="hcmCopy(this.textContent)">{$ipv4}</span>
                    {if $ipv6} &middot; IPv6: <span class="hcm-ip-copy" id="hcm-ipv6" onclick="hcmCopy(this.textContent)">{$ipv6}</span>{/if}
                </div>
            </div>
            <div id="hcm-status-badge" style="font-size:12px; font-weight:700; text-transform:uppercase; color:#718096;">Checking status...</div>
        </div>

        <div style="margin-top:16px;">
            <button class="hcm-btn hcm-btn-primary" onclick="hcmAction('poweron')">▶ Start</button>
            <button class="hcm-btn hcm-btn-secondary" onclick="hcmAction('shutdown')">⏻ Shutdown</button>
            <button class="hcm-btn hcm-btn-secondary" onclick="hcmAction('reboot')">↻ Reboot</button>
            <button class="hcm-btn hcm-btn-danger" onclick="if(confirm('Hard reset the server? Unsaved data may be lost.')) hcmAction('reset')">⚠ Hard Reset</button>
            {if $allow_console}<button class="hcm-btn hcm-btn-secondary" onclick="hcmOpenConsole()">🖥 Console</button>{/if}
        </div>
        <div class="hcm-log" id="hcm-log"></div>
    </div>

    <div class="hcm-tabs">
        <div class="hcm-tab active" data-tab="overview">Overview</div>
        {if $allow_rescue}<div class="hcm-tab" data-tab="rescue">Rescue</div>{/if}
        {if $allow_rebuild}<div class="hcm-tab" data-tab="rebuild">Reinstall OS</div>{/if}
        {if $allow_ptr}<div class="hcm-tab" data-tab="ptr">Reverse DNS</div>{/if}
        {if $allow_firewall}<div class="hcm-tab" data-tab="firewall">Firewall</div>{/if}
        <div class="hcm-tab" data-tab="volumes">Volumes</div>
        <div class="hcm-tab" data-tab="snapshots">Snapshots &amp; Backups</div>
        <div class="hcm-tab" data-tab="traffic">Traffic</div>
    </div>

    <div class="hcm-tabpanel active" data-tabpanel="overview">
        <div class="hcm-panel">
            <h5>Server Overview</h5>
            <p style="color:#718096; font-size:13px;">Use the action bar above to control power state. The IP addresses shown are copyable - click to copy to clipboard.</p>
        </div>
    </div>

    {if $allow_rescue}
    <div class="hcm-tabpanel" data-tabpanel="rescue">
        <div class="hcm-panel">
            <h5>Rescue Mode</h5>
            <p style="color:#718096; font-size:13px;">Boots the server into a minimal recovery Linux environment with a temporary root password, without touching the installed OS. You must reboot the server to enter rescue mode after enabling it.</p>
            <button class="hcm-btn hcm-btn-primary" onclick="hcmEnableRescue()">Enable Rescue Mode</button>
            <button class="hcm-btn hcm-btn-secondary" onclick="hcmAction('disable_rescue')">Disable Rescue Mode</button>
            <div id="hcm-rescue-result" class="hcm-log"></div>
        </div>
    </div>
    {/if}

    {if $allow_rebuild}
    <div class="hcm-tabpanel" data-tabpanel="rebuild">
        <div class="hcm-panel">
            <h5>Reinstall Operating System</h5>
            <p style="color:#b42318; font-size:13px; font-weight:600;">⚠ This permanently erases all data on the server.</p>
            <select id="hcm-rebuild-image" style="padding:8px; border:1px solid #ccd2db; border-radius:6px; min-width:260px;"><option>Loading images...</option></select>
            <button class="hcm-btn hcm-btn-danger" style="margin-left:8px;" onclick="hcmRebuild()">Reinstall</button>
            <div id="hcm-rebuild-result" class="hcm-log"></div>
        </div>
    </div>
    {/if}

    {if $allow_ptr}
    <div class="hcm-tabpanel" data-tabpanel="ptr">
        <div class="hcm-panel">
            <h5>Reverse DNS (PTR)</h5>
            <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end;">
                <div>
                    <label style="display:block; font-size:12px; font-weight:600;">IP Address</label>
                    <input type="text" id="hcm-ptr-ip" value="{$ipv4}" style="padding:8px; border:1px solid #ccd2db; border-radius:6px;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:600;">PTR Hostname</label>
                    <input type="text" id="hcm-ptr-hostname" placeholder="server.example.com" style="padding:8px; border:1px solid #ccd2db; border-radius:6px;">
                </div>
                <button class="hcm-btn hcm-btn-primary" onclick="hcmUpdatePtr()">Update PTR</button>
            </div>
            <div id="hcm-ptr-result" class="hcm-log"></div>
        </div>
    </div>
    {/if}

    {if $allow_firewall}
    <div class="hcm-tabpanel" data-tabpanel="firewall">
        <div class="hcm-panel">
            <h5>Firewalls</h5>
            <div id="hcm-firewall-list">Loading...</div>
        </div>
    </div>
    {/if}

    <div class="hcm-tabpanel" data-tabpanel="volumes">
        <div class="hcm-panel">
            <h5>Block Storage Volumes</h5>
            <div id="hcm-volumes-list">Loading...</div>
        </div>
    </div>

    <div class="hcm-tabpanel" data-tabpanel="snapshots">
        <div class="hcm-panel">
            <h5>Snapshots &amp; Backups</h5>
            <div style="margin-bottom:12px;">
                <label style="font-size:13px;"><input type="checkbox" id="hcm-backups-toggle" onchange="hcmToggleBackups(this.checked)"> Automated daily backups</label>
            </div>
            <div style="display:flex; gap:8px; margin-bottom:12px;">
                <input type="text" id="hcm-snapshot-desc" placeholder="Optional description" style="flex:1; padding:8px; border:1px solid #ccd2db; border-radius:6px;">
                <button class="hcm-btn hcm-btn-primary" onclick="hcmCreateSnapshot()">Create Snapshot</button>
            </div>
            <div id="hcm-snapshots-list">Loading...</div>
        </div>
    </div>

    <div class="hcm-tabpanel" data-tabpanel="traffic">
        <div class="hcm-panel">
            <h5>Traffic &amp; Bandwidth</h5>
            <div id="hcm-traffic-summary">Loading...</div>
        </div>
    </div>
</div>

{* noVNC console modal *}
<div class="hcm-modal-backdrop" id="hcm-console-modal">
    <div class="hcm-modal">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
            <h5 style="margin:0;">Web Console</h5>
            <button class="hcm-btn hcm-btn-secondary" onclick="hcmCloseConsole()">Close</button>
        </div>
        <p style="font-size:12px; color:#718096;">A one-time console password was generated below. The console opens in a new secure browser tab using Hetzner's noVNC endpoint.</p>
        <div id="hcm-console-password" style="font-family:monospace; background:#f7f8fa; padding:8px; border-radius:6px; margin-bottom:10px;"></div>
        <a id="hcm-console-link" href="#" target="_blank" rel="noopener" class="hcm-btn hcm-btn-primary">Open Console in New Tab</a>
    </div>
</div>

<script>
(function () {
    var app = document.getElementById('hcm-app');
    var serviceId = app.dataset.serviceid;
    var csrfToken = app.dataset.csrf;
    var ajaxUrl = app.dataset.ajaxUrl;

    function hcmLog(msg) {
        document.getElementById('hcm-log').textContent = msg;
    }

    window.hcmCopy = function (text) {
        navigator.clipboard && navigator.clipboard.writeText(text);
        hcmLog('Copied to clipboard: ' + text);
    };

    function hcmRequest(action, extraParams, method) {
        method = method || 'POST';
        var body = new URLSearchParams(Object.assign({
            action: action,
            serviceid: serviceId,
            csrf_token: csrfToken
        }, extraParams || {}));

        var opts = { method: method, headers: { 'X-Requested-With': 'XMLHttpRequest' } };
        var url = ajaxUrl;

        if (method === 'GET') {
            url += '?' + body.toString();
        } else {
            opts.body = body;
        }

        return fetch(url, opts).then(function (r) { return r.json(); });
    }

    window.hcmAction = function (action) {
        hcmLog('Sending ' + action + '...');
        hcmRequest(action).then(function (res) {
            hcmLog(res.message || res.status);
            if (res.status === 'success') setTimeout(hcmRefreshStatus, 2000);
        }).catch(function (e) { hcmLog('Error: ' + e); });
    };

    function hcmRefreshStatus() {
        hcmRequest('status', {}, 'GET').then(function (res) {
            if (res.status !== 'success') return;
            var dot = document.getElementById('hcm-status-dot');
            var badge = document.getElementById('hcm-status-badge');
            var cls = res.server.status === 'running' ? 'running' : (res.server.status === 'off' ? 'off' : 'other');
            dot.className = 'hcm-status-dot ' + cls;
            badge.textContent = res.server.status.toUpperCase();
        });
    }

    // --- Tabs ---
    document.querySelectorAll('.hcm-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.hcm-tab').forEach(function (t) { t.classList.remove('active'); });
            document.querySelectorAll('.hcm-tabpanel').forEach(function (p) { p.classList.remove('active'); });
            tab.classList.add('active');
            document.querySelector('.hcm-tabpanel[data-tabpanel="' + tab.dataset.tab + '"]').classList.add('active');
            hcmLoadTabData(tab.dataset.tab);
        });
    });

    function hcmLoadTabData(tab) {
        if (tab === 'rebuild') {
            hcmRequest('images', {}, 'GET').then(function (res) {
                var select = document.getElementById('hcm-rebuild-image');
                if (!select) return;
                select.innerHTML = '';
                (res.images || []).forEach(function (img) {
                    var opt = document.createElement('option');
                    opt.value = img.name;
                    opt.textContent = img.description || img.name;
                    select.appendChild(opt);
                });
            });
        } else if (tab === 'firewall') {
            hcmRequest('firewalls_list', {}, 'GET').then(function (res) {
                var el = document.getElementById('hcm-firewall-list');
                if (!el) return;
                if (res.status !== 'success') { el.textContent = res.message; return; }
                el.innerHTML = (res.available || []).map(function (fw) {
                    var applied = (res.applied || []).some(function (a) { return a.id === fw.id; });
                    return '<div style="padding:6px 0; border-bottom:1px solid #eef0f3;">' + fw.name +
                        ' <button class="hcm-btn ' + (applied ? 'hcm-btn-danger' : 'hcm-btn-primary') + '" style="padding:4px 10px; font-size:11px;" onclick="hcmToggleFirewall(' + fw.id + ',' + (!applied) + ')">' +
                        (applied ? 'Remove' : 'Apply') + '</button></div>';
                }).join('') || '<p style="color:#718096;">No firewalls available in this project.</p>';
            });
        } else if (tab === 'volumes') {
            hcmRequest('volumes_list', {}, 'GET').then(function (res) {
                var el = document.getElementById('hcm-volumes-list');
                if (!el) return;
                el.innerHTML = (res.volumes || []).map(function (v) {
                    return '<div style="padding:6px 0; border-bottom:1px solid #eef0f3;">' + v.name + ' - ' + v.size + ' GB</div>';
                }).join('') || '<p style="color:#718096;">No volumes attached.</p>';
            });
        } else if (tab === 'snapshots') {
            hcmRequest('snapshots_list', {}, 'GET').then(function (res) {
                var el = document.getElementById('hcm-snapshots-list');
                if (!el) return;
                el.innerHTML = '<p style="font-size:12px; color:#718096;">' + (res.snapshots || []).length + ' / ' + res.limit + ' snapshot(s) used.</p>' +
                    (res.snapshots || []).map(function (s) {
                        return '<div style="padding:6px 0; border-bottom:1px solid #eef0f3;">' + (s.description || ('#' + s.id)) +
                            ' <button class="hcm-btn hcm-btn-secondary" style="padding:4px 10px; font-size:11px;" onclick="hcmRestoreSnapshot(' + s.id + ')">Restore</button>' +
                            ' <button class="hcm-btn hcm-btn-danger" style="padding:4px 10px; font-size:11px;" onclick="hcmDeleteSnapshot(' + s.id + ')">Delete</button></div>';
                    }).join('');
            });
        } else if (tab === 'traffic') {
            hcmRequest('traffic', {}, 'GET').then(function (res) {
                var el = document.getElementById('hcm-traffic-summary');
                if (!el) return;
                el.innerHTML = res.status === 'success'
                    ? '<p style="font-size:12px; color:#718096;">7-day network metrics loaded from Hetzner. See browser console for raw series.</p>'
                    : '<p style="color:#b42318;">' + res.message + '</p>';
                console.log('Hetzner Cloud Manager traffic metrics', res.metrics);
            });
        }
    }

    // --- Rescue ---
    window.hcmEnableRescue = function () {
        hcmRequest('enable_rescue').then(function (res) {
            document.getElementById('hcm-rescue-result').textContent = res.status === 'success'
                ? 'Rescue mode enabled. Temporary root password: ' + res.rescue_password + ' - reboot the server to enter rescue mode.'
                : res.message;
        });
    };

    // --- Rebuild ---
    window.hcmRebuild = function () {
        var image = document.getElementById('hcm-rebuild-image').value;
        if (!confirm('This will erase ALL data on the server and reinstall ' + image + '. Continue?')) return;
        hcmRequest('rebuild', { image: image }).then(function (res) {
            document.getElementById('hcm-rebuild-result').textContent = res.status === 'success'
                ? res.message + (res.root_password ? ' New root password: ' + res.root_password : '')
                : res.message;
        });
    };

    // --- PTR ---
    window.hcmUpdatePtr = function () {
        var ip = document.getElementById('hcm-ptr-ip').value;
        var ptr = document.getElementById('hcm-ptr-hostname').value;
        hcmRequest('update_ptr', { ip: ip, ptr: ptr }).then(function (res) {
            document.getElementById('hcm-ptr-result').textContent = res.message;
        });
    };

    // --- Firewall ---
    window.hcmToggleFirewall = function (firewallId, apply) {
        hcmRequest('firewall_toggle', { firewall_id: firewallId, apply: apply ? '1' : '0' }).then(function () {
            hcmLoadTabData('firewall');
        });
    };

    // --- Snapshots ---
    window.hcmCreateSnapshot = function () {
        var description = document.getElementById('hcm-snapshot-desc').value;
        hcmRequest('snapshot_create', { description: description }).then(function (res) {
            hcmLog(res.message);
            hcmLoadTabData('snapshots');
        });
    };
    window.hcmDeleteSnapshot = function (id) {
        if (!confirm('Delete this snapshot?')) return;
        hcmRequest('snapshot_delete', { snapshot_id: id }).then(function () { hcmLoadTabData('snapshots'); });
    };
    window.hcmRestoreSnapshot = function (id) {
        if (!confirm('Restore this snapshot? The server\'s current disk contents will be replaced.')) return;
        hcmRequest('snapshot_restore', { snapshot_id: id }).then(function (res) { hcmLog(res.message); });
    };
    window.hcmToggleBackups = function (enable) {
        hcmRequest('backups_toggle', { enable: enable ? '1' : '0' }).then(function (res) { hcmLog(res.message); });
    };

    // --- Console ---
    // The wss:// URL and one-time password are placed in the URL *fragment*
    // (after '#') of the noVNC page link. Browsers never transmit the
    // fragment to the server, so the console secret stays client-side only.
    window.hcmOpenConsole = function () {
        hcmRequest('console').then(function (res) {
            if (res.status !== 'success') { hcmLog(res.message); return; }
            document.getElementById('hcm-console-password').textContent = 'Password: ' + res.password;
            var novncBase = ajaxUrl.replace('clientarea.php', 'novnc.php');
            var fragment = 'wss=' + encodeURIComponent(res.wss_url) + '&password=' + encodeURIComponent(res.password);
            document.getElementById('hcm-console-link').href = novncBase + '#' + fragment;
            document.getElementById('hcm-console-modal').classList.add('open');
        });
    };
    window.hcmCloseConsole = function () {
        document.getElementById('hcm-console-modal').classList.remove('open');
    };

    hcmRefreshStatus();
})();
</script>

{/if}
