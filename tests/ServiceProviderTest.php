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
    private function fakeFailedJobEvent(): JobFailed
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\SendNewsletter');
        $job->shouldReceive('getQueue')->andReturn('default');

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

    public function test_failed_job_listener_is_not_registered_without_queue_check_uuid(): void
    {
        config(['steadrun.queue_check_uuid' => null]);
        $this->app->register(SteadrunMonitorServiceProvider::class, force: true);

        Http::fake();

        event($this->fakeFailedJobEvent());

        Http::assertNothingSent();
    }
}
