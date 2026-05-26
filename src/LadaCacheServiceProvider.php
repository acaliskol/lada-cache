<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SqliteConnection;
use Illuminate\Database\SqlServerConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestHandled;
use Spiritix\LadaCache\Calibration\TtlCalibrationRepository;
use Spiritix\LadaCache\Console\CalibrateCommand;
use Spiritix\LadaCache\Console\DisableCommand;
use Spiritix\LadaCache\Console\EnableCommand;
use Spiritix\LadaCache\Console\FlushCommand;
use Spiritix\LadaCache\Database\MariaDbConnection as LadaMariaDbConnection;
use Spiritix\LadaCache\Database\MySqlConnection as LadaMySqlConnection;
use Spiritix\LadaCache\Database\PostgresConnection as LadaPostgresConnection;
use Spiritix\LadaCache\Database\QueryBuilder;
use Spiritix\LadaCache\Database\SqliteConnection as LadaSqliteConnection;
use Spiritix\LadaCache\Database\SqlServerConnection as LadaSqlServerConnection;
use Spiritix\LadaCache\Debug\CacheCollector;
use Spiritix\LadaCache\Events\LadaCacheActivity;
use Spiritix\LadaCache\Stats\StatsCounter;
use Spiritix\LadaCache\Stats\StatsReader;

/**
 * Lada Cache service provider for Laravel.
 *
 * Registers bindings, database connection integration (via DB::extend()),
 * artisan commands, and optional Debugbar integration. Configuration
 * publishing is provided and the package's config is merged during registration.
 */
