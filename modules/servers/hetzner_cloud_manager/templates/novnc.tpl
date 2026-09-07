<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Hetzner Cloud Manager - Web Console</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    html, body { margin:0; padding:0; height:100%; background:#111; font-family:-apple-system,"Segoe UI",Roboto,sans-serif; }
    #hcm-toolbar { background:#1a1a1a; color:#fff; padding:8px 14px; font-size:13px; display:flex; justify-content:space-between; align-items:center; }
    #hcm-toolbar button { background:#d50c2d; color:#fff; border:none; padding:6px 12px; border-radius:4px; cursor:pointer; font-size:12px; }
    #hcm-screen { width:100%; height:calc(100% - 42px); }
    #hcm-status { padding:40px; color:#ccc; text-align:center; }
</style>
</head>
<body>
<div id="hcm-toolbar">
    <span>Hetzner Cloud Manager &middot; Web Console</span>
    <button onclick="hcmSendCtrlAltDel()">Send Ctrl+Alt+Del</button>
</div>
<div id="hcm-screen"><div id="hcm-status">Connecting...</div></div>

<script type="module">
    // noVNC is loaded at runtime from jsDelivr's CDN build of the
    // official @novnc/novnc package (BSD-2-Clause) rather than vendored
    // into this repository, keeping the module's own source tree free of
    // large unreviewed third-party bundles. See https://github.com/novnc/noVNC
    import RFB from 'https://cdn.jsdelivr.net/npm/@novnc/novnc@1.4.0/core/rfb.js';

    var params = new URLSearchParams(window.location.hash.replace(/^#/, ''));
    var wssUrl = params.get('wss');
    var password = params.get('password');
    var statusEl = document.getElementById('hcm-status');
    var screenEl = document.getElementById('hcm-screen');

    if (!wssUrl) {
        statusEl.textContent = 'No console session was provided. Close this tab and click "Open Console" again from your service page.';
    } else {
        statusEl.textContent = 'Connecting to console...';

        try {
            var rfb = new RFB(screenEl, wssUrl, { credentials: { password: password } });

            rfb.addEventListener('connect', function () {
                statusEl.remove();
            });
            rfb.addEventListener('disconnect', function () {
                screenEl.innerHTML = '<div id="hcm-status">Console session ended. Close this tab and reopen the console from your service page for a new session.</div>';
            });
            rfb.addEventListener('credentialsrequired', function () {
                rfb.sendCredentials({ password: password });
            });

            window.hcmSendCtrlAltDel = function () {
                rfb.sendCtrlAltDel();
            };

            // Clear the credentials from the visible URL bar once connected,
            // without losing them from the already-initialized RFB session.
            history.replaceState(null, '', window.location.pathname + window.location.search);
        } catch (e) {
            statusEl.textContent = 'Failed to initialize console: ' + e.message;
        }
    }
</script>
</body>
</html>
