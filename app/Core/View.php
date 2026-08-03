<?php

declare(strict_types=1);

namespace BookSphere\App\Core;

/**
 * View
 *
 * Renders PHP templates into HTML pages. The view name uses dot
 * notation ("foundation.index") and is translated into a file
 * path ("app/Views/foundation/index.php").
 *
 * The data array is unpacked into variables that the template can
 * use directly. Every view is wrapped inside a LAYOUT - by default
 * the master layout (app/Views/layouts/master.php), which brings
 * in the reusable header, sidebar and footer partials.
 *
 * A different layout can be chosen per page (for example a bare
 * layout for login pages without the application shell).
 */
final class View
{
    /**
     * Render a view inside a layout.
     *
     * @param string      $view   Dot notation, e.g. "foundation.index"
     * @param array       $data   Key/value pairs available to the template
     * @param string|null $layout Layout name, e.g. "layouts.auth".
     *                            Defaults to "layouts.master".
     */
    public static function render(string $view, array $data = [], ?string $layout = null): void
    {
        // Make every data key available as a local variable ($title, ...).
        // EXTR_SKIP never overwrites an already defined variable.
        extract($data, EXTR_SKIP);

        // The view file to render. The leading "$__" prefix keeps the
        // internal variables apart from any variable a page may pass.
        $__view = self::resolve('app/Views/', $view);

        if (!is_file($__view)) {
            throw new \RuntimeException('View not found: ' . $view);
        }

        // The layout that wraps the view (default: the master layout).
        $__layout = self::resolve('app/Views/', $layout ?? 'layouts.master');

        if (!is_file($__layout)) {
            throw new \RuntimeException('Layout not found: ' . ($layout ?? 'layouts.master'));
        }

        // The layout reads the $__view variable and includes the view
        // inside its content area.
        require $__layout;
    }

    /**
     * Render a view WITHOUT a layout and return the HTML as a string.
     *
     * Used for partials that are both included in a page AND shipped
     * over JSON (the live-search results region), so both paths can
     * render the exact same file.
     *
     * @param string $view Dot notation, e.g. "books.partials._results"
     * @param array  $data Key/value pairs available to the partial
     */
    public static function fragment(string $view, array $data = []): string
    {
        extract($data, EXTR_SKIP);

        $__view = self::resolve('app/Views/', $view);

        if (!is_file($__view)) {
            throw new \RuntimeException('View not found: ' . $view);
        }

        ob_start();
        require $__view;

        return (string) ob_get_clean();
    }

    /**
     * Turn dot notation into an absolute file path.
     *
     * "foundation.index" -> "D:/PROJECTS/booksphere/app/Views/foundation/index.php"
     */
    private static function resolve(string $base, string $name): string
    {
        return root_path($base . str_replace('.', '/', $name) . '.php');
    }
}
