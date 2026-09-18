<?php

namespace Steadrun\LaravelMonitor\Tests;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Steadrun\LaravelMonitor\SteadrunMonitorServiceProvider;

class ServiceProviderTest extends TestCase
{
    private function fakeFailedJobEvent(string $queue = 'default'): JobFailed
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\SendNewsletter');
        $job->shouldReceive('getQueue')->andReturn($queue);

        return new JobFailed('database', $job, new RuntimeException('boom'));
    }

    public function test_failed_job_listener_is_registered_when_queue_check_uuid_is_configured(): void
    {
        config(['steadrun.queue_check_uuid' => 'queue-uuid', 'steadrun.base_url' => 'https://steadrun.example']);
        $this->app->register(SteadrunMonitorServiceProvider::class, force: true);

        Http::fake();

        event($this->fakeFailedJobEvent());

        Http::assertSent(fn ($request) => $request->url() === 'https://steadrun.example/ping/queue-uuid/fail');
    }

    public function test_failed_job_listener_is_not_registered_without_any_queue_check_uuid(): void
    {
        config(['steadrun.queue_check_uuid' => null, 'steadrun.queue_check_uuids' => []]);
        $this->app->register(SteadrunMonitorServiceProvider::class, force: true);

        Http::fake();

        event($this->fakeFailedJobEvent());

        Http::assertNothingSent();
    }

    public function test_failed_job_uses_queue_specific_uuid_when_mapped(): void
    {
        config([
            'steadrun.queue_check_uuid' => 'default-uuid',
            'steadrun.queue_check_uuids' => ['emails' => 'emails-uuid'],
            'steadrun.base_url' => 'https://steadrun.example',
        ]);
        $this->app->register(SteadrunMonitorServiceProvider::class, force: true);

        Http::fake();

        event($this->fakeFailedJobEvent(queue: 'emails'));

        Http::assertSent(fn ($request) => $request->url() === 'https://steadrun.example/ping/emails-uuid/fail');
    }

    public function test_failed_job_falls_back_to_default_uuid_for_unmapped_queue(): void
    {
        config([
            'steadrun.queue_check_uuid' => 'default-uuid',
            'steadrun.queue_check_uuids' => ['emails' => 'emails-uuid'],
            'steadrun.base_url' => 'https://steadrun.example',
        ]);
        $this->app->register(SteadrunMonitorServiceProvider::class, force: true);

        Http::fake();

        event($this->fakeFailedJobEvent(queue: 'imports'));

        Http::assertSent(fn ($request) => $request->url() === 'https://steadrun.example/ping/default-uuid/fail');
    }

    public function test_failed_job_on_unmapped_queue_without_default_is_not_sent(): void
    {
        config([
            'steadrun.queue_check_uuid' => null,
            'steadrun.queue_check_uuids' => ['emails' => 'emails-uuid'],
        ]);
        $this->app->register(SteadrunMonitorServiceProvider::class, force: true);

        Http::fake();

        event($this->fakeFailedJobEvent(queue: 'imports'));

        Http::assertNothingSent();
    }
}
