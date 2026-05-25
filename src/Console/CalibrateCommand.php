<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use ReflectionClass;
use Spiritix\LadaCache\Calibration\TtlCalibrationRepository;
use Spiritix\LadaCache\Database\LadaCacheTrait;
use Spiritix\LadaCache\Redis;
use Throwable;

/**
 * Sample Redis OBJECT IDLETIME for Lada-cached models and derive per-model TTLs.
 *
 * Algorithm:
 *   1. Discover Eloquent models using LadaCacheTrait (or use --model=FQCN).
 *   2. For each model, SCAN tag sets `lada:tags:database:*:table_specific:<table>`
 *      and `:table_unspecific:<table>` to enumerate cache keys for that table.
 *   3. Run OBJECT IDLETIME per key → seconds since last access (pipelined).
 *   4. Compute P50, P95, max of the distribution.
 *   5. calibrated_ttl = max(ceil(P95 × safety_factor), floor(previous_ttl / 2)).
 *   6. Skip models with samples < min_samples; persist only with --apply.
 *
 * Safety:
 *   - Aborts when Redis maxmemory-policy is *-lfu (IDLETIME is unsupported there).
 *   - Tolerates orphan SET members (cache key deleted, tag membership lingering).
 *   - Dry-run by default; --apply mutates the lada_cache_calibrations table only.
 *
 * Designed for periodic cron use. Reads Redis metadata only (no key mutation).
 */
final class CalibrateCommand extends Command
{
    protected $signature = 'lada-cache:calibrate
                            {--apply : Persist calibrated TTLs (default: dry-run)}
                            {--model= : Only calibrate the given fully-qualified model class}
                            {--safety-factor= : Override config safety_factor (P95 multiplier)}
                            {--models-path= : Override scanned directory (default: app_path("Models"))}
                            {--models-namespace= : Override scanned namespace (default: "App\\\\Models\\\\")}';

    protected $description = 'Sample Redis OBJECT IDLETIME for Lada-cached models and compute per-model TTLs.';

    public function __construct(
        private readonly ?Redis $redis = null,
        private readonly ?TtlCalibrationRepository $repository = null,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! (bool) config('lada-cache.active', true)) {
            $this->warn('Lada Cache is disabled — nothing to calibrate.');

            return self::SUCCESS;
        }

        if ($this->redis === null || $this->repository === null) {
            $this->error('Lada Cache calibration dependencies are not bound. Check service provider registration.');

            return self::FAILURE;
        }

        if (! (bool) config('lada-cache.calibration.enabled', false)) {
            $this->warn('Lada Cache calibration is disabled. Set LADA_CACHE_CALIBRATION_ENABLED=true to enable.');

            return self::SUCCESS;
        }

        if (! $this->isIdleTimeSupported()) {
            $this->error('Redis uses an LFU eviction policy; OBJECT IDLETIME is unavailable. Calibration aborted.');

            return self::FAILURE;
        }

        $models = $this->discoverModels();

        if ($models === []) {
            $this->warn('No models using LadaCacheTrait were discovered.');

            return self::SUCCESS;
        }

