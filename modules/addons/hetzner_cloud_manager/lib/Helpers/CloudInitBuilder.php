<?php
/**
 * Hetzner Cloud Manager - Dynamic cloud-init (user_data) Builder
 *
 * Generates a #cloud-config document for new servers, injecting the
 * hostname, optional SSH keys, and any client-supplied custom script.
 * If the client supplied their own full custom cloud-init document
 * (starts with "#cloud-config" or "#!"), it is used verbatim instead
 * of being wrapped, so advanced users retain full control.
 *
 * @package HetznerCloudManager\Helpers
 */

namespace HetznerCloudManager\Helpers;

class CloudInitBuilder
{
    /**
     * Build the final user_data string for POST /v1/servers.
     *
     * @param string      $hostname          Server hostname
     * @param string|null $customScript      Raw client-provided cloud-init / shell script, or null
     * @param array       $extraPackages      Additional apt/yum packages to install on first boot
     * @param string|null $motd               Optional message-of-the-day line
     */
    public static function build(string $hostname, ?string $customScript = null, array $extraPackages = [], ?string $motd = null): string
    {
        $customScript = $customScript !== null ? trim($customScript) : '';

        // A full custom document (cloud-config or shebang script) is trusted
        // and passed through unmodified - the client controls provisioning.
        if ($customScript !== '' && (
            str_starts_with($customScript, '#cloud-config') ||
            str_starts_with($customScript, '#!')
        )) {
            return $customScript;
        }

        $lines = ['#cloud-config'];
        $lines[] = 'hostname: ' . self::sanitizeHostname($hostname);
        $lines[] = 'manage_etc_hosts: true';

        if (!empty($extraPackages)) {
            $lines[] = 'package_update: true';
            $lines[] = 'packages:';
            foreach ($extraPackages as $package) {
                $lines[] = '  - ' . preg_replace('/[^a-zA-Z0-9\-_.]/', '', $package);
            }
        }

        if ($motd) {
            $lines[] = 'write_files:';
            $lines[] = '  - path: /etc/motd';
            $lines[] = '    content: |';
            foreach (explode("\n", $motd) as $motdLine) {
                $lines[] = '      ' . $motdLine;
        }
        }

        // A plain (non-cloud-config) custom script gets appended as a
        // runcmd block so it always executes on first boot.
        if ($customScript !== '') {
            $lines[] = 'runcmd:';
            foreach (explode("\n", $customScript) as $scriptLine) {
                if (trim($scriptLine) === '') {
                    continue;
                }
                $lines[] = "  - '" . str_replace("'", "''", $scriptLine) . "'";
            }
        }

        return implode("\n", $lines) . "\n";
    }

    protected static function sanitizeHostname(string $hostname): string
    {
        $hostname = strtolower(trim($hostname));
        $hostname = preg_replace('/[^a-z0-9\-.]/', '-', $hostname);
        return trim($hostname, '-') ?: 'server';
    }
}
