<?php
/**
 * Hetzner Cloud Manager - Database Schema / Auto-Migration
 *
 * Creates and upgrades the mod_hetzner_cloud_* tables used by the addon
 * and provisioning modules. Safe to call on every activation/upgrade;
 * every statement is idempotent (CREATE TABLE IF NOT EXISTS / column
 * existence checks before ALTER TABLE).
 *
 * @package HetznerCloudManager\Database
 */

namespace HetznerCloudManager\Database;

use WHMCS\Database\Capsule;
use Exception;

class Schema
{
    /**
     * Current schema version. Bump this whenever migrate() gains new steps.
     */
    const SCHEMA_VERSION = '1.0.0';

    /**
     * Run full migration. Called from the addon's activate() and
     * from AdminAreaOutput/AdminHomepage on every page load (cheap no-op
     * once tables exist) so upgrades apply automatically.
     *
     * @return array List of human-readable actions performed.
     */
    public static function migrate(): array
    {
        $actions = [];

        $actions[] = self::createAccountsTable();
        $actions[] = self::createServerStockTable();
        $actions[] = self::createInstancesTable();
        $actions[] = self::createMarkupRulesTable();
        $actions[] = self::createSettingsTable();
        $actions[] = self::createActivityLogTable();
        $actions[] = self::createMetricUsageTable();

        self::seedDefaultSettings();

        return array_values(array_filter($actions));
    }

    protected static function createAccountsTable(): ?string
    {
        if (Capsule::schema()->hasTable('mod_hetzner_cloud_accounts')) {
            return null;
        }

        Capsule::schema()->create('mod_hetzner_cloud_accounts', function ($table) {
            $table->increments('id');
            $table->string('account_name', 128);
            $table->text('api_token');
            $table->tinyInteger('is_active')->default(1);
            $table->integer('rate_limit_remaining')->default(3600);
            $table->integer('rate_limit_reset')->default(0);
            $table->string('last_test_status', 32)->nullable();
            $table->text('last_test_message')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
        });

        return 'Created table mod_hetzner_cloud_accounts';
    }

    protected static function createServerStockTable(): ?string
    {
        if (Capsule::schema()->hasTable('mod_hetzner_cloud_server_stock')) {
            return null;
        }

        Capsule::schema()->create('mod_hetzner_cloud_server_stock', function ($table) {
            $table->increments('id');
            $table->string('server_type', 64);
            $table->string('location', 32);
            $table->tinyInteger('is_available')->default(1);
            $table->timestamp('last_checked')->useCurrent();
            $table->unique(['server_type', 'location'], 'unique_flavor_location');
        });

        return 'Created table mod_hetzner_cloud_server_stock';
    }

    protected static function createInstancesTable(): ?string
    {
        if (Capsule::schema()->hasTable('mod_hetzner_cloud_instances')) {
            return null;
        }

        Capsule::schema()->create('mod_hetzner_cloud_instances', function ($table) {
            $table->increments('id');
            $table->integer('service_id')->unsigned()->unique();
            $table->integer('account_id')->unsigned();
            $table->bigInteger('hetzner_server_id')->unsigned();
            $table->string('server_name', 128);
            $table->string('datacenter', 32)->nullable();
            $table->string('server_type', 64)->nullable();
            $table->string('location', 32)->nullable();
            $table->string('ipv4_address', 45)->nullable();
            $table->string('ipv6_subnet', 64)->nullable();
            $table->text('applied_firewall_ids')->nullable();
            $table->integer('snapshot_limit')->default(1);
            $table->tinyInteger('backups_enabled')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('account_id')
                ->references('id')->on('mod_hetzner_cloud_accounts')
                ->onDelete('cascade');
        });

        return 'Created table mod_hetzner_cloud_instances';
    }

    protected static function createMarkupRulesTable(): ?string
    {
        if (Capsule::schema()->hasTable('mod_hetzner_cloud_markup_rules')) {
            return null;
        }

        Capsule::schema()->create('mod_hetzner_cloud_markup_rules', function ($table) {
            $table->increments('id');
            $table->integer('product_id')->unsigned()->unique();
            $table->decimal('base_server_markup_pct', 6, 2)->default(0.00);
            $table->decimal('backup_markup_pct', 6, 2)->default(0.00);
            $table->decimal('snapshot_markup_pct', 6, 2)->default(0.00);
            $table->decimal('volume_markup_pct', 6, 2)->default(0.00);
            $table->decimal('snapshot_per_gb_eur', 6, 4)->default(0.0150);
            $table->decimal('volume_per_gb_eur', 6, 4)->default(0.0450);
            $table->decimal('primary_ipv4_monthly_eur', 6, 2)->default(0.50);
            $table->decimal('floating_ipv4_monthly_eur', 6, 2)->default(3.00);
            $table->decimal('bandwidth_per_tb_eur', 6, 2)->default(1.00);
            $table->tinyInteger('auto_sync_pricing')->default(1);
            $table->timestamp('updated_at')->useCurrent();
        });

        return 'Created table mod_hetzner_cloud_markup_rules';
    }