        try {
            $safetyFactor = $this->resolveSafetyFactor();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $minSamples = (int) config('lada-cache.calibration.min_samples', 50);
        $apply = (bool) $this->option('apply');

        $rows = [];
        $counters = [
            'applied' => 0,
            'dry_run' => 0,
            'skipped_no_samples' => 0,
            'skipped_low_samples' => 0,
        ];

        foreach ($models as $modelClass => $tableName) {
            $metrics = $this->collectMetrics($tableName);

            // Defensive: even when min_samples is misconfigured to 0, never persist
            // a row with zero samples — calibrated_ttl would be 0 which means
            // "persist forever" and that's never what we want from an empty signal.
            if ($metrics['samples'] === 0 || $metrics['samples'] < $minSamples) {
                if ($metrics['samples'] === 0) {
                    $counters['skipped_no_samples']++;
                    // Log so operators can distinguish "model rarely used" from "table name
                    // mismatch / discovery bug" — both surface as 'skipped' in the output.
                    Log::info('[lada-cache:calibrate] No cache entries discovered for model.', [
                        'model' => $modelClass,
                        'table' => $tableName,
                    ]);
                } else {
                    $counters['skipped_low_samples']++;
                }

                $rows[] = [
                    $modelClass,
                    $tableName,
                    $metrics['samples'],
                    '-',
                    '-',
                    '-',
                    $metrics['samples'] === 0
                        ? 'skipped (no cache entries)'
                        : sprintf('skipped (< %d samples)', $minSamples),
                ];

                continue;
            }

            $rawCalibrated = (int) ceil($metrics['p95'] * $safetyFactor);
            $previousTtl = $this->resolveCurrentTtl($modelClass);

            // Survivor-bias guard: OBJECT IDLETIME can only sample keys still alive,
            // so P95 is always ≤ current TTL. Without a floor, repeated cron runs
            // would monotonically shrink TTL toward zero. Floor at half the previous
            // effective TTL so each calibration can move at most one octave down.
            $floor = $previousTtl > 0 ? (int) floor($previousTtl / 2) : 0;
            $calibratedTtl = max($rawCalibrated, $floor);

            $persistedMetrics = array_merge($metrics, [
                'previous_ttl' => $previousTtl,
                'raw_calibrated' => $rawCalibrated,
                'floor' => $floor,
                'safety_factor' => $safetyFactor,
            ]);

            if ($apply) {
                $this->repository->upsert($modelClass, $tableName, $calibratedTtl, $persistedMetrics);
                $counters['applied']++;
            } else {
                $counters['dry_run']++;
            }

            $rows[] = [
                $modelClass,
                $tableName,
                $metrics['samples'],
                $metrics['p50'],
                $metrics['p95'],
                $calibratedTtl,
                $apply ? 'applied' : 'dry-run',
            ];
        }

        $this->table(
            ['Model', 'Table', 'Samples', 'P50 (s)', 'P95 (s)', 'TTL (s)', 'Status'],
            $rows,
        );

        $this->info($apply
            ? 'Calibration persisted. TTL resolver will pick up new values on next read.'
            : 'Dry-run complete. Re-run with --apply to persist.');

        // Summary log for cron-style monitoring (Sentry, Horizon, log aggregator).
        // Operators can alert on sudden drops in `applied` or spikes in `skipped_no_samples`
        // without parsing console output.
        Log::info('[lada-cache:calibrate] Run summary', $counters + [
            'mode' => $apply ? 'apply' : 'dry-run',
            'safety_factor' => $safetyFactor,
            'min_samples' => $minSamples,
            'models_total' => count($models),
        ]);

        return self::SUCCESS;
    }

    /**
     * @throws InvalidArgumentException when an explicit --safety-factor <= 0 is supplied.
     */
    private function resolveSafetyFactor(): float
    {
        $override = $this->option('safety-factor');

        if ($override !== null && is_numeric($override)) {
            $value = (float) $override;

            if ($value <= 0.0) {
                throw new InvalidArgumentException(
                    'safety-factor must be > 0 (got '.$override.'); negative/zero would produce nonsensical TTLs.',
                );
            }

            return $value;
        }

        return (float) config('lada-cache.calibration.safety_factor', 2.0);
    }

    /**
     * Effective TTL currently in force for the given model class.
     * Walks the same resolution chain TtlResolver uses, minus the HasLadaTtl interface
     * (which depends on a Model instance — not relevant when we just need a floor).
     */
    private function resolveCurrentTtl(string $modelClass): int
    {
        $calibrated = $this->repository->findForModel($modelClass);

        if ($calibrated !== null) {
            return $calibrated;
        }

        /** @var array<class-string, ?int> $modelTtls */
        $modelTtls = (array) config('lada-cache.model_ttls', []);

        if (array_key_exists($modelClass, $modelTtls) && $modelTtls[$modelClass] !== null) {
            return (int) $modelTtls[$modelClass];
        }

        return (int) config('lada-cache.expiration_time', 0);
    }

