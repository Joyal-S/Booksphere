<?php

declare(strict_types=1);

namespace BookSphere\App\Core;

/**
 * Controller
 *
 * Base class for all controllers (e.g. BookController).
 *
 * A controller receives a request, performs the action the user
 * asked for (usually by calling a model or service), and then
 * returns a response. Controllers stay thin: they orchestrate,
 * they do not contain business logic.
 *
 * It exists so every controller gets the same small set of
 * presentation helpers (like view()) without repeating them.
 */
abstract class Controller
{
    /**
     * Render a view with presentation data.
     *
     * @param string      $view   Dot notation, e.g. "foundation.index"
     * @param array       $data   Key/value pairs made available to the template
     * @param string|null $layout Layout name, e.g. "layouts.auth".
     *                            Defaults to the master layout.
     */
    protected function view(string $view, array $data = [], ?string $layout = null): void
    {
        Response::view($view, $data, 200, $layout);
    }
}
