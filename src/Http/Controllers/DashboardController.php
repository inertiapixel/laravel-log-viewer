<?php

namespace InertiaPixel\LaravelLogViewer\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InertiaPixel\LaravelLogViewer\Http\DashboardPresenter;
use InertiaPixel\LaravelLogViewer\Query\FilterFactory;
use InertiaPixel\LaravelLogViewer\Query\LogQuery;

/**
 * Services are method-injected: controller instances are cached on their
 * route, while these services are scoped to a single request (Octane-safe).
 */
final class DashboardController
{
    /** The full dashboard page. Works without JavaScript. */
    public function index(Request $request, FilterFactory $filters, LogQuery $query, DashboardPresenter $presenter): Response
    {
        [$values, $errors] = $filters->make($request->query());

        return $presenter->page($values, $errors, $query->run($values));
    }

    /** Only the results region, for in-page updates. */
    public function results(Request $request, FilterFactory $filters, LogQuery $query, DashboardPresenter $presenter): Response
    {
        [$values, $errors] = $filters->make($request->query());

        return $presenter->fragment($values, $errors, $query->run($values));
    }
}
