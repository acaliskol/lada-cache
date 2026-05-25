<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\QueryBuilder;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Database\QueryBuilder;
use Spiritix\LadaCache\Hasher;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tests\Concerns\CacheAssertions;
use Spiritix\LadaCache\Tests\Database\Models\Car;
use Spiritix\LadaCache\Tests\TestCase;

class WithoutCacheTest extends TestCase
{
    use CacheAssertions;

    public function testWithoutCacheBypassesReadCache(): void
    {
        DB::table('cars')->insert(['id' => 10, 'name' => 'NoCacheRead', 'engine_id' => null, 'driver_id' => null]);

        $builder = DB::table('cars')->where('id', 10);

        // First read with withoutCache should NOT populate cache
        $result = $builder->withoutCache()->get();
        $this->assertNotEmpty($result);

        $key = (new Hasher(new Reflector($builder)))->getHash();
        $this->assertCacheMissing($key, 'withoutCache should not populate Redis on read');
    }

    public function testWithoutCacheBypassesWriteInvalidation(): void
    {
        // Arrange: warm cache for the cars table
        DB::table('cars')->insert(['id' => 20, 'name' => 'OriginalName', 'engine_id' => null, 'driver_id' => null]);
        $builder = DB::table('cars')->where('id', 20);
        $first = $builder->get();
        $this->assertNotEmpty($first);

        $key = (new Hasher(new Reflector($builder)))->getHash();
        $this->assertCacheHas($key, 'Read should populate the cache');

        // Act: write with withoutCache — invalidation must NOT fire
        DB::table('cars')->where('id', 20)->withoutCache()->update(['name' => 'WithoutCacheWrite']);

        // Assert: cache still has the (now-stale) key because invalidation was skipped
        $this->assertCacheHas($key, 'withoutCache write must not queue/fire invalidation');
    }

    public function testNormalWriteStillInvalidates(): void
    {
        // Sanity check that regression of withoutCache did not break default behavior
        DB::table('cars')->insert(['id' => 30, 'name' => 'NormalWriteOriginal', 'engine_id' => null, 'driver_id' => null]);
        $builder = DB::table('cars')->where('id', 30);
        $builder->get();

        $key = (new Hasher(new Reflector($builder)))->getHash();
        $this->assertCacheHas($key);

        DB::table('cars')->where('id', 30)->update(['name' => 'NormalWriteAfter']);

        $this->assertCacheMissing($key, 'Default write path must invalidate');
    }

    public function testWithoutCacheReturnsBuilderForChaining(): void
    {
        $builder = DB::table('cars');
        $returned = $builder->withoutCache();

        $this->assertInstanceOf(QueryBuilder::class, $returned);
        $this->assertTrue($returned->isSkippingCache());
    }

    public function testEloquentMacroPropagatesToUnderlyingQueryBuilder(): void
    {
        Car::query()->forceCreate(['id' => 40, 'name' => 'EloquentMacro', 'engine_id' => null, 'driver_id' => null]);

        $eloquent = Car::query()->where('id', 40);
        $eloquent->withoutCache(); // Eloquent macro
        $underlying = $eloquent->getQuery();

        $this->assertInstanceOf(QueryBuilder::class, $underlying);
        $this->assertTrue($underlying->isSkippingCache(), 'Eloquent macro must flip the underlying QueryBuilder skipCache flag');
    }

    public function testChainOrderingPreservesSkipFlag(): void
    {
        DB::table('cars')->insert(['id' => 50, 'name' => 'ChainOrder', 'engine_id' => null, 'driver_id' => null]);

        // withoutCache before where()
        $builderA = DB::table('cars')->withoutCache()->where('id', 50);
        $builderA->get();
        $keyA = (new Hasher(new Reflector($builderA)))->getHash();
        $this->assertCacheMissing($keyA, 'withoutCache->where chain must bypass');

        // where() before withoutCache
        $builderB = DB::table('cars')->where('id', 50)->withoutCache();
        $builderB->get();
        $keyB = (new Hasher(new Reflector($builderB)))->getHash();
        $this->assertCacheMissing($keyB, 'where->withoutCache chain must bypass');
    }
}
