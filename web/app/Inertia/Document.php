<?php

declare(strict_types=1);

namespace App\Inertia;

/**
 * Renders the root HTML template, passing the page object in explicitly rather
 * than letting a local variable leak into an include.
 */
final class Document
{
    /**
     * @param string $pageJson HTML-escaped page object for the data-page attribute.
     */
    public static function render(string $pageJson): string
    {
        $template = config('settings.views_path') . '/app.php';

        return (static function () use ($template, $pageJson): string {
            ob_start();
            include $template;

            return (string) ob_get_clean();
        })();
    }
}
