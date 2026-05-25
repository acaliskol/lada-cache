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
        DB::table('cars')->insert(['id' => 2010, 'name' => 'NoCacheRead', 'engine_id' => null, 'driver_id' => null]);

        $builder = DB::table('cars')->where('id', 2010);

        // First read with withoutCache() must not populate the cache
        $result = $builder->withoutCache()->get();
        $this->assertNotEmpty($result);

        $key = (new Hasher(new Reflector($builder)))->getHash();
        $this->assertCacheMissing($key, 'withoutCache should not populate Redis on read');
    }

    public function testWithoutCacheBypassesUpdateInvalidation(): void
    {
        // Arrange: warm cache for the row
        DB::table('cars')->insert(['id' => 2020, 'name' => 'OriginalName', 'engine_id' => null, 'driver_id' => null]);
        $builder = DB::table('cars')->where('id', 2020);
        $builder->get();

        $key = (new Hasher(new Reflector($builder)))->getHash();
        $this->assertCacheHas($key);

        // Act: update with withoutCache() — invalidation must not fire
        DB::table('cars')->where('id', 2020)->withoutCache()->update(['name' => 'WithoutCacheWrite']);

        // Assert: cache still has the (now-stale) key
        $this->assertCacheHas($key, 'withoutCache write must not queue/fire invalidation');
    }

    public function testWithoutCacheBypassesInsertInvalidation(): void
    {
        // Warm cache on a "named row missing" query
        $builder = DB::table('cars')->where('name', 'InsertBypass');
        $builder->get();
        $key = (new Hasher(new Reflector($builder)))->getHash();
        $this->assertCacheHas($key);

        DB::table('cars')->withoutCache()
            ->insert(['id' => 2030, 'name' => 'InsertBypass', 'engine_id' => null, 'driver_id' => null]);

        $this->assertCacheHas($key, 'withoutCache insert must not invalidate');
    }

    public function testWithoutCacheBypassesInsertGetIdInvalidation(): void
    {
        $builder = DB::table('cars')->where('name', 'InsertGetIdBypass');
        $builder->get();
        $key = (new Hasher(new Reflector($builder)))->getHash();
        $this->assertCacheHas($key);

        DB::table('cars')->withoutCache()
            ->insertGetId(['name' => 'InsertGetIdBypass', 'engine_id' => null, 'driver_id' => null]);

        $this->assertCacheHas($key, 'withoutCache insertGetId must not invalidate');
    }

    public function testWithoutCacheBypassesInsertOrIgnoreInvalidation(): void
    {
        $builder = DB::table('cars')->where('name', 'InsertOrIgnoreBypass');
        $builder->get();
        $key = (new Hasher(new Reflector($builder)))->getHash();
        $this->assertCacheHas($key);

        DB::table('cars')->withoutCache()
            ->insertOrIgnore(['id' => 2040, 'name' => 'InsertOrIgnoreBypass', 'engine_id' => null, 'driver_id' => null]);

        $this->assertCacheHas($key, 'withoutCache insertOrIgnore must not invalidate');
    }

    public function testWithoutCacheBypassesUpsertInvalidation(): void
    {
        DB::table('cars')->insert(['id' => 2050, 'name' => 'UpsertBypassOriginal', 'engine_id' => null, 'driver_id' => null]);
        $builder = DB::table('cars')->where('id', 2050);
        $builder->get();
        $key = (new Hasher(new Reflector($builder)))->getHash();
        $this->assertCacheHas($key);

        DB::table('cars')->withoutCache()
            ->upsert([['id' => 2050, 'name' => 'UpsertBypassNew']], ['id'], ['name']);

        $this->assertCacheHas($key, 'withoutCache upsert must not invalidate');
    }

    public function testWithoutCacheBypassesUpdateOrInsertInvalidation(): void
    {
        $builder = DB::table('cars')->where('id', 2060);
        $builder->get();
        $key = (new Hasher(new Reflector($builder)))->getHash();
        $this->assertCacheHas($key);

        DB::table('cars')->where('id', 2060)->withoutCache()
            ->updateOrInsert(['id' => 2060], ['name' => 'UpdateOrInsertBypass']);

        $this->assertCacheHas($key, 'withoutCache updateOrInsert must not invalidate');
    }

    public function testWithoutCacheBypassesDeleteInvalidation(): void
    {
        DB::table('cars')->insert(['id' => 2070, 'name' => 'DeleteBypass', 'engine_id' => null, 'driver_id' => null]);
        $builder = DB::table('cars')->where('id', 2070);
        $builder->get();
        $key = (new Hasher(new Reflector($builder)))->getHash();
        $this->assertCacheHas($key);

        DB::table('cars')->where('id', 2070)->withoutCache()->delete();

        $this->assertCacheHas($key, 'withoutCache delete must not invalidate');
    }

    public function testWithoutCacheBypassesTruncateInvalidation(): void
    {
        DB::table('cars')->insert(['id' => 2080, 'name' => 'TruncateBypass', 'engine_id' => null, 'driver_id' => null]);
        $builder = DB::table('cars')->where('id', 2080);
        $builder->get();
        $key = (new Hasher(new Reflector($builder)))->getHash();
        $this->assertCacheHas($key);

        DB::table('cars')->withoutCache()->truncate();

        $this->assertCacheHas($key, 'withoutCache truncate must not invalidate');
    }

    public function testNormalWriteStillInvalidates(): void
    {
        // Regression sanity: the default write path must still invalidate
        DB::table('cars')->insert(['id' => 2090, 'name' => 'NormalWriteOriginal', 'engine_id' => null, 'driver_id' => null]);
        $builder = DB::table('cars')->where('id', 2090);
        $builder->get();

        $key = (new Hasher(new Reflector($builder)))->getHash();
        $this->assertCacheHas($key);

        DB::table('cars')->where('id', 2090)->update(['name' => 'NormalWriteAfter']);

        $this->assertCacheMissing($key, 'Default write path must invalidate');
    }

    public function testWithoutCacheReturnsBuilderForChaining(): void
    {
        $builder = DB::table('cars');
        $returned = $builder->withoutCache();

        $this->assertInstanceOf(QueryBuilder::class, $returned);
        $this->assertTrue($returned->isCacheBypassed());
    }

    public function testEloquentMacroPropagatesToUnderlyingQueryBuilder(): void
    {
        Car::query()->forceCreate(['id' => 2100, 'name' => 'EloquentMacro', 'engine_id' => null, 'driver_id' => null]);

        $eloquent = Car::query()->where('id', 2100);
        $eloquent->withoutCache();
        $underlying = $eloquent->getQuery();

        $this->assertInstanceOf(QueryBuilder::class, $underlying);
        $this->assertTrue($underlying->isCacheBypassed(), 'Eloquent macro must flip the underlying query builder flag');
    }

    public function testChainOrderingPreservesBypassFlag(): void
    {
        DB::table('cars')->insert(['id' => 2110, 'name' => 'ChainOrder', 'engine_id' => null, 'driver_id' => null]);

        // withoutCache() before where()
        $builderA = DB::table('cars')->withoutCache()->where('id', 2110);
        $builderA->get();
        $keyA = (new Hasher(new Reflector($builderA)))->getHash();
        $this->assertCacheMissing($keyA, 'withoutCache->where chain must bypass');

        // where() before withoutCache()
        $builderB = DB::table('cars')->where('id', 2110)->withoutCache();
        $builderB->get();
        $keyB = (new Hasher(new Reflector($builderB)))->getHash();
        $this->assertCacheMissing($keyB, 'where->withoutCache chain must bypass');
    }

    public function testExistsHonorsBypassFlag(): void
    {
        DB::table('cars')->insert(['id' => 2120, 'name' => 'ExistsBypass', 'engine_id' => null, 'driver_id' => null]);

        $builder = DB::table('cars')->where('id', 2120)->withoutCache();
        $this->assertTrue($builder->exists());

        // exists() uses an internal clone-and-get path; verify that path also bypasses the cache
        $reflectorBuilder = DB::table('cars')->where('id', 2120)->limit(1);
        $key = (new Hasher(new Reflector($reflectorBuilder)))->getHash();
        $this->assertCacheMissing($key, 'exists() under withoutCache must not populate Redis');
    }

    public function testNewQueryResetsBypassFlag(): void
    {
        $builder = DB::table('cars')->withoutCache();
        $this->assertTrue($builder->isCacheBypassed());

        $fresh = $builder->newQuery();
        $this->assertInstanceOf(QueryBuilder::class, $fresh);
        $this->assertFalse($fresh->isCacheBypassed(), 'newQuery() must not inherit the bypass flag');
    }
}