    protected static function createSettingsTable(): ?string
    {
        if (Capsule::schema()->hasTable('mod_hetzner_cloud_settings')) {
            return null;
        }

        Capsule::schema()->create('mod_hetzner_cloud_settings', function ($table) {
            $table->string('setting_key', 128)->primary();
            $table->text('setting_value')->nullable();
            $table->timestamp('updated_at')->useCurrent();
        });

        return 'Created table mod_hetzner_cloud_settings';
    }

    protected static function createActivityLogTable(): ?string
    {
        if (Capsule::schema()->hasTable('mod_hetzner_cloud_activity_log')) {
            return null;
        }

        Capsule::schema()->create('mod_hetzner_cloud_activity_log', function ($table) {
            $table->increments('id');
            $table->integer('service_id')->unsigned()->nullable();
            $table->integer('account_id')->unsigned()->nullable();
            $table->string('action', 64);
            $table->string('status', 16)->default('info');
            $table->text('message')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        return 'Created table mod_hetzner_cloud_activity_log';
    }

    protected static function createMetricUsageTable(): ?string
    {
        if (Capsule::schema()->hasTable('mod_hetzner_cloud_metric_usage')) {
            return null;
        }

        Capsule::schema()->create('mod_hetzner_cloud_metric_usage', function ($table) {
            $table->increments('id');
            $table->integer('service_id')->unsigned();
            $table->date('period');
            $table->decimal('bandwidth_out_gb', 12, 4)->default(0);
            $table->decimal('snapshot_gb', 12, 4)->default(0);
            $table->decimal('volume_gb', 12, 4)->default(0);
            $table->integer('floating_ips')->default(0);
            $table->tinyInteger('billed')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['service_id', 'period'], 'unique_service_period');
        });

        return 'Created table mod_hetzner_cloud_metric_usage';
    }

    protected static function seedDefaultSettings(): void
    {
        $defaults = [
            'schema_version'          => self::SCHEMA_VERSION,
            'stock_sync_interval_min' => '15',
            'pricing_sync_interval_h' => '12',
            'default_vat_factor'      => '1.00',
        ];

        foreach ($defaults as $key => $value) {
            $exists = Capsule::table('mod_hetzner_cloud_settings')->where('setting_key', $key)->exists();
            if (!$exists) {
                Capsule::table('mod_hetzner_cloud_settings')->insert([
                    'setting_key'   => $key,
                    'setting_value' => $value,
                    'updated_at'    => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    /**
     * Drop all module tables. Used by the addon's deactivate/uninstall hook
     * only when the admin explicitly opts in to full data removal.
     */
    public static function uninstall(): void
    {
        $tables = [
            'mod_hetzner_cloud_metric_usage',
            'mod_hetzner_cloud_activity_log',
            'mod_hetzner_cloud_settings',
            'mod_hetzner_cloud_markup_rules',
            'mod_hetzner_cloud_instances',
            'mod_hetzner_cloud_server_stock',
            'mod_hetzner_cloud_accounts',
        ];

        foreach ($tables as $table) {
            try {
                Capsule::schema()->dropIfExists($table);
            } catch (Exception $e) {
                // Ignore FK ordering issues on drop; log for visibility.
                logActivity('Hetzner Cloud Manager: failed to drop ' . $table . ' - ' . $e->getMessage());
            }
        }
    }

    public static function getSetting(string $key, $default = null)
    {
        $row = Capsule::table('mod_hetzner_cloud_settings')->where('setting_key', $key)->first();
        return $row ? $row->setting_value : $default;
    }

    public static function setSetting(string $key, $value): void
    {
        Capsule::table('mod_hetzner_cloud_settings')->updateOrInsert(
            ['setting_key' => $key],
            ['setting_value' => $value, 'updated_at' => date('Y-m-d H:i:s')]
        );
    }
}
