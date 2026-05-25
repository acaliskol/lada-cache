<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable or Disable Lada Cache
    |--------------------------------------------------------------------------
    |
    | By setting this value to false, Lada Cache will be completely disabled.
    | This can be useful during debugging, development, or when temporarily
    | troubleshooting cache-related behavior.
    |
    */
    'active' => env('LADA_CACHE_ACTIVE', true),

    /*
    |--------------------------------------------------------------------------
    | Cache Driver
    |--------------------------------------------------------------------------
    |
    | The cache driver that should be used for storing Lada Cache entries.
    | By default, Redis is used since it provides excellent performance for
    | tagged and granular cache invalidation.
    |
    */
    'driver' => env('LADA_CACHE_DRIVER', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Redis Connection Name
    |--------------------------------------------------------------------------
    |
    | Choose which Redis connection (as defined in config/database.php -> redis)
    | Lada Cache should use. This allows isolating Lada Cache from your default
    | Redis connection. Typically you can set this to 'cache' or define a
    | dedicated 'lada-cache' connection.
    |
    */
    'redis_connection' => env('LADA_CACHE_REDIS_CONNECTION', 'cache'),

    /*
    |--------------------------------------------------------------------------
    | Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be prepended to all cache keys stored in Redis.
    | It helps to isolate data between different environments or projects.
    | Do not change this value in production, as doing so will make all
    | previously cached entries inaccessible.
    |
    */
    'prefix' => env('LADA_CACHE_PREFIX', 'lada:'),

    /*
    |--------------------------------------------------------------------------
    | Expiration Time
    |--------------------------------------------------------------------------
    |
    | The number of seconds after which cached items should expire.
    | Setting this value to null will store cached items indefinitely
    | (until manually invalidated). If you want automatic cleanup or
    | to avoid stale data, set this to something like 604800 (7 days).
    |
    */
    'expiration_time' => env('LADA_CACHE_EXPIRATION', 0),

    /*
    |--------------------------------------------------------------------------
    | Per-model TTL overrides
    |--------------------------------------------------------------------------
    |
    | Map of model FQCN to TTL in seconds. Models listed here override the
    | global expiration_time. Resolution order (first non-null wins):
    |   1. $model->getLadaTtl() if model implements
    |      Spiritix\LadaCache\Contracts\HasLadaTtl
    |   2. lada_cache_calibrations.calibrated_ttl
    |      (auto-calibration via lada-cache:calibrate, see "Calibration" below)
    |   3. config('lada-cache.model_ttls.<FQCN>')
    |   4. config('lada-cache.expiration_time') (global)
    |
    | Examples:
    |   App\Models\City::class       => 86400 * 30, // 30 days for rarely-changing data
    |   App\Models\Tournament::class => 300,        // 5 minutes for hot state
    |   App\Models\Order::class      => null,       // fall through to global
    |
    | 0 = persist forever (cache until tag-based invalidation).
    |
    */
    'model_ttls' => [
        // App\Models\City::class => 86400 * 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-calibration
    |--------------------------------------------------------------------------
    |
    | The `lada-cache:calibrate` Artisan command samples Redis OBJECT IDLETIME
    | for cached keys belonging to each Lada-cached model, computes the
    | P95 idle time, and derives a per-model TTL via
    |
    |     calibrated_ttl = max(ceil(P95 * safety_factor), floor(previous_ttl / 2))
    |
    | The floor term guards against survivor bias — OBJECT IDLETIME can only
    | sample keys that haven't yet been evicted, so successive runs would
    | otherwise shrink TTLs monotonically toward zero.
    |
    | Results are stored in the `lada_cache_calibrations` table and consumed
    | by TtlResolver between the HasLadaTtl interface and the static
    | `model_ttls` map (see "Per-model TTL overrides" above).
    |
    | Safety:
    |   - The command refuses to run when Redis maxmemory-policy is *-lfu
    |     (IDLETIME is unsupported under LFU eviction).
    |   - `--apply` is required to persist; the default is a dry-run table.
    |   - Models with fewer than `min_samples` data points are skipped.
    |
    | Designed for periodic cron use, e.g. weekly: `lada-cache:calibrate --apply`.
    |
    */
    'calibration' => [
        'enabled' => (bool) env('LADA_CACHE_CALIBRATION_ENABLED', false),
        'safety_factor' => (float) env('LADA_CACHE_CALIBRATION_SAFETY_FACTOR', 2.0),
        'min_samples' => (int) env('LADA_CACHE_CALIBRATION_MIN_SAMPLES', 50),
        'cache_ttl' => (int) env('LADA_CACHE_CALIBRATION_CACHE_TTL', 300),

        // Cron expression for the auto-scheduled calibration run. Empty string disables
        // the auto-schedule (you can still invoke `php artisan lada-cache:calibrate --apply`
        // manually or register it yourself in `routes/console.php`).
        //
        // Default `0 3 * * 0` = every Sunday at 03:00. Both `enabled=true` AND a non-empty
        // schedule string are required for the service provider to register the cron.
        'schedule' => (string) env('LADA_CACHE_CALIBRATION_SCHEDULE', '0 3 * * 0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Granularity
    |--------------------------------------------------------------------------
    |
    | Determines how precisely the cache is tagged. When enabled (true),
    | cache invalidation happens at the row level, using primary keys to
    | generate tags for each database query. This provides maximum accuracy
    | but may produce more Redis keys.
    |
    | If you experience issues or performance degradation, set this to false
    | to reduce granularity. This tells Lada Cache to ignore row-level keys
    | and cache results at a broader level.
    |
    */
    'consider_rows' => env('LADA_CACHE_CONSIDER_ROWS', true),

    /*
    |--------------------------------------------------------------------------
    | Include Tables
    |--------------------------------------------------------------------------
    |
    | Use this array if you want to cache *only specific tables*.
    | Once any query involves a table not listed here, that query
    | will not be cached.
    |
    | If "include_tables" is not empty, "exclude_tables" will be ignored.
    |
    | Tip: Instead of hardcoding table names, use model instances:
    |
    | 'include_tables' => [
    |     (new \App\Models\User())->getTable(),
    |     (new \App\Models\Post())->getTable(),
    | ],
    |
    */
    'include_tables' => [],

    /*
    |--------------------------------------------------------------------------
    | Exclude Tables
    |--------------------------------------------------------------------------
    |
    | Use this array if you want to cache all tables *except* specific ones.
    | If a query touches any table listed here, it will not be cached.
    | This is the inverse of "include_tables".
    |
    */
    'exclude_tables' => [],

    /*
    |--------------------------------------------------------------------------
    | Debugbar Collector
    |--------------------------------------------------------------------------
    |
    | When enabled, Lada Cache will register a collector for the Laravel
    | Debugbar package, allowing you to view cache activity and hit/miss
    | statistics directly in your browser during development.
    |
    | This is useful for debugging and optimizing query performance.
    |
    */
    'enable_debugbar' => env('LADA_CACHE_ENABLE_DEBUGBAR', true),

];
