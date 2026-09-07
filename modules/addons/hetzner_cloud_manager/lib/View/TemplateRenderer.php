<?php
/**
 * Hetzner Cloud Manager - Lightweight Template Renderer
 *
 * WHMCS addon modules render admin output as a raw HTML string returned
 * from the module's *_output() function. Rather than pull in a full
 * Smarty dependency for a self-contained, ionCube-free module, this
 * renderer extracts an associative array of variables into local scope
 * and includes a plain PHP/HTML ".tpl" file, buffering its output.
 *
 * Templates use `<?= $variable ?>` / `<?php foreach (...) ?>` PHP tags
 * directly - no separate template language to learn, no extra Composer
 * dependency, and htmlspecialchars() escaping is explicit and visible
 * wherever it matters.
 *
 * @package HetznerCloudManager\View
 */

namespace HetznerCloudManager\View;

use Exception;

class TemplateRenderer
{
    protected string $templatesPath;
    protected array $globals = [];

    public function __construct(string $templatesPath)
    {
        $this->templatesPath = rtrim($templatesPath, '/');
    }

    /**
     * Variables merged into every render() call, e.g. the module link,
     * active tab, whmcs version, currency symbol, etc.
     */
    public function setGlobals(array $globals): void
    {
        $this->globals = $globals;
    }

    /**
     * Render a single template partial to an HTML string.
     *
     * @param string $template Relative path under templates/, without extension, e.g. "admin/dashboard"
     * @param array  $vars     Variables made available inside the template
     */
    public function render(string $template, array $vars = []): string
    {
        $file = $this->templatesPath . '/' . $template . '.tpl';

        if (!is_file($file)) {
            throw new Exception("Template not found: {$template}.tpl");
        }

        $data = array_merge($this->globals, $vars);

        // Isolate template scope in a closure-free include via a helper method.
        return $this->includeWithVars($file, $data);
    }

    /**
     * Render a template and wrap it inside the shared admin layout shell
     * (nav tabs, page header) defined in templates/admin/layout.tpl.
     */
    public function renderPage(string $activeTab, string $template, array $vars = []): string
    {
        $content = $this->render($template, $vars);

        return $this->render('admin/layout', array_merge($vars, [
            'active_tab' => $activeTab,
            'content'    => $content,
        ]));
    }

    protected function includeWithVars(string $__file, array $__data): string
    {
        extract($__data, EXTR_SKIP);
        ob_start();

        try {
            include $__file;
        } catch (\Throwable $e) {
            // Always discard the partial output before bubbling up, otherwise
            // a broken template leaks half-rendered HTML into the admin page
            // and leaves a dangling output buffer open.
            ob_end_clean();
            throw $e;
        }

        return ob_get_clean();
    }

    /**
     * Escape helper, also available to templates as the global hcm_e()
     * (see lib/helpers.php - templates run in the global namespace, so the
     * helper cannot be declared inside this namespace).
     */
    public static function e($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
