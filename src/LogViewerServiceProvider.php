<?php

namespace InertiaPixel\LaravelLogViewer;

use DateTimeZone;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InertiaPixel\LaravelLogViewer\Exceptions\InvalidConfigurationException;
use InertiaPixel\LaravelLogViewer\Http\Controllers\AssetController;
use InertiaPixel\LaravelLogViewer\Http\DashboardPresenter;
use InertiaPixel\LaravelLogViewer\Http\Middleware\Authorize;
use InertiaPixel\LaravelLogViewer\Http\Middleware\SecureHeaders;
use InertiaPixel\LaravelLogViewer\Indexing\LogIndexer;
use InertiaPixel\LaravelLogViewer\Parsing\LogParser;
use InertiaPixel\LaravelLogViewer\Query\DatePresets;
use InertiaPixel\LaravelLogViewer\Query\EntryFactory;
use InertiaPixel\LaravelLogViewer\Query\FilterFactory;
use InertiaPixel\LaravelLogViewer\Query\LogQuery;
use InertiaPixel\LaravelLogViewer\Reading\LogReader;
use InertiaPixel\LaravelLogViewer\Sources\LogSourceRepository;
use InertiaPixel\LaravelLogViewer\Support\AssetManager;
use InertiaPixel\LaravelLogViewer\Support\Redactor;
use InertiaPixel\LaravelLogViewer\Support\Settings;
use Throwable;

class LogViewerServiceProvider extends ServiceProvider
{
    /** View, translation and publish-tag namespace. */
    public const NAME = 'inertiapixel-log-viewer';

    /** Bumped when the index format or parsing changes, to invalidate caches. */
    public const INDEX_VERSION = '1';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/log-viewer.php', Settings::KEY);

        // Scoped: resolved once per request (or Octane operation), never shared across them.
        $this->app->scoped(Settings::class, fn (Application $app) => new Settings($app->make('config')));

        $this->app->scoped(LogSourceRepository::class, fn (Application $app) => new LogSourceRepository(
            $app->make(Settings::class),
            $app->make('config'),
            $app,
            $app->basePath(),
        ));

        $this->app->scoped(LogParser::class, function (Application $app) {
            $settings = $app->make(Settings::class);

            return new LogParser(
                $settings->strings('parsing.formats'),
                $settings->strings('parsing.patterns'),
                $this->timezone($app, 'parsing.timezone'),
            );
        });

        $this->app->bind(LogReader::class);

        $this->app->scoped(Redactor::class, fn (Application $app) => Redactor::fromSettings($app->make(Settings::class), $app->basePath()));

        $this->app->scoped(EntryFactory::class);

        $this->app->scoped(LogIndexer::class, function (Application $app) {
            $settings = $app->make(Settings::class);

            return new LogIndexer(
                $app->make(LogReader::class),
                $app->make(LogParser::class),
                $this->cacheStore($app, $settings),
                $settings->int('cache.ttl', 1, 86400),
                $settings->string('cache.prefix', 'inertiapixel-log-viewer'),
                $settings->int('limits.scan_bytes', 4096),
                $settings->int('limits.max_entries_per_file', 100, 10000000),
                $this->indexSignature($app, $settings),
            );
        });

        $this->app->scoped(DatePresets::class, function (Application $app) {
            $settings = $app->make(Settings::class);

            return new DatePresets(
                $settings->array('filters.date_range.presets'),
                $this->timezone($app, 'ui.timezone'),
                $settings->int('filters.date_range.first_weekday', 0, 6),
            );
        });

        $this->app->scoped(FilterFactory::class);

        $this->app->scoped(LogQuery::class, fn (Application $app) => new LogQuery(
            $app->make(LogSourceRepository::class),
            $app->make(LogIndexer::class),
            $app->make(LogReader::class),
            $app->make(EntryFactory::class),
            $app->make(Redactor::class),
            $app->make(Settings::class),
            $this->timezone($app, 'ui.timezone'),
            $app->make('translator'),
        ));

