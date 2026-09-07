<?php
/**
 * Hetzner Cloud Manager - Automated Email Template Installer
 *
 * Registers a set of WHMCS email templates (tbleupport... actually
 * tblemailtemplates) on addon activation so provisioning lifecycle
 * events have ready-to-use notification templates with the merge
 * fields this module populates. Installation is idempotent - existing
 * templates (identified by name) are left untouched so admin edits are
 * never overwritten by a re-activation.
 *
 * Merge tags used below are plain WHMCS email template variables and
 * are populated by hooks.php around the relevant lifecycle events
 * (see hooks.php's email-trigger hooks).
 *
 * @package HetznerCloudManager\Helpers
 */

namespace HetznerCloudManager\Helpers;

use WHMCS\Database\Capsule;

class EmailTemplates
{
    /**
     * @return string[] Names of templates that were newly created
     */
    public static function install(): array
    {
        $created = [];

        foreach (self::definitions() as $template) {
            $exists = Capsule::table('tblemailtemplates')
                ->where('name', $template['name'])
                ->where('type', 'product')
                ->exists();

            if ($exists) {
                continue;
            }

            Capsule::table('tblemailtemplates')->insert([
                'type' => 'product',
                'name' => $template['name'],
                'subject' => $template['subject'],
                'message' => $template['message'],
                'plaintext' => 0,
                'fromname' => '',
                'fromemail' => '',
                'copyto' => '',
                'disabled' => 0,
                'language' => '',
                'custom' => 1,
            ]);

            $created[] = $template['name'];
        }

        return $created;
    }

    protected static function definitions(): array
    {
        return [
            [
                'name' => 'Hetzner Cloud - Server Deployed',
                'subject' => 'Your Cloud Server is Ready - {$service_domain}',
                'message' => self::wrap(
                    '<p>Hi {$client_first_name},</p>' .
                    '<p>Your new cloud server has been deployed and is ready to use.</p>' .
                    '<table cellpadding="6" cellspacing="0" style="border:1px solid #e2e5ec;">' .
                    '<tr><td><strong>Hostname</strong></td><td>{$service_domain}</td></tr>' .
                    '<tr><td><strong>IPv4 Address</strong></td><td>{$server_ip}</td></tr>' .
                    '<tr><td><strong>IPv6 Subnet</strong></td><td>{$server_ipv6}</td></tr>' .
                    '<tr><td><strong>Datacenter</strong></td><td>{$server_datacenter}</td></tr>' .
                    '<tr><td><strong>Server Type</strong></td><td>{$server_type_name}</td></tr>' .
                    '<tr><td><strong>Root Password</strong></td><td>{$server_root_password}</td></tr>' .
                    '</table>' .
                    '<p>For security, please log in and change your root password as soon as possible.</p>'
                ),
            ],
            [
                'name' => 'Hetzner Cloud - Password Reset',
                'subject' => 'Root Password Reset - {$service_domain}',
                'message' => self::wrap(
                    '<p>Hi {$client_first_name},</p>' .
                    '<p>The root password for your server has been reset as requested.</p>' .
                    '<table cellpadding="6" cellspacing="0" style="border:1px solid #e2e5ec;">' .
                    '<tr><td><strong>Server</strong></td><td>{$service_domain} ({$server_ip})</td></tr>' .
                    '<tr><td><strong>New Root Password</strong></td><td>{$new_root_password}</td></tr>' .
                    '</table>'
                ),
            ],
            [
                'name' => 'Hetzner Cloud - Server Rebuilt',
                'subject' => 'Server Reinstalled - {$service_domain}',
                'message' => self::wrap(
                    '<p>Hi {$client_first_name},</p>' .
                    '<p>Your server has been reinstalled with a fresh operating system as requested. All previous data on the disk was erased as part of this process.</p>' .
                    '<table cellpadding="6" cellspacing="0" style="border:1px solid #e2e5ec;">' .
                    '<tr><td><strong>Server</strong></td><td>{$service_domain} ({$server_ip})</td></tr>' .
                    '<tr><td><strong>Operating System</strong></td><td>{$rebuilt_os_name}</td></tr>' .
                    '<tr><td><strong>New Root Password</strong></td><td>{$new_root_password}</td></tr>' .
                    '</table>'
                ),
            ],
            [
                'name' => 'Hetzner Cloud - Rescue Mode Enabled',
                'subject' => 'Rescue Mode Enabled - {$service_domain}',
                'message' => self::wrap(
                    '<p>Hi {$client_first_name},</p>' .
                    '<p>Rescue mode has been enabled for your server. Reboot the server to boot into the rescue environment using the credentials below.</p>' .
                    '<table cellpadding="6" cellspacing="0" style="border:1px solid #e2e5ec;">' .
                    '<tr><td><strong>Server</strong></td><td>{$service_domain} ({$server_ip})</td></tr>' .
                    '<tr><td><strong>Rescue Root Password</strong></td><td>{$rescue_password}</td></tr>' .
                    '</table>'
                ),
            ],
        ];
    }

    protected static function wrap(string $body): string
    {
        return $body . "\n<p>Thanks,<br>{\$company_name}</p>";
    }
}
