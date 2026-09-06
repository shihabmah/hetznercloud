<?php
/**
 * Hetzner Cloud Manager - Live Stock & Datacenter Availability Engine
 *
 * Reads GET /v1/server_types and inspects the per-server-type
 * `locations[].available` flags to determine, in real time, whether a
 * given server flavor can currently be deployed in a given location.
 *
 * Results are cached into mod_hetzner_cloud_server_stock so order-form
 * hooks and admin templates can render availability badges without an
 * API round trip on every page view. Call refresh() from the addon's
 * cron entry point to keep the cache warm.
 *
 * @package HetznerCloudManager\Api
 */

namespace HetznerCloudManager\Api;

use WHMCS\Database\Capsule;

class StockAvailability
{
    protected HetznerClient $client;

    public function __construct(HetznerClient $client)
    {
        $this->client = $client;
    }

    /**
     * Pull the full server_types catalog and return a normalized
     * [server_type_name => [location_name => bool available]] map.
     */
    public function fetchLiveAvailability(): array
    {
        $map = [];

        foreach ($this->client->getServerTypes() as $type) {
            $name = $type['name'];
            $map[$name] = [];

            foreach ($type['locations'] ?? [] as $location) {
                $map[$name][$location['name']] = (bool) ($location['available'] ?? false);
            }
        }

        return $map;
    }

    /**
     * Check a single server_type + location combination against the
     * live API response (no cache). Used at checkout/provisioning time
     * where correctness matters more than latency.
     *
     * @return bool true if available, false if sold out or unknown
     */
    public function isAvailable(string $serverType, string $location): bool
    {
        foreach ($this->client->getServerTypes() as $type) {
            if ($type['name'] !== $serverType) {
                continue;
            }

            foreach ($type['locations'] ?? [] as $loc) {
                if ($loc['name'] === $location) {
                    return (bool) ($loc['available'] ?? false);
                }
            }

            // Server type exists but this location was not listed for it.
            return false;
        }

        // Unknown server type - fail closed to avoid provisioning into the void.
        return false;
    }

    /**
     * Refresh the mod_hetzner_cloud_server_stock cache table from the
     * live API. Intended to run on a schedule (e.g. every 15 minutes)
     * via the addon's cron hook.
     *
     * @return int Number of (server_type, location) rows written
     */
    public function refresh(): int
    {
        $availability = $this->fetchLiveAvailability();
        $now = date('Y-m-d H:i:s');
        $written = 0;

        foreach ($availability as $serverType => $locations) {
            foreach ($locations as $location => $available) {
                Capsule::table('mod_hetzner_cloud_server_stock')->updateOrInsert(
                    ['server_type' => $serverType, 'location' => $location],
                    ['is_available' => $available ? 1 : 0, 'last_checked' => $now]
                );
                $written++;
            }
        }

        return $written;
    }

    /**
     * Read the cached availability for a combination without hitting
     * the API. Used by order-form / cart validation hooks for speed.
     * Falls back to a live check if no cache entry exists yet.
     */
    public function isAvailableCached(string $serverType, string $location): bool
    {
        $row = Capsule::table('mod_hetzner_cloud_server_stock')
            ->where('server_type', $serverType)
            ->where('location', $location)
            ->first();

        if (!$row) {
            return $this->isAvailable($serverType, $location);
        }

        return (bool) $row->is_available;
    }

    /**
     * Return all cached stock rows, grouped by server_type, for
     * building order-form dropdowns with "Out of Stock" badges.
     */
    public static function cachedMap(): array
    {
        $map = [];

        foreach (Capsule::table('mod_hetzner_cloud_server_stock')->get() as $row) {
            $map[$row->server_type][$row->location] = (bool) $row->is_available;
        }

        return $map;
    }

    /**
     * List every (server_type, location) pair currently flagged as
     * out-of-stock, most-recently-checked first. Used by the Dashboard
     * controller's capacity warnings widget.
     */
    public static function outOfStock(int $limit = 50): array
    {
        return Capsule::table('mod_hetzner_cloud_server_stock')
            ->where('is_available', 0)
            ->orderBy('last_checked', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }
}
