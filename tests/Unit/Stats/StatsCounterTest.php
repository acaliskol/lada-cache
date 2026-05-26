<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit\Stats;

use Spiritix\LadaCache\Events\LadaCacheActivity;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Stats\StatsCounter;
use Spiritix\LadaCache\Tests\TestCase;

/**
 * Unit-level checks for {@see StatsCounter}.
 *
 * Verifies:
 *   - In-memory aggregation per (table, action) field.
 *   - Auto-flush triggers: distinct-field threshold and time interval.
 *   - Pipeline writes HINCRBY per field + EXPIRE per bucket.
 *   - Null table coerces to "unknown" so the counter survives malformed events.
 *
 * The counter is exercised directly (not via the event bus) to keep these
 * deterministic.
 */
class StatsCounterTest extends TestCase
{
    private Redis $redis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redis = new Redis;
    }

    public function test_handle_aggregates_in_memory_until_flush(): void
    {
        $counter = $this->makeCounter(maxBatchSize: 1000, maxIntervalSeconds: 1000.0);

        $counter->handle($this->event('hit', 'users'));
        $counter->handle($this->event('hit', 'users'));
        $counter->handle($this->event('miss', 'users'));
        $counter->handle($this->event('hit', 'orders'));

        $this->assertSame([
            'users:hit' => 2,
            'users:miss' => 1,
            'orders:hit' => 1,
        ], $counter->pending());
    }

    public function test_flush_writes_hincrby_per_field_and_sets_bucket_ttl(): void
    {
        $counter = $this->makeCounter(maxBatchSize: 1000, maxIntervalSeconds: 1000.0, bucketTtlSeconds: 3600);

        $counter->handle($this->event('hit', 'users'));
        $counter->handle($this->event('hit', 'users'));
        $counter->handle($this->event('invalidate', 'orders'));

        $counter->flush();

        $bucket = $this->redis->prefix('lada:stats:'.date('YmdH'));

        $this->assertSame('2', (string) $this->redis->hget($bucket, 'users:hit'));
        $this->assertSame('1', (string) $this->redis->hget($bucket, 'orders:invalidate'));

        $ttl = (int) $this->redis->ttl($bucket);
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(3600, $ttl);

        // After flush, pending must be empty so the next handle() starts fresh.
        $this->assertSame([], $counter->pending());
    }

    public function test_max_batch_size_triggers_auto_flush(): void
    {
        $counter = $this->makeCounter(maxBatchSize: 3, maxIntervalSeconds: 1000.0);

        // 3 distinct fields → auto-flush on the 3rd handle.
        $counter->handle($this->event('hit', 'users'));
        $counter->handle($this->event('hit', 'orders'));
        $this->assertCount(2, $counter->pending(), 'no flush yet — under threshold');

        $counter->handle($this->event('hit', 'cities'));

        $this->assertSame([], $counter->pending(), 'auto-flush should fire when distinct field count reaches batch size');

        $bucket = $this->redis->prefix('lada:stats:'.date('YmdH'));
        $this->assertSame('1', (string) $this->redis->hget($bucket, 'users:hit'));
        $this->assertSame('1', (string) $this->redis->hget($bucket, 'orders:hit'));
        $this->assertSame('1', (string) $this->redis->hget($bucket, 'cities:hit'));
    }

    public function test_max_interval_seconds_triggers_auto_flush(): void
    {
        // Very short interval — first event happens "in the past" relative to lastFlush
        // because the constructor sets lastFlush to now and the test waits below.
        $counter = $this->makeCounter(maxBatchSize: 1000, maxIntervalSeconds: 0.05);

        $counter->handle($this->event('hit', 'users'));
        $this->assertCount(1, $counter->pending());

        usleep(80_000); // 80ms — exceeds 50ms interval

        $counter->handle($this->event('hit', 'orders'));

        $this->assertSame([], $counter->pending(), 'interval threshold should auto-flush both pending events');

        $bucket = $this->redis->prefix('lada:stats:'.date('YmdH'));
        $this->assertSame('1', (string) $this->redis->hget($bucket, 'users:hit'));
        $this->assertSame('1', (string) $this->redis->hget($bucket, 'orders:hit'));
    }

    public function test_handle_uses_unknown_when_table_is_null(): void
    {
        $counter = $this->makeCounter(maxBatchSize: 1000, maxIntervalSeconds: 1000.0);

        $counter->handle(new LadaCacheActivity('hit', 'some-key', [], null));

        $this->assertSame(['unknown:hit' => 1], $counter->pending());
    }

    public function test_repeat_handle_on_same_field_increments(): void
    {
        $counter = $this->makeCounter(maxBatchSize: 1000, maxIntervalSeconds: 1000.0);

        for ($i = 0; $i < 50; $i++) {
            $counter->handle($this->event('hit', 'users'));
        }

        $this->assertSame(['users:hit' => 50], $counter->pending());

        $counter->flush();

        $bucket = $this->redis->prefix('lada:stats:'.date('YmdH'));
        $this->assertSame('50', (string) $this->redis->hget($bucket, 'users:hit'));
    }

    public function test_flush_is_no_op_when_pending_empty(): void
    {
        $counter = $this->makeCounter(maxBatchSize: 1000, maxIntervalSeconds: 1000.0);

        $counter->flush();

        $bucket = $this->redis->prefix('lada:stats:'.date('YmdH'));
        $this->assertSame(0, (int) $this->redis->exists($bucket), 'no Redis key should be created for an empty flush');
    }

    private function makeCounter(
        int $maxBatchSize = 100,
        float $maxIntervalSeconds = 5.0,
        int $bucketTtlSeconds = 86400 * 7,
    ): StatsCounter {
        return new StatsCounter($this->redis, $maxBatchSize, $maxIntervalSeconds, $bucketTtlSeconds);
    }

    private function event(string $action, ?string $table): LadaCacheActivity
    {
        return new LadaCacheActivity($action, 'cache-key-'.$action.'-'.($table ?? 'null'), [], $table);
    }
}
