<?php

namespace Steadrun\LaravelMonitor\Tests;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Steadrun\LaravelMonitor\QueueProbeJob;
use Steadrun\LaravelMonitor\SteadrunMonitorServiceProvider;

class ScheduledChecksTest extends TestCase
{
    /**
     * @return array<int, Event>
     */
    private function scheduledEvents(): array
    {
        $this->app->register(SteadrunMonitorServiceProvider::class, force: true);

        return $this->app->make(Schedule::class)->events();
    }

    public function test_nothing_is_scheduled_without_uuids(): void
    {
        config(['steadrun.queue_probe_uuid' => null, 'steadrun.cron_check_uuid' => null]);

        $this->assertSame([], $this->scheduledEvents());
    }

    public function test_queue_probe_is_scheduled_every_five_minutes_and_dispatches_the_job(): void
    {
        config(['steadrun.queue_probe_uuid' => 'probe-uuid']);
        Bus::fake();

        $events = $this->scheduledEvents();

        $this->assertCount(1, $events);
        $this->assertSame('*/5 * * * *', $events[0]->expression);

        $events[0]->run($this->app);

        Bus::assertDispatched(QueueProbeJob::class, fn (QueueProbeJob $job) => $job->uuid === 'probe-uuid');
    }

    public function test_cron_heartbeat_is_scheduled_and_pings_on_success(): void
    {
        config(['steadrun.cron_check_uuid' => 'cron-uuid', 'steadrun.base_url' => 'https://steadrun.example']);
        Http::fake();

        $events = $this->scheduledEvents();

        $this->assertCount(1, $events);
        $this->assertSame('steadrun-cron-heartbeat', $events[0]->description);
        $this->assertSame('*/5 * * * *', $events[0]->expression);

        $events[0]->run($this->app);

        Http::assertSent(fn ($request) => $request->url() === 'https://steadrun.example/ping/cron-uuid/start');
        Http::assertSent(fn ($request) => $request->url() === 'https://steadrun.example/ping/cron-uuid');
    }

    public function test_both_checks_are_scheduled_together(): void
    {
        config(['steadrun.queue_probe_uuid' => 'probe-uuid', 'steadrun.cron_check_uuid' => 'cron-uuid']);

        $this->assertCount(2, $this->scheduledEvents());
    }

    public function test_probe_job_pings_check_with_short_timeout(): void
    {
        config(['steadrun.base_url' => 'https://steadrun.example']);
        $timeouts = $this->fakeHttpRecordingTimeouts();

        (new QueueProbeJob('probe-uuid'))->handle();

        Http::assertSent(fn ($request) => $request->url() === 'https://steadrun.example/ping/probe-uuid');
        $this->assertSame([5], $timeouts->getArrayCopy());
    }

    public function test_probe_job_does_not_throw_when_steadrun_is_unreachable(): void
    {
        Http::fake(fn () => throw new RuntimeException('connection refused'));

        (new QueueProbeJob('probe-uuid'))->handle();

        $this->expectNotToPerformAssertions();
    }
}