        $this->app->scoped(AssetManager::class);

        $this->app->scoped(DashboardPresenter::class, fn (Application $app) => new DashboardPresenter(
            $app->make(Settings::class),
            $app->make(LogSourceRepository::class),
            $app->make(FilterFactory::class),
            $app->make(DatePresets::class),
            $app->make(AssetManager::class),
            $app->make(Redactor::class),
            $app->make('view'),
            $app->make('translator'),
            $this->timezone($app, 'ui.timezone'),
        ));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', self::NAME);
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', self::NAME);

        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();
        }
    }

    /**
     * The display timezone ("ui.timezone") falls back to the parsing
     * timezone, which falls back to the application timezone.
     */
    public function timezone(Application $app, string $key): DateTimeZone
    {
        $settings = $app->make(Settings::class);
        $name = $settings->get($key);

        if ($key === 'ui.timezone' && ! is_string($name)) {
            return $this->timezone($app, 'parsing.timezone');
        }

        if (! is_string($name) || $name === '') {
            $name = (string) ($app->make('config')->get('app.timezone') ?: 'UTC');
        }

        try {
            return new DateTimeZone($name);
        } catch (Throwable) {
            throw new InvalidConfigurationException("log-viewer.{$key} is not a valid timezone.");
        }
    }

    private function cacheStore(Application $app, Settings $settings): ?\Illuminate\Contracts\Cache\Repository
    {
        if (! $settings->bool('cache.enabled')) {
            return null;
        }

        try {
            $store = $settings->get('cache.store');

            return $app->make('cache')->store(is_string($store) && $store !== '' ? $store : null);
        } catch (Throwable) {
            // A missing or misconfigured store disables caching instead of breaking the viewer.
            return null;
        }
    }

    private function indexSignature(Application $app, Settings $settings): string
    {
        return md5((string) json_encode([
            self::INDEX_VERSION,
            $settings->get('limits.scan_bytes'),
            $settings->get('limits.max_entries_per_file'),
            $settings->get('parsing.formats'),
            $settings->get('parsing.patterns'),
            $this->timezone($app, 'parsing.timezone')->getName(),
        ]));
    }

    private function registerRoutes(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        $settings = new Settings($this->app->make('config'));

        if (! $settings->bool('enabled') || ! $settings->bool('route.enabled')) {
            return;
        }

        $prefix = trim($settings->string('route.prefix', 'log-viewer'), '/');
        $name = $settings->string('route.name', 'inertiapixel.log-viewer.');
        $domain = $settings->get('route.domain');
        $domain = is_string($domain) && $domain !== '' ? $domain : null;

        // Static assets: no session, cookies or authentication.
        Route::group(['domain' => $domain, 'prefix' => $prefix, 'as' => $name], function () {
            Route::get('assets/{file}', AssetController::class)
                ->where('file', 'log-viewer\.(js|css)')
                ->name('asset');
        });

        Route::group([
            'domain' => $domain,
            'prefix' => $prefix,
            'as' => $name,
            // The authorization middleware is always last and cannot be removed.
            'middleware' => array_merge($settings->strings('route.middleware'), [SecureHeaders::class, Authorize::class]),
        ], function () use ($settings) {
            $downloads = $settings->bool('downloads.enabled');

            require __DIR__.'/../routes/web.php';
        });

        // Names are assigned after the routes were added to the collection.
        Route::getRoutes()->refreshNameLookups();
    }

    private function registerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../config/log-viewer.php' => config_path('log-viewer.php'),
        ], self::NAME.'-config');

        $this->publishes([
            __DIR__.'/../resources/dist' => public_path(AssetManager::PUBLISH_PATH),
        ], self::NAME.'-assets');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/'.self::NAME),
        ], self::NAME.'-views');

        $this->publishes([
            __DIR__.'/../resources/lang' => $this->app->langPath('vendor/'.self::NAME),
        ], self::NAME.'-lang');
    }
}
