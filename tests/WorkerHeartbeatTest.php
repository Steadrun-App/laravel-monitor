<?php

namespace Steadrun\LaravelMonitor\Tests;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Steadrun\LaravelMonitor\WorkerHeartbeat;

class WorkerHeartbeatTest extends TestCase
{
    public function test_pings_success_endpoint(): void
    {
        config(['steadrun.base_url' => 'https://steadrun.example']);
        Http::fake();

        (new WorkerHeartbeat)->ping('abc-123', 60);

        Http::assertSent(fn ($request) => $request->url() === 'https://steadrun.example/ping/abc-123');
    }

    public function test_throttles_repeated_calls_within_interval(): void
    {
        config(['steadrun.base_url' => 'https://steadrun.example']);
        Http::fake();

        $heartbeat = new WorkerHeartbeat;
        $heartbeat->ping('abc-123', 60);
        $heartbeat->ping('abc-123', 60);
        $heartbeat->ping('abc-123', 60);

        Http::assertSentCount(1);
    }

    public function test_does_not_throw_when_steadrun_is_unreachable(): void
    {
        config(['steadrun.base_url' => 'https://steadrun.example']);
        Http::fake(fn () => throw new RuntimeException('connection refused'));

        (new WorkerHeartbeat)->ping('abc-123', 60);

        $this->expectNotToPerformAssertions();
    }

    public function test_pings_with_short_timeout(): void
    {
        $timeouts = $this->fakeHttpRecordingTimeouts();

        (new WorkerHeartbeat)->ping('abc-123', 60);

        $this->assertSame([5], $timeouts->getArrayCopy());
    }
}
