<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Stats;

use Illuminate\Support\Facades\Log;
use Spiritix\LadaCache\Events\LadaCacheActivity;
use Spiritix\LadaCache\Redis;
use Throwable;

/**
 * Per-table cache activity counter (hit / miss / invalidate).
 *
 * Aggregates {@see LadaCacheActivity} events in process memory and periodically
 * flushes the aggregate to Redis as HASH counters keyed by hour bucket:
 *
 *   lada:stats:YYYYMMDDHH
 *     field "users:hit"        → 42819
 *     field "users:miss"       → 512
 *     field "users:invalidate" → 120
 *     field "orders:hit"       → 2840
 *     ...
 *
 * The hash is given a configurable TTL so older buckets self-evict.
 *
 * Flush is triggered by whichever condition fires first:
 *   1. Pending distinct (table:action) keys exceed `maxBatchSize`.
 *   2. Time since last flush exceeds `maxIntervalSeconds`.
 *   3. The application terminates (via `Application::terminating()` hook
 *      registered by the service provider).
 *
 * Runtime-agnostic: works under Octane (singleton state persists across
 * requests), FPM (per-request lifecycle), queue workers, and the scheduler.
 * No dependency on Octane-specific timers.
 *
 * Failure mode: a flush exception is caught and the pending counters are
 * restored so the next flush retries the lost batch. Telemetry must never
 * break a request.
 */
final class StatsCounter
{
    /** @var array<string, int> table:action => count */
    private array $pending = [];

    private float $lastFlush;

    public function __construct(
        private readonly Redis $redis,
        private readonly int $maxBatchSize = 100,
        private readonly float $maxIntervalSeconds = 5.0,
        private readonly int $bucketTtlSeconds = 86400 * 7,
    ) {
        $this->lastFlush = microtime(true);
    }

    public function handle(LadaCacheActivity $event): void
    {
        $table = $event->table ?? 'unknown';
        $field = $table.':'.$event->action;

        $this->pending[$field] = ($this->pending[$field] ?? 0) + 1;

        if (count($this->pending) >= $this->maxBatchSize
            || (microtime(true) - $this->lastFlush) >= $this->maxIntervalSeconds) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->pending === []) {
            $this->lastFlush = microtime(true);

            return;
        }

        $batch = $this->pending;
        $this->pending = [];
        $this->lastFlush = microtime(true);

        try {
            $bucket = $this->bucketKey();
            $ttl = $this->bucketTtlSeconds;

            $this->redis->pipeline(static function ($pipe) use ($bucket, $batch, $ttl): void {
                foreach ($batch as $field => $count) {
                    $pipe->hincrby($bucket, $field, $count);
                }
                $pipe->expire($bucket, $ttl);
            });
        } catch (Throwable $e) {
            Log::warning('[LadaCache] Stats flush failed: '.$e->getMessage());

            // Restore so the next flush retries the lost batch.
            foreach ($batch as $field => $count) {
                $this->pending[$field] = ($this->pending[$field] ?? 0) + $count;
            }
        }
    }

    /**
     * Return pending counters without flushing — primarily for tests/diagnostics.
     *
     * @return array<string, int>
     */
    public function pending(): array
    {
        return $this->pending;
    }

    private function bucketKey(): string
    {
        // Apply Lada's configured prefix so the bucket sits alongside other
        // Lada-owned Redis keys (and tests asserting against the expected key
        // via Redis::prefix() can find it).
        return $this->redis->prefix('lada:stats:'.date('YmdH'));
    }
}
