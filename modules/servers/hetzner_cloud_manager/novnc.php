<?php
/**
 * Hetzner Cloud Manager - noVNC Console Page
 *
 * Standalone page opened in a new browser tab by clientarea.tpl's
 * "Open Console" button. The one-time console password and wss:// URL
 * issued by Hetzner (POST /servers/{id}/actions/request_console) are
 * passed via the URL *fragment* (after '#'), which browsers never send
 * to the server in the HTTP request line, headers, or referrer - so the
 * credential never touches this script, server access logs, or
 * clientarea.php's own logging. JavaScript on this page reads
 * window.location.hash client-side and feeds it straight into the
 * noVNC RFB client.
 *
 * This module does not bundle a vendor copy of noVNC (avoiding an
 * unreviewed third-party JS blob in the repo); instead it loads the
 * official @novnc/novnc ES module bundle from the jsDelivr CDN at
 * runtime. noVNC is BSD-2-Clause licensed - see https://github.com/novnc/noVNC.
 *
 * Only an authenticated WHMCS client or admin session may load this
 * page at all; the actual console secret still only exists in the
 * browser URL fragment of the tab that opened it.
 */

$whmcsRoot = realpath(__DIR__ . '/../../..');
$initFile = $whmcsRoot . '/init.php';

if (is_file($initFile)) {
    define('WHMCS', true);
    require_once $initFile;
}

$isClient = class_exists('WHMCS\\Session') ? (bool) (\WHMCS\Session::get('uid') ?? 0) : false;
$isAdmin = class_exists('WHMCS\\Session') ? (bool) (\WHMCS\Session::get('adminid') ?? 0) : false;

if (!$isClient && !$isAdmin) {
    http_response_code(401);
    echo 'Authentication required. Please log in to your client area and reopen the console from your service page.';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
include __DIR__ . '/templates/novnc.tpl';
