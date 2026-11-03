<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Safety guard: tests must never touch the real database or the live Redis keys.
 */
class TestEnvironmentTest extends TestCase
{
    public function test_tests_use_isolated_services(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame('array', config('cache.default'));
        $this->assertEquals(15, config('database.redis.default.database'));
    }

    public function test_migrations_run_on_the_test_database(): void
    {
        // Checked first, so migrate:fresh can never run on the real database.
        $this->assertSame('sqlite', DB::connection()->getDriverName());

        $this->artisan('migrate:fresh')->assertSuccessful();

        foreach (['stalls', 'sensors', 'parking_sessions', 'sensor_readings'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }
}
