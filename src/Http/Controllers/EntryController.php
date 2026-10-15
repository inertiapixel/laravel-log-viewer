<?php

namespace InertiaPixel\LaravelLogViewer\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InertiaPixel\LaravelLogViewer\Exceptions\UnreadableLogFileException;
use InertiaPixel\LaravelLogViewer\Http\DashboardPresenter;
use InertiaPixel\LaravelLogViewer\Query\LogQuery;

/**
 * Details of one entry: a fragment for in-page expansion, or a full page
 * (the no-JavaScript fallback). Entries are addressed by file id, byte
 * offset and a fingerprint of their first line, so a rotated or rewritten file
 * yields 404 instead of showing a different entry.
 */
final class EntryController
{
    public function __invoke(Request $request, LogQuery $query, DashboardPresenter $presenter, string $file, string $offset): Response
    {
        $hash = $request->query('h');

        abort_unless(is_string($hash) && preg_match('/^[a-f0-9]{16}$/', $hash), 404);

        try {
            $entry = $query->find($file, (int) $offset, $hash);
        } catch (UnreadableLogFileException) {
            $entry = null;
        }

        abort_if($entry === null, 404, $presenter->label('detail.not_found'));

        return $presenter->entry($entry, $request->header('X-IPX-Log-Viewer') === 'fragment');
    }
}
