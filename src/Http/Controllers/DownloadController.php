<?php

namespace InertiaPixel\LaravelLogViewer\Http\Controllers;

use InertiaPixel\LaravelLogViewer\Exceptions\UnreadableLogFileException;
use InertiaPixel\LaravelLogViewer\Reading\LogReader;
use InertiaPixel\LaravelLogViewer\Sources\LogSourceRepository;
use InertiaPixel\LaravelLogViewer\Support\Redactor;
use InertiaPixel\LaravelLogViewer\Support\Settings;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads one allowlisted file. Only registered when downloads are
 * enabled, and authorized separately ("download" ability).
 */
final class DownloadController
{
    /** Longer lines cannot be redacted reliably and are withheld. */
    private const MAX_LINE = 4194304;

    private const BEGIN_KEY = '/-----BEGIN [A-Z ]*PRIVATE KEY-----/';

    private const END_KEY = '/-----END [A-Z ]*PRIVATE KEY-----/';

    public function __invoke(LogSourceRepository $sources, LogReader $reader, Redactor $redactor, Settings $settings, string $file): Response
    {
        $log = $sources->find($file);

        abort_if($log === null, 404);

        try {
            $handle = $reader->open($log);
        } catch (UnreadableLogFileException) {
            abort(404);
        }

        $redact = $settings->bool('downloads.redact');
        $replacement = $settings->string('privacy.redaction.replacement', '[REDACTED]');

        $response = new StreamedResponse(function () use ($handle, $redact, $redactor, $replacement) {
            try {
                if (! $redact) {
                    fpassthru($handle);

                    return;
                }

                $this->streamRedacted($handle, $redactor, $replacement);
            } finally {
                fclose($handle);
            }
        });

        $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            basename($log->name),
            (string) preg_replace('/[^\x20-\x7E]|[%\/\\\\]/', '_', basename($log->name)),
        ));

        return $response;
    }

    /**
     * Redact line by line, keeping state across lines for multi-line PEM
     * private keys. Line endings are preserved.
     *
     * @param  resource  $handle
     */
    private function streamRedacted($handle, Redactor $redactor, string $replacement): void
    {
        $inKey = false;

        while (($line = fgets($handle, self::MAX_LINE)) !== false) {
            $ending = '';

            if (str_ends_with($line, "\n")) {
                $ending = str_ends_with($line, "\r\n") ? "\r\n" : "\n";
                $line = substr($line, 0, -strlen($ending));
            } elseif (! feof($handle)) {
                // Overlong line: skip the rest of it rather than risk a split secret.
                while (($rest = fgets($handle, self::MAX_LINE)) !== false && ! str_ends_with($rest, "\n")) {
                    // discard
                }

                echo '[line withheld: longer than '.(self::MAX_LINE / 1048576)." MB]\n";

                continue;
            }

            if ($inKey) {
                if (preg_match(self::END_KEY, $line)) {
                    $inKey = false;
                }

                continue;
            }

            if (preg_match(self::BEGIN_KEY, $line) && ! preg_match(self::END_KEY, $line)) {
                $inKey = true;
                echo $replacement.$ending;

                continue;
            }

            echo $redactor->apply($line).$ending;
        }
    }
}
