<?php
/**
 * Hetzner Cloud Manager - Global Template Helper Functions
 *
 * These MUST live in the global namespace: template (.tpl) files are
 * included by TemplateRenderer and therefore execute in the global
 * namespace, so an unqualified hcm_e() call inside a template resolves
 * to \hcm_e(). Declaring these helpers inside the
 * HetznerCloudManager\View namespace (as an earlier revision did) makes
 * them invisible to templates and produces a fatal
 * "Call to undefined function hcm_e()".
 *
 * This file intentionally declares no namespace.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

if (!function_exists('hcm_e')) {
    /**
     * HTML-escape a value for safe output inside a template.
     * Usage: <?= hcm_e($value) ?>
     */
    function hcm_e($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('hcm_attr')) {
    /**
     * Escape a value for use inside an HTML attribute.
     */
    function hcm_attr($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('hcm_money')) {
    /**
     * Format a numeric amount for display, tolerating null/non-numeric input.
     */
    function hcm_money($value, int $decimals = 2): string
    {
        return number_format((float) ($value ?? 0), $decimals);
    }
}
