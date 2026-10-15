<?php

/*
|--------------------------------------------------------------------------
| InertiaPixel Laravel Log Viewer
|--------------------------------------------------------------------------
|
| Every key is optional. A published copy of this file may contain only the
| keys you want to change: missing keys fall back to the package defaults,
| section by section (lists such as "middleware" are replaced, not merged).
|
| The viewer is read-only. It never writes, truncates or deletes log files.
|
*/

return [

    /*
    | Master switch. When false the package registers no routes at all.
    */
    'enabled' => env('LOG_VIEWER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | "middleware" runs before the package's own authorization middleware,
    | which is always appended and cannot be removed. Add role/permission
    | middleware from your application here, e.g. 'can:admin' or 'role:ops'.
    |
    */
    'route' => [
        'enabled' => true,
        'prefix' => env('LOG_VIEWER_PATH', 'log-viewer'),
        'name' => 'inertiapixel.log-viewer.',
        'domain' => env('LOG_VIEWER_DOMAIN'),
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication and authorization
    |--------------------------------------------------------------------------
    |
    | guard:                   Guard used to resolve the user (null = the
    |                          application's default guard).
    | require_authentication:  Guests receive 401 (JSON) or a redirect to the
    |                          "login" route when it exists. Only disable this
    |                          when another middleware protects the routes.
    | gate:                    Ability checked with Gate::forUser($user). Define
    |                          it in your AppServiceProvider. If it is not
    |                          defined (and no LogViewer::authorizeUsing()
    |                          callback is registered), access is allowed only
    |                          in "local_environments".
    | download_gate:           Extra ability checked for file downloads when
    |                          defined. Otherwise "gate" applies.
    |
    */
    'auth' => [
        'guard' => null,
        'require_authentication' => true,
        'gate' => 'viewLogViewer',
        'download_gate' => 'downloadLogViewerFiles',
        'local_environments' => ['local'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Log sources
    |--------------------------------------------------------------------------
    |
    | Only files inside these allowlisted locations can ever be read. Paths are
    | canonicalized; symlinks resolving outside an allowed directory, hidden
    | files and the filesystem root are always rejected. Empty lists mean "no
    | files", never "everything".
    |
    | directories:              Directories scanned for files matching "patterns".
    | files:                    Individual absolute file paths to allow.
    | patterns:                 Basename glob patterns (fnmatch) for directories.
    | recursive / max_depth:    Scan sub-directories of "directories".
    | include_logging_channels: Also allow the paths of "single" and "daily"
    |                           channels from config/logging.php.
    | resolver:                 Class implementing
    |                           InertiaPixel\LaravelLogViewer\Sources\LogPathResolver
    |                           that returns extra absolute paths. They pass the
    |                           same canonicalization checks.
    | max_files:                Maximum number of files listed.
    |
    */
    'sources' => [
        'directories' => [storage_path('logs')],
        'files' => [],
        'patterns' => ['*.log'],
        'recursive' => false,
        'max_depth' => 2,
        'include_logging_channels' => true,
        'resolver' => null,
        'max_files' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Parsing
    |--------------------------------------------------------------------------
    |
    | timezone:  Timezone of timestamps that carry no offset (Laravel's
    |            default "Y-m-d H:i:s" format). null = config('app.timezone').
    | formats:   Built-in entry formats to recognize: "laravel" (Monolog
    |            LineFormatter, including ISO-8601 timestamps) and "json"
    |            (Monolog JsonFormatter, one object per line).
    | patterns:  Extra regular expressions for custom line formats, tried
    |            after the built-in formats. Each must match the first line of
    |            an entry and define the named groups "datetime" and "message";
    |            "level" and "channel" are optional.
    |            Example: '/^(?<datetime>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) (?<level>[A-Z]+) (?<message>.*)$/'
    |
    | Lines that match no format are attached to the previous entry (stack
    | traces, multi-line messages) or shown as "unparsed" entries.
    |
    */
    'parsing' => [
        'timezone' => null,
        'formats' => ['laravel', 'json'],
        'patterns' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | scan_bytes:            Per file, only the most recent N bytes are scanned.
    |                        Larger files show a "partial" notice.
    | max_entries_per_file:  Upper bound of indexed entries per file (newest kept).
    | max_files_per_query:   "All files" searches at most this many of the most
    |                        recently modified files.
    | max_entry_bytes:       Longer entries are truncated for display and search.
    | top_messages:          Number of frequent error messages shown (0 = off).
    | search_seconds:        Time budget of a text search; slower searches stop
    |                        early and say that results are incomplete.
    |
    */
    'limits' => [
        'scan_bytes' => 8 * 1024 * 1024,
        'max_entries_per_file' => 100000,
        'max_files_per_query' => 10,
        'max_entry_bytes' => 256 * 1024,
        'top_messages' => 5,
        'search_seconds' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Index cache
    |--------------------------------------------------------------------------
    |
    | Each file's entry index (byte offsets, timestamps, levels and message
    | hashes; never log text) is cached and extended incrementally as the file
    | grows, so filtering does not re-parse the file on every request. The
    | index is local to each server, hence the "file" store by default.
    | If the store is unavailable the viewer silently works without a cache.
    |
    */
    'cache' => [
        'enabled' => true,
        'store' => 'file',
        'ttl' => 600,
        'prefix' => 'inertiapixel-log-viewer',
    ],

    /*
    |--------------------------------------------------------------------------
    | User interface
    |--------------------------------------------------------------------------
    |
    | layout / section:  Render inside one of your Blade layouts instead of
    |                    the standalone page, e.g. 'layouts.admin' / 'content'.
    | theme:             "light", "dark" or "auto" (follows the OS setting).
    | timezone:          Timezone used to display timestamps and to interpret
    |                    the date-range filter. null = parsing.timezone.
    | auto_refresh:      Lets users opt in to periodic refreshing. Disabled by
    |                    default; "interval" is in seconds (minimum 5).
    | back_url:          Optional link back to your application.
    |
    */
    'ui' => [
        'title' => 'Log Viewer',
        'layout' => null,
        'section' => 'content',
        'theme' => 'auto',
        'timezone' => null,
        'date_format' => 'Y-m-d H:i:s',
        'per_page' => 50,
        'per_page_options' => [25, 50, 100],
        'default_sort' => 'newest',
        'show_summary' => true,
        'show_charts' => true,
        'show_stack_traces' => true,
        'show_context' => true,
        'show_raw' => true,
        'show_file_paths' => false,
        'copy_buttons' => true,
        'auto_refresh' => [
            'enabled' => false,
            'interval' => 30,
        ],
        'back_url' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    |
    | date_range.default:  Preset applied when no date is in the URL, or null
    |                      for "Any time".
    | date_range.presets:  Built-in keys: today, yesterday, last_24_hours,
    |                      last_7_days, last_14_days, last_30_days, this_week,
    |                      this_month, last_month. Custom presets cover the
    |                      last N days (including today):
    |                      'last_90_days' => ['label' => 'Last 90 days', 'days' => 90]
    | date_range.picker:   Use the bundled calendar picker. When false (or if
    |                      it fails to load) native date inputs are used.
    | search.regex:        Allow regular-expression search (off by default;
    |                      patterns are length-limited and backtracking-capped).
    |
    */
    'filters' => [
        'search' => [
            'enabled' => true,
            'case_sensitive_toggle' => true,
            'stack_trace_toggle' => true,
            'regex' => false,
            'max_length' => 200,
        ],
        'levels' => [
            'enabled' => true,
            'available' => ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'],
        ],
        'files' => [
            'enabled' => true,
            'default' => 'all',
        ],
        'channels' => [
            'enabled' => true,
        ],
        'date_range' => [
            'enabled' => true,
            'default' => 'last_7_days',
            'presets' => ['today', 'yesterday', 'last_7_days', 'last_30_days'],
            'picker' => true,
            'first_weekday' => 1,
            'months' => 2,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Privacy
    |--------------------------------------------------------------------------
    |
    | Redaction is best-effort masking applied to everything displayed (and to
    | downloads when enabled). It is not a substitute for not logging secrets.
    |
    | redaction.rules:          Built-in rules to apply.
    | redaction.sensitive_keys: Keys whose values are masked in JSON,
    |                           key=value and query-string pairs.
    | redaction.patterns:       Custom rules: regex => replacement.
    | hide_base_path:           Remove base_path() from displayed text, so stack
    |                           traces show "app/Models/User.php" instead of
    |                           absolute server paths.
    |
    */
    'privacy' => [
        'redaction' => [
            'enabled' => true,
            'replacement' => '[REDACTED]',
            'rules' => [
                'sensitive_keys' => true,
                'bearer_tokens' => true,
                'jwt' => true,
                'app_keys' => true,
                'aws_keys' => true,
                'private_keys' => true,
                'emails' => false,
                'ip_addresses' => false,
            ],
            'sensitive_keys' => [
                'password', 'password_confirmation', 'current_password', 'new_password',
                'passwd', 'secret', 'client_secret', 'token', 'access_token', 'refresh_token',
                'id_token', 'api_key', 'apikey', 'api_token', 'auth', 'authorization',
                'cookie', 'set-cookie', 'x-api-key', 'x-csrf-token', 'x-xsrf-token',
                'private_key', 'credit_card', 'card_number', 'cvv', 'cvc', 'ssn',
            ],
            'patterns' => [],
        ],
        'hide_base_path' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Downloads
    |--------------------------------------------------------------------------
    |
    | Disabled by default. When enabled, authorized users can download a whole
    | allowlisted file. With "redact" the file is streamed line by line through
    | the redaction rules. Deleting logs is intentionally not supported.
    |
    */
    'downloads' => [
        'enabled' => false,
        'redact' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Assets
    |--------------------------------------------------------------------------
    |
    | "route":     Served by the package from vendor/ (default, nothing to do).
    | "published": Served from public/vendor/inertiapixel/log-viewer after
    |              `php artisan vendor:publish --tag=inertiapixel-log-viewer-assets`.
    |              "url" may point to a CDN copy of that directory.
    |
    */
    'assets' => [
        'mode' => 'route',
        'url' => null,
    ],

];
