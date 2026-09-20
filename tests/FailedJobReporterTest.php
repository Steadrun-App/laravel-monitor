<?php

namespace Steadrun\LaravelMonitor\Tests;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Steadrun\LaravelMonitor\FailedJobReporter;

class FailedJobReporterTest extends TestCase
{
    public function test_reports_failed_job_body_to_fail_endpoint(): void
    {
        config(['steadrun.base_url' => 'https://steadrun.example']);
        Http::fake();

        $job = Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\SendNewsletter');
        $job->shouldReceive('getQueue')->andReturn('default');

        $event = new JobFailed('database', $job, new RuntimeException('boom'));

        (new FailedJobReporter)->report('abc-123', $event);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://steadrun.example/ping/abc-123/fail'
                && str_contains($request->body(), 'App\\Jobs\\SendNewsletter')
                && str_contains($request->body(), 'boom');
        });
    }

    public function test_does_not_throw_when_steadrun_is_unreachable(): void
    {
        config(['steadrun.base_url' => 'https://steadrun.example']);
        Http::fake(fn () => throw new RuntimeException('connection refused'));

        $job = Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\SendNewsletter');
        $job->shouldReceive('getQueue')->andReturn('default');

        $event = new JobFailed('database', $job, new RuntimeException('boom'));

        (new FailedJobReporter)->report('abc-123', $event);

        $this->expectNotToPerformAssertions();
    }

    public function test_reports_with_short_timeout(): void
    {
        $timeouts = $this->fakeHttpRecordingTimeouts();

        $job = Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\SendNewsletter');
        $job->shouldReceive('getQueue')->andReturn('default');

        (new FailedJobReporter)->report('abc-123', new JobFailed('database', $job, new RuntimeException('boom')));

        $this->assertSame([5], $timeouts->getArrayCopy());
    }
}
