<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Events;

use Spiritix\LadaCache\Console\CalibrateCommand;
use Spiritix\LadaCache\Stats\StatsCounter;

/**
 * Lada Cache activity event — dispatched on cache hit / miss / invalidate.
 *
 * Lightweight hook so listeners can collect per-table cache counters without
 * forcing every install to pay for the dispatch on the hot path.
 *
 * Activation: requires `lada-cache.events.enabled = true` (defaults to false
 * so unused installs incur zero overhead).
 *
 * Example listener:
 *
 *   Event::listen(LadaCacheActivity::class, function (LadaCacheActivity $e): void {
 *       app('redis')->hincrby(
 *           'lada:metrics:'.$e->action,
 *           $e->table ?? 'unknown',
 *           1,
 *       );
 *   });
 *
 * The bundled {@see StatsCounter} listener provides a
 * production-ready buffered counter that periodically writes per-table HASH
 * buckets to Redis — useful as a calibrate-time input to {@see CalibrateCommand}.
 */
final class LadaCacheActivity
{
    /**
     * @param  string  $action  One of: hit, miss, invalidate.
     * @param  string  $key  Cache key (empty string for invalidate-by-tag).
     * @param  array<string>  $tags  Tags associated with the operation.
     * @param  string|null  $table  Primary table for the operation, when known.
     */
    public function __construct(
        public readonly string $action,
        public readonly string $key,
        public readonly array $tags,
        public readonly ?string $table = null,
    ) {}

    public function isHit(): bool
    {
        return $this->action === 'hit';
    }

    public function isMiss(): bool
    {
        return $this->action === 'miss';
    }
}
