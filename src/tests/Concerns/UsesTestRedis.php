<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\Redis;

trait UsesTestRedis
{
    /**
     * Starts each test with empty test keys.
     * The check runs first, so this can never clear the live keys.
     */
    protected function clearTestRedis(): void
    {
        $this->assertEquals(15, config('database.redis.default.database'));

        Redis::flushdb();
    }
}
