# InertiaPixel Laravel Log Viewer

A secure, read-only log dashboard for Laravel. Search, filter by level, file, channel and date range, see trends and the most frequent errors, and inspect stack traces, with no npm, Vite or CSS framework required in your application.

```bash
composer require inertiapixel/laravel-log-viewer
```

Then open `/log-viewer`.

- **Protected by default.** Authentication plus authorization (your Gate, a callback, or local-only), with a configurable guard and middleware.
- **Read-only and allowlisted.** Only files inside configured log directories can be read. Paths are canonicalized, and symlink escapes, hidden files and traversal are rejected. Deleting logs is not supported.
- **Bounded on large files.** Only the newest part of each file is scanned. A compact, cached index makes filtering fast and grows incrementally with the file.
- **Safe rendering.** Everything is escaped on the server, secrets are masked (best-effort), absolute paths are hidden, and responses are `no-store`.
- **Isolated assets.** One prefixed stylesheet and one strict-mode script exposing one global (`InertiaPixelLogViewer`). Both are bundled, including the date-range picker and the charts.
- **Works without JavaScript.** Plain forms and links. JavaScript adds in-page updates, the calendar, charts and inline details.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Access control](#access-control)
- [Configuration](#configuration)
- [Log sources](#log-sources)
- [Supported formats](#supported-formats)
- [Search, filters and pagination](#search-filters-and-pagination)
- [Date range and timezones](#date-range-and-timezones)
- [Charts and summary](#charts-and-summary)
- [Using your own layout](#using-your-own-layout)
- [Styling and CSS isolation](#styling-and-css-isolation)
- [JavaScript](#javascript)
- [Security and privacy](#security-and-privacy)
- [Performance and operations](#performance-and-operations)
- [Troubleshooting](#troubleshooting)
- [Known limitations](#known-limitations)
- [Development](#development)
- [License](#license)

## Requirements

| | Supported | Tested |
|---|---|---|
| PHP | 8.2 – 8.5 | 8.2.10, 8.3 (8.3.0RC1 build), 8.4.18, 8.5.2 |
| Laravel | 12.x, 13.x | 12.69 (lowest resolvable), 12.70, 13.36 |
| PHP extensions | `json`, `mbstring` | |
| Browsers | Current Chrome, Edge, Firefox, Safari (Safari 15.4+) | Chromium, Firefox and WebKit via Playwright 1.62 |

The package is installed into fresh Laravel 12 (PHP 8.2) and Laravel 13 (PHP 8.4) applications as part of the test suite (see [Development](#development)).

## Installation

```bash
composer require inertiapixel/laravel-log-viewer
```

The service provider is auto-discovered. With the defaults, the dashboard is available at `https://your-app.test/log-viewer` and reads `storage/logs/*.log` plus the paths of your `single` and `daily` log channels.

Out of the box, signed-in users of your default guard can open it **in the `local` environment only**. In any other environment, define who may access it (next section).

Publishing is optional:

```bash
php artisan vendor:publish --tag=inertiapixel-log-viewer-config   # config/log-viewer.php
php artisan vendor:publish --tag=inertiapixel-log-viewer-assets   # public/vendor/inertiapixel/log-viewer
php artisan vendor:publish --tag=inertiapixel-log-viewer-views    # resources/views/vendor/inertiapixel-log-viewer
php artisan vendor:publish --tag=inertiapixel-log-viewer-lang     # lang/vendor/inertiapixel-log-viewer
```

A published config file may contain only the keys you change. Missing keys fall back to the defaults section by section, while lists such as `route.middleware` replace the default list.

## Access control

Every route except the two static assets runs through, in order:

1. the middleware in `log-viewer.route.middleware` (default `['web']`);
2. the package's `Authorize` middleware, which cannot be removed.

`Authorize` resolves the user from `log-viewer.auth.guard` (default: your default guard), then:

| Situation | Response |
|---|---|
| Guest, JSON/AJAX request | `401` JSON |
| Guest, browser request | Redirect to your app's guest redirect or `login` route; plain `401` when neither exists |
| Signed in, not authorized | `403` |

Authorization is decided by the first of these that applies:

**1. A callback** (most flexible), registered in a service provider:

```php
use Illuminate\Http\Request;
use InertiaPixel\LaravelLogViewer\LogViewer;

public function boot(): void
{
    LogViewer::authorizeUsing(function (Request $request, $user, string $ability) {
        // $ability is "view" or "download"
        return $user?->is_admin === true;
    });
}
```

**2. A Gate**, named by `auth.gate` (default `viewLogViewer`):

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewLogViewer', fn ($user) => in_array($user->email, ['ops@example.com']));
```

Downloads (when enabled) additionally check `auth.download_gate` (default `downloadLogViewerFiles`) if you define it.

**3. Neither defined:** access is allowed only in `auth.local_environments` (default `['local']`).

Other options:

```php
// config/log-viewer.php
'route' => [
    'middleware' => ['web', 'can:admin', 'role:ops'], // any middleware your app provides
],
'auth' => [
    'guard' => 'admin',                // e.g. a separate admin guard
    'require_authentication' => true,  // set false only if other middleware authenticates
],
```

No particular permissions package is required. Role or permission middleware from Spatie, Bouncer and similar packages can be added to `route.middleware`.

## Configuration

All options live in [`config/log-viewer.php`](config/log-viewer.php), where each one is documented inline. Summary:

| Key | Default | Purpose |
|---|---|---|
| `enabled` | `env('LOG_VIEWER_ENABLED', true)` | Master switch. When false, no routes are registered |
| `route.enabled` | `true` | Register routes |
| `route.prefix` | `env('LOG_VIEWER_PATH', 'log-viewer')` | URL prefix |
| `route.name` | `inertiapixel.log-viewer.` | Route-name prefix (`index`, `results`, `entry`, `download`, `asset`) |
| `route.domain` | `env('LOG_VIEWER_DOMAIN')` | Optional domain, e.g. `ops.example.com` |
| `route.middleware` | `['web']` | Runs before the package's authorization |
| `auth.guard` | `null` | Guard used to resolve the user |
| `auth.require_authentication` | `true` | Reject guests with 401 or a redirect |
| `auth.gate` / `auth.download_gate` | `viewLogViewer` / `downloadLogViewerFiles` | Abilities checked when defined |
| `auth.local_environments` | `['local']` | Fallback when no gate or callback exists |
| `sources.*` | `storage/logs`, `*.log` | See [Log sources](#log-sources) |
| `parsing.timezone` | `null` (app timezone) | Timezone of timestamps without an offset |
| `parsing.formats` | `['laravel', 'json']` | Built-in formats to recognize |
| `parsing.patterns` | `[]` | Regexes for custom formats |
| `limits.scan_bytes` | `8 MB` | Newest bytes scanned per file |
| `limits.max_entries_per_file` | `100000` | Index cap per file (newest kept) |
| `limits.max_files_per_query` | `10` | Files searched for "All files" |
| `limits.max_entry_bytes` | `256 KB` | Longer entries are truncated for display/search |
| `limits.top_messages` | `5` | Frequent messages listed (`0` disables) |
| `limits.search_seconds` | `10` | Time budget of a text search |
| `cache.enabled` / `store` / `ttl` | `true` / `file` / `600` | Index cache (no log text is cached) |
| `ui.title`, `ui.theme` | `Log Viewer`, `auto` | Page title; `light`, `dark` or `auto` |
| `ui.layout`, `ui.section` | `null`, `content` | Render inside your Blade layout |
| `ui.timezone`, `ui.date_format` | `null`, `Y-m-d H:i:s` | Display timezone and format |
| `ui.per_page`, `ui.per_page_options` | `50`, `[25, 50, 100]` | Page sizes (max 500) |
| `ui.default_sort` | `newest` | `newest` or `oldest` |
| `ui.show_summary`, `ui.show_charts` | `true` | Summary cards and charts |
| `ui.show_stack_traces`, `ui.show_context`, `ui.show_raw` | `true` | Sections of the entry details |
| `ui.show_file_paths` | `false` | Show absolute file paths in details |
| `ui.copy_buttons` | `true` | Copy message, trace, context or raw entry |
| `ui.auto_refresh.enabled`, `.interval` | `false`, `30` | Offer an opt-in auto-refresh toggle |
| `ui.back_url` | `null` | Link back to your application |
| `filters.search.*` | enabled, regex off | Search box, case and trace toggles, regex, max length |
| `filters.levels.available` | all 8 PSR-3 levels | Level checkboxes |
| `filters.files.default` | `all` | `all` or `latest` |
| `filters.channels.enabled` | `true` | Channel select (shown when 2+ channels exist) |
| `filters.date_range.*` | `last_7_days`, picker on | Default preset, presets, picker, first weekday, months |
| `privacy.redaction.*` | on | Built-in and custom masking rules |
| `privacy.hide_base_path` | `true` | Strip `base_path()` from displayed text |
| `downloads.enabled`, `downloads.redact` | `false`, `true` | Whole-file downloads |
| `assets.mode`, `assets.url` | `route`, `null` | Serve assets from the package or `public/` (or a CDN) |

### Precedence

1. Package defaults
2. Your `config/log-viewer.php`
3. URL parameters, only for filters (search, levels, file, channel, dates, sort, page, page size), always validated and normalized

The package never modifies global configuration, and server-side settings are never sent to the browser.

## Log sources

Only allowlisted files can be read:

```php
'sources' => [
    'directories' => [storage_path('logs'), '/var/log/my-app'],
    'files' => ['/var/log/nginx/my-app.error.log'],
    'patterns' => ['*.log'],          // matched against file names
    'recursive' => false,             // also scan sub-directories (max_depth)
    'max_depth' => 2,
    'include_logging_channels' => true, // paths of "single" and "daily" channels
    'resolver' => null,               // class implementing LogPathResolver
    'max_files' => 200,
],
```

Rules that always apply:

- Paths are resolved with `realpath()`. A file whose real location is outside its directory, for example through a symlink, is ignored.
- Hidden files and directories (`.env`, `.git/...`) are never listed, whatever the pattern.
- The filesystem root is refused as a directory, and stream wrappers (`phar://`, `php://`, …) are refused.
- Empty lists mean **no files**, never "everything".
- The browser only ever sees an opaque file id (a hash) and the file name. Request input is never used to build a path.
- Files are re-validated immediately before being opened, so a file swapped for a symlink is not read.
- Unreadable files are listed as such and skipped with a notice.

A custom resolver returns extra absolute file paths, which go through the same checks:

```php
use InertiaPixel\LaravelLogViewer\Sources\LogPathResolver;

final class TenantLogs implements LogPathResolver
{
    public function paths(): iterable
    {
        return glob(storage_path('tenants/*/logs/app.log')) ?: [];
    }
}
```

Compressed (`.gz`) rotated files are **not** supported.

## Supported formats

| Format | Example |
|---|---|
| `laravel`: Monolog `LineFormatter` as configured by Laravel, including multi-line messages, JSON context, `[stacktrace]` and `[previous exception]` | `[2026-10-15 09:30:00] production.ERROR: Message {"exception":"[object] (App\\Exceptions\\X(code: 0): ..."}` |
| `laravel` with ISO-8601 timestamps (Monolog's default) | `[2026-10-15T09:30:00.123456+00:00] app.WARNING: ...` |
| `json`: Monolog `JsonFormatter`, one object per line | `{"message":"...","level_name":"INFO","channel":"app","datetime":"...","context":{...}}` |
| Custom (`parsing.patterns`) | See below |

The parser extracts the timestamp, channel, level, message, context, extra, exception (class, code, message, file, line) and stack trace. Escaped namespaces (`App\\Models\\User`) are shown unescaped.

Custom formats are regexes matched against the first line of an entry. They need the named groups `datetime` and `message`; `level` and `channel` are optional:

```php
'parsing' => [
    'patterns' => [
        '/^(?<datetime>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) (?<level>[A-Z]+) \[(?<channel>[^\]]+)\] (?<message>.*)$/',
    ],
],
```

Lines that match no format are handled as follows:

- Lines after a recognized entry belong to it (stack traces, multi-line messages).
- Lines at the very start of a file become separate **unparsed** entries (level "Unknown").
- A file without any recognized line is shown line by line.

Level names are normalized (`WARN` → warning, `FATAL` → critical, Monolog numbers → names). Unknown levels are filed under "Unknown".

## Search, filters and pagination

- **Search:** substring search, case-insensitive by default (with full Unicode case folding). It covers the message, context and exception summary. **Include stack traces** extends it to traces, and **Match case** makes it case-sensitive. Search matches the text as displayed, after redaction, so masked values cannot be found or guessed. A search stops after `limits.search_seconds` (default 10) with a notice that results are incomplete.
- **Regular expressions:** opt-in with `filters.search.regex = true`. Patterns are length-limited, compiled without the `u` modifier (logs may contain invalid UTF-8) and run under a reduced backtracking limit. An invalid pattern is reported and ignored.
- **Levels:** any combination of emergency, alert, critical, error, warning, notice, info, debug and unknown.
- **File and channel:** one file or "All files", and one channel. The channel select appears when the scanned files contain more than one channel.
- **Sorting:** newest or oldest first. Entries from several files are merged by timestamp.
- **Pagination:** page sizes from `ui.per_page_options`; pages past the end clamp to the last page.
- **Shareable URLs:** filters live in the query string (`?levels[]=error&q=timeout&preset=last_7_days`). Default values are omitted and nothing server-side is included. Invalid parameters are dropped with a visible message instead of failing the page.
- **Active filters** are shown as chips, each with a remove link, plus **Reset**.

## Date range and timezones

The date filter has three parts:

- **Presets:** `today`, `yesterday`, `last_24_hours`, `last_7_days`, `last_14_days`, `last_30_days`, `this_week`, `this_month` and `last_month`. Custom presets cover the last N days:

  ```php
  'date_range' => [
      'default' => 'last_7_days', // or null for "Any time"
      'presets' => ['today', 'yesterday', 'last_7_days', 'last_30_days', 'last_90_days' => ['label' => 'Last 90 days', 'days' => 90]],
  ],
  ```

- **Custom range:** From/To dates (`YYYY-MM-DD`) typed by hand or picked from the calendar. Typing is validated in the browser (real dates, start ≤ end, with accessible error messages) and again on the server.
- **Calendar:** [vanilla-calendar-pro](https://vanilla-calendar.pro) 3.4.0 (MIT), bundled. It opens as a labelled dialog with presets and one or two months (one on narrow screens). It supports arrow-key navigation, closes with Escape and returns focus to its button. Without JavaScript, when `filters.date_range.picker` is false, or if the calendar fails to start, the native date inputs are used.

Timezones:

- `parsing.timezone` is the timezone of timestamps that have **no offset**, which is Laravel's default `Y-m-d H:i:s` format. It defaults to `app.timezone`, which is what Laravel writes logs in. Timestamps that carry an offset (ISO-8601, JSON) use their own offset.
- `ui.timezone` is used both to **display** times and to **interpret date filters**. "2026-10-15" means 00:00:00–23:59:59 in that timezone. It defaults to `parsing.timezone`, and the dashboard shows it next to the filters.

## Charts and summary

Charts use [Chart.js](https://www.chartjs.org) 4.5.1 (MIT), bundled and tree-shaken to bar charts.

- **Summary cards:** entries, errors (emergency through error), warnings, and files scanned. Cards are marked "Partial scan" when a file was larger than the scan window.
- **Entries over time:** stacked bars for errors, warnings and other. The bucket size adapts to the range (hourly up to yearly, at most 31 buckets) and buckets align to calendar days in the display timezone.
- **Entries by level:** horizontal bars with values.
- **Most frequent messages:** groups messages that differ only in numbers ("User 12 not found" and "User 34 not found" are one group). Each links to a search.

All figures reflect the active filters and are computed from the index of the scanned window, never from a separate full scan. Each chart has a text summary (`role="img"` with a label) and a data table. The tables are shown without JavaScript or if a chart fails, and are otherwise available as a disclosure. Colours are validated for colour-vision deficiency and contrast on both themes, and series are named in a legend, never by colour alone. Disable charts with `ui.show_charts` and the cards with `ui.show_summary`.

## Using your own layout

By default the viewer is a standalone page. To place it inside your admin layout:

```php
'ui' => [
    'layout' => 'layouts.admin', // a view that @yield()s the section below
    'section' => 'content',
],
```

The viewer brings its own stylesheet and script and is styled consistently inside layouts that use Tailwind, Bootstrap or custom CSS (see the checklist below).

## Styling and CSS isolation

The stylesheet is plain, hand-written CSS with no framework. Every selector starts with `.ipx-log-viewer` (or `.ipx-log-viewer-page` for the standalone body), and every class is `ipx-log-viewer__*` in BEM style. Design tokens are CSS custom properties scoped to the viewer root, never `:root`.

Override tokens to restyle:

```css
.ipx-log-viewer {
    --ipx-lv-color-primary: #7c3aed;
    --ipx-lv-color-primary-hover: #6d28d9;
    --ipx-lv-radius-lg: 4px;
    --ipx-lv-font-sans: "Inter", system-ui, sans-serif;
}
```

| Group | Tokens |
|---|---|
| Typography | `--ipx-lv-font-sans`, `--ipx-lv-font-mono`, `--ipx-lv-font-size`, `--ipx-lv-text-{xs,sm,md,lg,xl,2xl,3xl}`, `--ipx-lv-leading`, `--ipx-lv-weight-*` |
| Spacing | `--ipx-lv-space-{1,2,3,4,5,6,8,10}` (4px scale) |
| Radii | `--ipx-lv-radius-{sm,md,lg,full}` |
| Colours | `--ipx-lv-color-{page,surface,surface-muted,surface-hover,border,border-strong,text,text-secondary,muted,primary,primary-hover,primary-contrast,primary-soft,danger,danger-soft,notice,notice-soft,focus,code-bg,code-text}` |
| Levels | `--ipx-lv-level-{level}-{bg,text,border}` for every level |
| Charts | `--ipx-lv-chart-{errors,warnings,other,bar,grid,axis}` |
| Effects | `--ipx-lv-shadow-{sm,md,lg}`, `--ipx-lv-ring`, `--ipx-lv-transition` |

Level badges always show the level name. Severe levels also use a square marker and warnings a triangle, so severity never depends on colour. Light and dark themes, `prefers-reduced-motion`, `forced-colors` and responsive layouts (tables become cards on phones) are built in.

### CSS isolation checklist

These checks are automated in `tests/Unit/AssetIsolationTest.php` and `tests/browser/isolation.spec.js`:

- [x] No Tailwind, Bootstrap or other CSS framework is installed, imported or required (`package.json` is checked).
- [x] Every selector in the source and compiled CSS is scoped to `.ipx-log-viewer…`, and every class in the markup is prefixed.
- [x] No `:root` rules, unprefixed custom properties, unprefixed `@keyframes` or inline styles.
- [x] Loading the stylesheet leaves host headings, paragraphs, buttons, badges, tables and inputs unchanged, on a plain page and on pages using Tailwind-preflight-like and Bootstrap-reboot-like element styles.
- [x] Inside those host layouts the viewer renders the same as standalone (buttons, badges, legends, tables, inputs, pagination, cards, typography).
- [x] No horizontal overflow at 390px wide, and tables turn into cards.

The date picker's classes are renamed to `ipx-log-viewer__vc-*`, and its own stylesheet is not loaded. The calendar is styled with the package tokens, so it cannot clash with other calendars on the page.

## JavaScript

The bundle is a strict-mode IIFE that defines a single global:

```js
window.InertiaPixelLogViewer.init(target?)    // initialize dashboards (idempotent)
window.InertiaPixelLogViewer.destroy(target?) // remove listeners, timers, charts and the calendar
window.InertiaPixelLogViewer.get(target)      // instance for a root element or selector
window.InertiaPixelLogViewer.instances()
window.InertiaPixelLogViewer.version
```

Dashboards initialize automatically. Dashboards added later, for example by Livewire, Turbo or partial page loads, are initialized, and removed ones are destroyed, via a `MutationObserver`. Loading the script twice is harmless and never duplicates listeners. All listeners are delegated to the dashboard root or tracked and removed on destroy. The bundle does not touch jQuery, Alpine, Vue, React or other globals.

With JavaScript enabled:

- Filters apply as you change them (search after typing pauses).
- Results are fetched as server-rendered HTML fragments, and the URL and Back button keep working.
- Details expand inline and are loaded on demand.
- A progress bar and `aria-busy` show while loading, a polite status message announces result counts, and errors and expired sessions show a message with **Try again**.
- A response never overwrites input typed after its request started.

## Security and privacy

Logs can contain passwords, tokens, personal data and attacker-controlled text.

- **Escaping:** all log text is rendered with Blade's escaping as text inside `<pre>`/text nodes. Log HTML and Blade syntax are shown, never executed. The JavaScript inserts only fragments rendered by the server and sets everything else with `textContent`.
- **Redaction (best-effort):** applied to everything displayed and to downloads:
  - values of sensitive keys, including prefixed ones such as `db_password` or `X-Auth-Token`. Strings, lists and nested objects are masked whole. This applies to JSON, escaped JSON, `print_r`, `var_export`, `key=value` pairs, query strings and headers (passwords, tokens, API keys, cookies, card numbers…). A value cut off by truncation is masked to the end;
  - Bearer/Basic tokens, JWTs, Laravel `base64:` keys, AWS access key ids and PEM private keys;
  - optionally e-mail and IP addresses (`privacy.redaction.rules`), plus your own patterns:

  ```php
  'privacy' => ['redaction' => ['patterns' => ['/\bcust_[a-z0-9]+\b/' => '[CUSTOMER]']]],
  ```

  It fails closed: an invalid custom pattern is a configuration error, and text that a pattern cannot process is withheld. Redaction is **not** a substitute for not logging secrets, so keep sensitive data out of your logs.
- **Paths:** `base_path()` is stripped from displayed text and files are shown by name. Absolute paths appear only with `ui.show_file_paths`.
- **Responses:** `Cache-Control: no-store, private`, `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin` (search terms never leak in the Referer header) and `X-Robots-Tag: noindex`.
- **Read-only:** every route is `GET`. There is no delete or truncate feature.
- **Downloads:** off by default. When enabled they need authorization (plus `downloadLogViewerFiles` if defined) and cover allowlisted files only. Unless `downloads.redact` is false, they are streamed through the redaction rules line by line: multi-line private keys are masked from BEGIN to END, and lines over 4 MB are withheld.
- **Entry links:** entries are addressed by file id, byte offset and a fingerprint of their redacted first line, so links cannot be used to guess masked values.
- **File access:** each file is opened only after re-checking that it is the same regular file that was validated, which defeats symlink swaps between the check and the open.
- **No recursive logging:** the viewer writes nothing to the logs. Configuration errors are thrown as exceptions without log content.
- **CSP:** the pages use no inline scripts or styles, and a CSP nonce from `Vite::useCspNonce()` is added to the asset tags.

## Performance and operations

Each file is indexed once: byte offsets, timestamps, levels, channels and message hashes, at 27 bytes per entry and never any log text. The index is cached (`cache.store`, default `file`; it describes local files, so the host name is part of the key) and extended incrementally when the file grows.

- **Rotation and truncation:** a different inode, a smaller size or different leading bytes trigger a rebuild.
- **Partial writes:** a partially written last line is re-read on the next request.
- **Filtering:** level, channel and date filters use the index alone. Only a text search reads entry text, and only the current page is fully parsed.
- **Selecting files:** "All files" skips files last modified before the date range starts and searches at most `max_files_per_query` files.
- **Large growth:** when a file grows by more than the scan window between requests, its index is rebuilt from the window instead of extended.
- **Deep pages:** skipped entries are counted, not stored, and pages past the middle are read from the other end, so memory stays constant.
- **Search cost:** entries whose raw text cannot match skip redaction. Entries that might match are redacted and then matched.
- **Cache failures:** an unavailable cache store is ignored.

Measured on a 200 MB Laravel log (620,000 entries; PHP 8.4, Apple Silicon, file cache):

| Operation | 8 MB window (default) | 64 MB window |
|---|---|---|
| First request (build index) | 142 ms | 894 ms |
| Later requests (cached index) | 25 ms | 94 ms |
| Level filter | 14 ms | 24 ms |
| Text search (message) | 58 ms | 217 ms |
| Text search matching in 1,239 / 5,000 stack traces | 545 ms | 2.1 s |
| After the file grows | 26 ms | 98 ms |
| Peak memory (incl. framework) | 24–30 MB | 33–41 MB |

Production notes:

- The PHP user needs **read** access to the log files and directories, and write access to the cache store if caching is on. Nothing is written to the log directory.
- Raise `limits.scan_bytes` to reach older entries in big files, or rely on daily logs (`LOG_CHANNEL=daily`), which keep files small and make date filtering skip whole files.
- On multiple servers, each server shows its own local log files.
- With Octane, services are scoped per request and controllers resolve them per call.

## Troubleshooting

| Symptom | Cause |
|---|---|
| `403` in production | No `viewLogViewer` gate or `LogViewer::authorizeUsing()` callback. Access is local-only without one. |
| `401` instead of a login redirect | Your app has no `login` route or guest redirect. Sign in first, or set `auth.guard` if you use a non-default guard. |
| "No log files are available" | Check `sources.directories`/`patterns`, file permissions, and that files aren't hidden or symlinked outside the directory. |
| Entries missing | Check the date range (the default is the last 7 days) and the "Only the most recent … were scanned" notice. |
| Times are off by hours | Set `parsing.timezone` to the timezone the logs were written in, and `ui.timezone` for display. |
| Custom format shows as "Unparsed" | Add a pattern to `parsing.patterns`. |
| Styles or script missing behind a CDN/proxy | Publish the assets and set `assets.mode = 'published'` (optionally `assets.url`). |
| Changes to `.env` not applied | Run `php artisan config:clear` if your config is cached. |

## Known limitations

- Compressed (`.gz`) files are not read.
- There is no streaming "live tail"; the opt-in auto-refresh (`ui.auto_refresh`) re-runs the current query periodically.
- Only the newest `limits.scan_bytes` of each file (and at most `max_entries_per_file` entries) are visible. Older content is reported as a partial scan; it can be downloaded if downloads are enabled.
- Regular-expression search is byte-based (no `u` modifier), so `.` matches a single byte of multi-byte characters.
- Entries with the same timestamp keep their file order. Entries without a timestamp sort with the entry before them.
- Custom formats need a pattern for their first line. Continuation lines are attached to the previous entry.
- In the calendar, arrow keys move within a month; use the month buttons to change months. Under a strict CSP without `'unsafe-inline'` for styles, the calendar's month arrows are always visible (the library sets them through inline styles).
- Entries longer than `limits.max_entry_bytes` (default 256 KB) are truncated for display and search; this includes single-line JSON entries.

## Development

Requirements: PHP 8.2+, Composer, and Node 20+ (only for building assets and browser tests).

```bash
composer install
npm install
npm run build        # bundles resources/js + resources/css into resources/dist (committed)
composer test        # PHPUnit: unit and feature tests
npx playwright install chromium firefox webkit
PHP_BINARY=php npx playwright test   # browser tests against a real Testbench server
```

Integration test in a fresh application (requires network access):

```bash
tests/integration/create-app.sh /tmp/ipx-app "^13.0" php
tests/integration/smoke.sh /tmp/ipx-app php
```

This installs the package from a Composer dist archive and checks the following over HTTP: auto-discovery, the 401/403 behaviour, the dashboard and fragments, redaction, cache headers, `config:cache`/`route:cache`, and publishing.

What the tests cover:

| Area | Where |
|---|---|
| Composer metadata, PSR-4, auto-discovery, namespaces, dist contents | `tests/Unit/ComposerMetadataTest.php` |
| Parser (Laravel, JSON, custom, exceptions, multi-line, malformed, timezones) | `tests/Unit/LogParserTest.php` |
| Index (windows, caps, growth, partial writes, truncation, rotation, cache) | `tests/Unit/LogIndexerTest.php` |
| Queries (filters, search, regex, timezone boundaries, presets, sorting, merging, pagination, limits, unreadable files, trends) | `tests/Unit/LogQueryTest.php` |
| Redaction | `tests/Unit/RedactorTest.php` |
| CSS/JS isolation (static) | `tests/Unit/AssetIsolationTest.php` |
| Routes (prefix, name, domain, middleware, caching headers, assets) | `tests/Feature/RoutesTest.php` |
| Guests, guards, gates, callbacks, middleware, 401/403 | `tests/Feature/AuthorizationTest.php` |
| Traversal, symlink escapes, hidden files, empty config, resolvers | `tests/Feature/SourcesSecurityTest.php` |
| XSS, redaction in responses, invalid UTF-8 | `tests/Feature/SafeRenderingTest.php` |
| Downloads (off by default, authorization, redaction) | `tests/Feature/DownloadTest.php` |
| Rendering, fragments, details, toggles, layouts, defaults without config | `tests/Feature/DashboardTest.php` |
| Browser: filters, history, details, copy, errors, no-JS, auto-refresh, date picker (mouse, keyboard, validation, fallback), charts, global/listener isolation, host CSS isolation, accessibility, mobile | `tests/browser/*.spec.js` |

Not covered by automated tests: screen-reader output (only roles, names and live regions are checked) and real Octane servers (scoped services and method injection are covered).

## License

MIT, see [LICENSE](LICENSE). The bundled script includes [Chart.js](https://github.com/chartjs/Chart.js) (MIT) and [vanilla-calendar-pro](https://github.com/uvarov-frontend/vanilla-calendar-pro) (MIT).
