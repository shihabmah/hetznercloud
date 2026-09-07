# Hetzner Cloud Manager for WHMCS

A fully dynamic, 100% open-source (zero ionCube encryption) WHMCS Addon
and Server Provisioning Module for the [Hetzner Cloud API v1](https://docs.hetzner.cloud/).
Compatible with WHMCS 8.x-9.x and PHP 8.1+.

Nothing is hardcoded: server types, RAM/CPU specs, locations, datacenter
zones, OS images, live stock availability, and pricing are all fetched
dynamically from the Hetzner Cloud API on every relevant page load.

## Features

- **Live Stock & Availability Engine** - checks `GET /v1/server_types`
  `locations[].available` flags in real time and blocks checkout/provisioning
  into an out-of-stock server/location combination.
- **Multi-Project Token Pool** - link unlimited Hetzner Cloud projects,
  with per-account rate-limit headroom tracking and automatic HTTP 429
  handling (exponential backoff with jitter).
- **Dynamic Pricing Sync** - converts Hetzner's net-EUR costs into every
  active WHMCS currency using live exchange rates, applies configurable
  percentage markups per product, and writes prices directly into
  `tblpricing` across every billing cycle.
- **1-Click Product Importer** - turns any live Hetzner server type into
  a fully configured WHMCS product (provisioning module, Configurable
  Option groups for Location/OS/Backups/Extra Storage, markup rules).
- **Full Server Lifecycle** - CreateAccount, Suspend, Unsuspend, Terminate,
  and ChangePackage (resize) against the real Hetzner API.
- **Orphan & Estate Auditing** - detects Hetzner servers with no WHMCS
  service (unbilled cost) and WHMCS services whose server was deleted
  outside of WHMCS, with 1-click adoption.
- **Resource Management** - Firewalls, Floating/Primary IPs (with PTR),
  Block Storage Volumes, Private Networks, and SSH Keys, across every
  linked account.
- **Snapshot & Backup Lifecycle** - manual snapshots with per-instance
  limits, restore-in-place or to a new server, "publish as template",
  and automated daily backup toggling.
- **AJAX Client Area** - power controls, noVNC web console, rescue mode,
  OS reinstall, PTR management, firewall toggles, volumes, snapshots, and
  traffic metrics, all without full page reloads.
- **Metric-Based Overage Billing** - daily bandwidth/snapshot/volume/IP
  usage collection with automatic `AddBillableItem` charges on the next
  invoice.

## Directory Structure

```
modules/
├── addons/hetzner_cloud_manager/       # Admin addon: dashboard, accounts,
│   │                                    # products, resources, snapshots
│   ├── hetzner_cloud_manager.php       # Addon config, activation, tab dispatch
│   ├── hooks.php                       # DailyCronJob, admin widget, order guard
│   ├── cron.php                        # Optional standalone CLI cron runner
│   ├── autoload.php                    # HetznerCloudManager\ namespace autoloader
│   └── lib/
│       ├── Api/HetznerClient.php       # Guzzle wrapper, rate-limiter, token pool
│       ├── Api/StockAvailability.php   # Live + cached availability engine
│       ├── Controller/*.php            # One controller per admin tab
│       ├── Database/Schema.php         # Idempotent table auto-migration
│       ├── Helpers/CloudInitBuilder.php
│       ├── Helpers/MetricBilling.php
│       ├── Helpers/EmailTemplates.php
│       └── View/TemplateRenderer.php   # Dependency-free .tpl renderer
└── servers/hetzner_cloud_manager/      # Provisioning module + client area
    ├── hetzner_cloud_manager.php       # ConfigOptions + lifecycle callbacks
    ├── clientarea.php                  # AJAX endpoint for the client dashboard
    ├── novnc.php                       # noVNC web console page
    ├── lib/bootstrap.php               # Shares the addon's class library
    └── templates/{clientarea,novnc}.tpl
```

## Installation

1. Copy the `modules/addons/hetzner_cloud_manager/` and
   `modules/servers/hetzner_cloud_manager/` directories into your WHMCS
   installation's `modules/` directory.
2. In WHMCS admin, go to **Setup > Addon Modules**, find
   **Hetzner Cloud Manager**, and click **Activate**. This creates the
   `mod_hetzner_cloud_*` database tables and registers the email templates.
3. Grant your admin role access to the addon under
   **Setup > Addon Modules > Hetzner Cloud Manager > Configure** (permissions tab).
4. Open the addon (left-hand admin menu) and go to the **Accounts** tab.
   Add your Hetzner Cloud project API token(s). Each "account" here maps
   to one Hetzner Cloud **project**.
5. Go to the **Products** tab, pick an account, and click **Import** next
   to any live server type to create a ready-to-sell WHMCS product. New
   products are created **hidden** - review the pricing and description,
   then unhide the product in **Setup > Products/Services**.
6. On the product's **Module Settings** tab, confirm the Account, Server
   Type, Location, and Image dropdowns (all populated live from the
   Hetzner API) and set the client-area feature toggles you want to expose
   (console, rescue, rebuild, PTR, firewall management).
7. (Optional) Add custom fields named **SSH Public Key** and
   **Custom Cloud-Init Script** to the product if you want clients to
   supply their own key/cloud-init at order time.
8. Ensure WHMCS's own cron (`crons/cron.php`) is scheduled as usual -
   stock refresh, pricing sync, and metric billing all run from the
   `DailyCronJob` hook automatically. Alternatively, schedule
   `modules/addons/hetzner_cloud_manager/cron.php` directly for finer
   control (`php cron.php stock|pricing|billing|all`).

## Requirements

- WHMCS 8.x or 9.x
- PHP 8.1, 8.2, or 8.3
- GuzzleHTTP (bundled with WHMCS core's vendor directory; if your install
  is missing it, `composer require guzzlehttp/guzzle` inside
  `modules/addons/hetzner_cloud_manager/` and the module will pick up its
  local `vendor/autoload.php` automatically)
- Outbound HTTPS access to `api.hetzner.cloud`

## Pricing Formulas

Given a Hetzner net-EUR cost `C`, a per-product markup percentage `M`,
and a WHMCS currency exchange rate `R`:

```
Price_Server  = (C * (1 + M_server / 100)) * R
Price_Backup  = ((C * 0.20) * (1 + M_backup / 100)) * R      # Hetzner backups cost 20% of server price
Price_Storage = (Size_GB * Cost_per_GB * (1 + M_storage / 100)) * R
```

These are implemented in
`modules/addons/hetzner_cloud_manager/lib/Controller/PricingSync.php`
and synced into `tblpricing` for every WHMCS billing cycle.

## Security Notes

- API tokens are stored in `mod_hetzner_cloud_accounts.api_token`. Treat
  your WHMCS database backups accordingly; token encryption at rest can
  be added by extending `HetznerClient`'s constructor if desired.
- The client-area AJAX endpoint (`clientarea.php`) requires an
  authenticated WHMCS session (client owning the service, or staff) and
  a per-session CSRF token, and re-validates every destructive action's
  feature toggle server-side.
- noVNC console credentials are passed only in the URL **fragment**
  (after `#`), which browsers never transmit to any server, keeping the
  one-time console password out of logs.

## License

MIT - see [LICENSE](LICENSE). This project bundles no ionCube-encoded
code and no vendored third-party console/JS bundles; the noVNC client
is loaded at runtime from its official CDN build (BSD-2-Clause).