    private function isIdleTimeSupported(): bool
    {
        try {
            $client = $this->redis->getConnection()->client();
            $result = $client->config('GET', 'maxmemory-policy');

            // PhpRedis: ['maxmemory-policy' => 'noeviction'] | Predis: ['maxmemory-policy', 'noeviction']
            if (! is_array($result)) {
                // Non-array return (e.g. false) → CONFIG GET silently failed.
                // Assume IDLETIME works but surface a warning so an LFU misconfiguration
                // doesn't silently calibrate every TTL toward zero.
                $this->warn('Could not determine Redis maxmemory-policy (CONFIG GET returned non-array). Proceeding under assumption that OBJECT IDLETIME is supported.');

                return true;
            }

            $policy = (string) ($result['maxmemory-policy'] ?? $result[1] ?? '');

            if ($policy === '') {
                $this->warn('Redis maxmemory-policy not reported. Proceeding under assumption that OBJECT IDLETIME is supported.');

                return true;
            }

            return ! str_contains(strtolower($policy), 'lfu');
        } catch (Throwable) {
            // CONFIG GET may be ACL-disabled in managed Redis; assume IDLETIME works.
            return true;
        }
    }

    /**
     * @return array<class-string<Model>, string>
     */
    private function discoverModels(): array
    {
        $explicit = $this->option('model');

        if (is_string($explicit) && $explicit !== '') {
            return $this->resolveSingleModel($explicit);
        }

        $modelsPath = $this->resolveModelsPath();
        $namespace = $this->resolveModelsNamespace();

        $models = [];

        if (! is_dir($modelsPath)) {
            return [];
        }

        foreach (File::allFiles($modelsPath) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = $this->classFromPath($file->getPathname(), $modelsPath, $namespace);

            if ($class === null || ! class_exists($class)) {
                continue;
            }

            if (! is_subclass_of($class, Model::class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract()) {
                continue;
            }

            if (! in_array(LadaCacheTrait::class, class_uses_recursive($class), true)) {
                continue;
            }

            try {
                /** @var Model $instance */
                $instance = $reflection->newInstanceWithoutConstructor();
                $models[$class] = $instance->getTable();
            } catch (Throwable) {
                continue;
            }
        }

        ksort($models);

        return $models;
    }

    private function resolveModelsPath(): string
    {
        $override = $this->option('models-path');

        if (is_string($override) && $override !== '') {
            return $override;
        }

        return app_path('Models');
    }

    private function resolveModelsNamespace(): string
    {
        $override = $this->option('models-namespace');

        if (is_string($override) && $override !== '') {
            return rtrim($override, '\\').'\\';
        }

        return 'App\\Models\\';
    }

    /**
     * @return array<class-string<Model>, string>
     */
    private function resolveSingleModel(string $modelClass): array
    {
        if (! class_exists($modelClass)) {
            $this->error(sprintf('Model class %s does not exist.', $modelClass));

            return [];
        }

        if (! is_subclass_of($modelClass, Model::class)) {
            $this->error(sprintf('%s is not an Eloquent model.', $modelClass));

            return [];
        }

        if (! in_array(LadaCacheTrait::class, class_uses_recursive($modelClass), true)) {
            $this->error(sprintf('%s does not use LadaCacheTrait.', $modelClass));

            return [];
        }

        /** @var Model $instance */
        $instance = (new ReflectionClass($modelClass))->newInstanceWithoutConstructor();

        return [$modelClass => $instance->getTable()];
    }

    private function classFromPath(string $path, string $basePath, string $namespace): ?string
    {
        $relative = ltrim(str_replace($basePath, '', $path), DIRECTORY_SEPARATOR);
        $withoutExt = preg_replace('/\.php$/', '', $relative);

        if ($withoutExt === null || $withoutExt === '') {
            return null;
        }

        return $namespace.str_replace(DIRECTORY_SEPARATOR, '\\', $withoutExt);
    }

    /**
     * @return array{samples:int, p50:int, p95:int, max:int}
     */
    private function collectMetrics(string $tableName): array
    {
        $patterns = [
            $this->redis->prefix('tags:database:*:table_specific:'.$tableName),
            $this->redis->prefix('tags:database:*:table_unspecific:'.$tableName),
        ];

        $connPrefix = (string) (config('database.redis.options.prefix') ?? '');
        $idleTimes = [];

        foreach ($patterns as $pattern) {
            foreach ($this->redis->scanKeys($pattern) as $batch) {
                foreach ($batch as $tagKey) {
                    $tagKeyStripped = $connPrefix !== '' && str_starts_with($tagKey, $connPrefix)
                        ? substr($tagKey, strlen($connPrefix))
                        : $tagKey;

                    // Stream the SET via SSCAN — large tag sets can hold millions of cache keys
                    // and a single SMEMBERS would block Redis and balloon client memory.
                    foreach ($this->redis->sScanMembers($tagKeyStripped) as $memberBatch) {
                        foreach ($this->pipelinedIdleTimes($memberBatch) as $idle) {
                            $idleTimes[] = $idle;
                        }
                    }
                }
            }
        }

        return $this->computePercentiles($idleTimes);
    }

    /**
     * Run OBJECT IDLETIME for a batch of keys using a single Redis pipeline round-trip
     * instead of N synchronous calls. Falls back to sequential calls when the
     * underlying client does not expose a pipeline API.
     *
     * @param  array<int, string> $keys
     * @return array<int, int>
     */
    private function pipelinedIdleTimes(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $client = $this->redis->getConnection()->client();
        $idleTimes = [];

        foreach (array_chunk($keys, 100) as $chunk) {
            try {
                $raw = $this->runIdleTimeBatch($client, $chunk);
            } catch (Throwable) {
                continue;
            }

            foreach ($raw as $idle) {
                if ($idle === false || $idle === null) {
                    continue;
                }

                $idleTimes[] = (int) $idle;
            }
        }

        return $idleTimes;
    }

    /**
     * Issue OBJECT IDLETIME for each key in a single Redis pipeline round-trip
     * (PhpRedis or Predis). Returns raw command results aligned with input order.
     *
     * @param  array<int, string> $chunk
     * @return array<int, mixed>
     */
    private function runIdleTimeBatch(mixed $client, array $chunk): array
    {
        // PhpRedis: pipeline() switches the client into queue mode; the flush call
        // returns results in order. Each queued command returns $client (not the value).
        if ($client instanceof \Redis) {
            $client->pipeline();

            foreach ($chunk as $key) {
                $client->object('IDLETIME', $key);
            }

            $results = $client->{'exec'}();

            return is_array($results) ? $results : [];
        }

        // Predis: pipeline() takes a closure and returns ordered results.
        if (is_object($client) && method_exists($client, 'pipeline')) {
            $results = $client->pipeline(static function ($pipe) use ($chunk): void {
                foreach ($chunk as $key) {
                    $pipe->object('IDLETIME', $key);
                }
            });

            return is_array($results) ? $results : [];
        }

        // No pipeline API → sequential fallback.
        $results = [];

        foreach ($chunk as $key) {
            $results[] = is_object($client) && method_exists($client, 'object')
                ? $client->object('IDLETIME', $key)
                : null;
        }

        return $results;
    }

    /**
     * @param  array<int, int>                               $values
     * @return array{samples:int, p50:int, p95:int, max:int}
     */
    private function computePercentiles(array $values): array
    {
        $samples = count($values);

        if ($samples === 0) {
            return ['samples' => 0, 'p50' => 0, 'p95' => 0, 'max' => 0];
        }

        sort($values);

        return [
            'samples' => $samples,
            'p50' => $this->percentile($values, 50),
            'p95' => $this->percentile($values, 95),
            'max' => $values[$samples - 1],
        ];
    }

    /**
     * @param array<int, int> $sortedValues
     */
    private function percentile(array $sortedValues, int $percentile): int
    {
        $count = count($sortedValues);

        if ($count === 0) {
            return 0;
        }

        $index = (int) ceil(($percentile / 100) * $count) - 1;

        return $sortedValues[max(0, min($index, $count - 1))];
    }
}