final class LadaCacheServiceProvider extends ServiceProvider
{
    public const CONFIG_FILE = 'lada-cache.php';

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/'.self::CONFIG_FILE => config_path(self::CONFIG_FILE),
        ], 'config');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'migrations');

        // Propagate withoutCache() from the Eloquent builder to the underlying Lada query builder.
        // Registered outside the active guard so the call is a no-op when Lada is disabled.
        EloquentBuilder::macro('withoutCache', function () {
            /** @var EloquentBuilder $this */
            $query = $this->getQuery();

            if ($query instanceof QueryBuilder) {
                $query->withoutCache();
            }

            return $this;
        });

        // If Lada Cache is not active, avoid wiring listeners / debugbar that could resolve Redis.
        if (! (bool) config('lada-cache.active', true)) {
            return;
        }

        if (config('lada-cache.enable_debugbar') && $this->app->bound('debugbar')) {
            $this->registerDebugbarCollector();
        }

        // Register transaction event listeners to coordinate transaction-aware invalidations.
        $events = $this->app['events'];
        $events->listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
            /** @var QueryHandler $handler */
            $handler = $this->app->make('lada.handler');
            $handler->flushQueuedInvalidationsForConnection($event->connection);
        });
        $events->listen(TransactionRolledBack::class, function (TransactionRolledBack $event): void {
            /** @var QueryHandler $handler */
            $handler = $this->app->make('lada.handler');
            $handler->clearQueuedInvalidationsForConnection($event->connection);
        });

        // Auto-flush cache after migrations complete to prevent stale schema-related cache.
        $events->listen(MigrationsEnded::class, function (): void {
            /** @var Cache $cache */
            $cache = $this->app->make('lada.cache');
            $cache->flush();
        });

        // Wire StatsCounter as a listener for LadaCacheActivity events when both
        // event dispatch AND stats collection are enabled. The counter buffers
        // in process memory and flushes either on threshold or at app shutdown.
        if ((bool) config('lada-cache.events.enabled', false)
            && (bool) config('lada-cache.stats.enabled', false)) {
            $events->listen(LadaCacheActivity::class, static function (LadaCacheActivity $event): void {
                /** @var StatsCounter $counter */
                $counter = app('lada.stats_counter');
                $counter->handle($event);
            });

            // Dual flush hook for the trailing batch:
            //
            //   - FPM / CLI:      Application::terminating() fires per-request /
            //                     once before the worker exits. Per-request under
            //                     FPM because each request rebuilds the kernel.
            //   - Octane (Swoole/RoadRunner/FrankenPHP):
            //                     Application::terminating() fires once at
            //                     **worker shutdown**, NOT per-request. Without
            //                     the RequestHandled listener below, the
            //                     in-memory buffer could persist for minutes or
            //                     hours and be lost on a worker crash / OOM /
            //                     graceful restart. The Octane-specific event
            //                     gives us a per-request boundary equivalent to
            //                     the FPM lifecycle.
            //
            // Both listeners are idempotent: an empty buffer is a no-op.
            $this->app->terminating(static function (): void {
                if (! app()->bound('lada.stats_counter')) {
                    return;
                }

                /** @var StatsCounter $counter */
                $counter = app('lada.stats_counter');
                $counter->flush();
            });

            // Defensive class_exists() so the package doesn't require
            // laravel/octane as a hard dependency — non-Octane apps simply skip
            // the registration.
            if (class_exists(RequestHandled::class)) {
                $events->listen(RequestHandled::class, static function (): void {
                    if (! app()->bound('lada.stats_counter')) {
                        return;
                    }

                    /** @var StatsCounter $counter */
                    $counter = app('lada.stats_counter');
                    $counter->flush();
                });
            }
        }

        // Auto-register the calibration cron when enabled and a schedule expression is set.
        // Uses callAfterResolving so we don't force the Schedule kernel to boot unnecessarily —
        // it fires only when the framework itself resolves the scheduler (artisan schedule:* etc).
        // To opt out, set config('lada-cache.calibration.schedule') to an empty string and call
        // Schedule::command(...) yourself in routes/console.php instead.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (! (bool) config('lada-cache.calibration.enabled', false)) {
                return;
            }

            $cron = trim((string) config('lada-cache.calibration.schedule', ''));

            if ($cron === '') {
                return;
            }

            $schedule->command('lada-cache:calibrate', ['--apply'])
                ->cron($cron)
                ->onOneServer()
                ->withoutOverlapping()
                ->runInBackground();
        });
    }

    /**
     * {@inheritDoc}
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/'.self::CONFIG_FILE,
            'lada-cache'
        );

        // Only register when active to avoid bootstrap overhead when disabled
        if ((bool) config('lada-cache.active', true)) {
            $this->registerSingletons();
            $this->registerDatabaseDecorator();
        }

        $this->registerCommands();
    }

    /**
     * {@inheritDoc}
     */
    public function provides(): array
    {
        return [
            'lada.redis',
            'lada.cache',
            'lada.invalidator',
            'lada.ttl_calibration_repo',
            'lada.ttl_resolver',
            'lada.handler',
            'lada.stats_counter',
            'lada.stats_reader',
        ];
    }

    private function registerSingletons(): void
    {
        $this->app->singleton('lada.redis', static fn () => new Redis);

        $this->app->singleton('lada.cache', static fn (Application $app) => new Cache($app->make('lada.redis'), new Encoder)
        );

        $this->app->singleton('lada.invalidator', static fn (Application $app) => new Invalidator($app->make('lada.redis'))
        );

        $this->app->singleton('lada.ttl_calibration_repo', static fn () => new TtlCalibrationRepository);

        $this->app->singleton('lada.ttl_resolver', static fn (Application $app) => new TtlResolver(
            $app->make('lada.ttl_calibration_repo'),
        ));

        $this->app->singleton('lada.handler', static fn (Application $app) => new QueryHandler(
            $app->make('lada.cache'),
            $app->make('lada.invalidator'),
            $app->make('lada.ttl_resolver'),
        ));

        $this->app->singleton('lada.stats_counter', static fn (Application $app) => new StatsCounter(
            $app->make('lada.redis'),
            (int) config('lada-cache.stats.flush_max_batch', 100),
            (float) config('lada-cache.stats.flush_max_seconds', 5.0),
            (int) config('lada-cache.stats.bucket_ttl_seconds', 86400 * 7),
        ));

        $this->app->singleton('lada.stats_reader', static fn (Application $app) => new StatsReader(
            $app->make('lada.redis'),
        ));
    }

    /**
     * Copy driver-specific state from the base connection to the Lada connection.
     */
    private function hydrateLadaConnection(Connection $base, Connection $lada, string $name): Connection
    {
        if (method_exists($lada, 'setReadPdo')) {
            $lada->setReadPdo($base->getReadPdo());
        }
        if (method_exists($lada, 'setName')) {
            $lada->setName($name);
        }

        $lada->setQueryGrammar($base->getQueryGrammar());
        $lada->setPostProcessor($base->getPostProcessor());

        // Initialize schema grammar on base, then mirror it
        $base->getSchemaBuilder();
        if (method_exists($lada, 'setSchemaGrammar') && $base->getSchemaGrammar() !== null) {
            $lada->setSchemaGrammar($base->getSchemaGrammar());
        }

        return $lada;
    }

    private function registerDatabaseDecorator(): void
    {
        DB::extend('mysql', function (array $config, string $name): Connection {
            /** @var MySqlConnection $base */
            $base = app('db.factory')->make($config, $name);
            $lada = new LadaMySqlConnection(
                $base->getPdo(),
                $base->getDatabaseName(),
                $base->getTablePrefix(),
                $base->getConfig(),
            );

            return $this->hydrateLadaConnection($base, $lada, $name);
        });

        // Optional explicit MariaDB driver (alias of MySQL)
        DB::extend('mariadb', function (array $config, string $name): Connection {
            /** @var MySqlConnection $base */
            $base = app('db.factory')->make($config, $name);
            $lada = new LadaMariaDbConnection(
                $base->getPdo(),
                $base->getDatabaseName(),
                $base->getTablePrefix(),
                $base->getConfig(),
            );

            return $this->hydrateLadaConnection($base, $lada, $name);
        });

        DB::extend('pgsql', function (array $config, string $name): Connection {
            /** @var PostgresConnection $base */
            $base = app('db.factory')->make($config, $name);
            $lada = new LadaPostgresConnection(
                $base->getPdo(),
                $base->getDatabaseName(),
                $base->getTablePrefix(),
                $base->getConfig(),
            );

            return $this->hydrateLadaConnection($base, $lada, $name);
        });

        DB::extend('sqlite', function (array $config, string $name): Connection {
            /** @var SqliteConnection $base */
            $base = app('db.factory')->make($config, $name);
            $lada = new LadaSqliteConnection(
                $base->getPdo(),
                $base->getDatabaseName(),
                $base->getTablePrefix(),
                $base->getConfig(),
            );

            return $this->hydrateLadaConnection($base, $lada, $name);
        });

        DB::extend('sqlsrv', function (array $config, string $name): Connection {
            /** @var SqlServerConnection $base */
            $base = app('db.factory')->make($config, $name);
            $lada = new LadaSqlServerConnection(
                $base->getPdo(),
                $base->getDatabaseName(),
                $base->getTablePrefix(),
                $base->getConfig(),
            );

            return $this->hydrateLadaConnection($base, $lada, $name);
        });
    }

    private function registerCommands(): void
    {
        $this->app->singleton('command.lada-cache.flush', static fn () => new FlushCommand);
        $this->app->singleton('command.lada-cache.enable', static fn () => new EnableCommand);
        $this->app->singleton('command.lada-cache.disable', static fn () => new DisableCommand);

        // When Lada is disabled, lada.redis / lada.ttl_calibration_repo are not bound —
        // instantiate the command without dependencies so its disabled-mode graceful path
        // still runs (otherwise the container resolution would throw).
        //
        // Also gate on `calibration.enabled`: `$this->commands()` triggers Artisan
        // resolveCommands → container resolution, which fires during
        // `package:discover` too. In Docker build / CI contexts without a Redis
        // connection (the common case, since calibration.enabled defaults to false),
        // resolving `lada.redis` here would fail with "Connection refused" and crash
        // the build. Calibration disabled = no Redis touch; handle() exits early.
        $this->app->singleton('command.lada-cache.calibrate', static function (Application $app): CalibrateCommand {
            if (! (bool) config('lada-cache.active', true) || ! (bool) config('lada-cache.calibration.enabled', false)) {
                return new CalibrateCommand;
            }

            // StatsReader is optional — the command falls back to "idletime_only"
            // when null. Only inject it when both events + stats are enabled, so
            // disabled installs don't pay for an unused Redis connection.
            $statsReader = ((bool) config('lada-cache.events.enabled', false)
                && (bool) config('lada-cache.stats.enabled', false))
                ? $app->make('lada.stats_reader')
                : null;

            return new CalibrateCommand(
                $app->make('lada.redis'),
                $app->make('lada.ttl_calibration_repo'),
                $statsReader,
            );
        });

        $this->commands([
            'command.lada-cache.flush',
            'command.lada-cache.enable',
            'command.lada-cache.disable',
            'command.lada-cache.calibrate',
        ]);
    }

    private function registerDebugbarCollector(): void
    {
        $this->app->singleton('lada.collector', static fn () => new CacheCollector);
        $this->app->make('debugbar')->addCollector($this->app->make('lada.collector'));
    }
}
