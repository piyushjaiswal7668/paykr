<?php

declare(strict_types=1);

use App\Domain\Observers\Services\SidecarClient;
use App\Domain\Platform\Services\ClockStatus;
use Illuminate\Support\Facades\Cache;

it('treats malformed clock state as stopped', function () {
    Cache::put('paykr:clock:status', 'corrupt');
    expect(app(ClockStatus::class)->read())->toBe(['healthy' => false, 'status' => 'STOPPED', 'heartbeat' => null, 'recovery' => null]);
});

it('reports an unavailable clock when the cache cannot be read', function () {
    Cache::shouldReceive('get')->once()->with('paykr:clock:status', [])->andThrow(new RuntimeException('connection unavailable'));
    expect(app(ClockStatus::class)->read())->toBe(['status' => 'UNAVAILABLE', 'healthy' => false, 'heartbeat' => null, 'recovery' => null]);
});

it('degrades safely when both the sidecar request and circuit cache fail', function () {
    config(['paykr.sidecars.whatsapp_enabled' => true]);
    Cache::shouldReceive('get')->once()->andThrow(new RuntimeException('cache offline'));
    Cache::shouldReceive('put')->once()->andThrow(new RuntimeException('cache offline'));
    expect(app(SidecarClient::class)->request('whatsapp', '/status'))->toBeNull();
});
