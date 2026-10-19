<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Cache\RedisStore;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Redis survives application refreshes; each test needs its own cache/rate-limit keys.
        $store = Cache::store()->getStore();
        if ($store instanceof RedisStore) {
            $store->setPrefix($store->getPrefix().'test:'.bin2hex(random_bytes(12)).':');
        }
    }
}
