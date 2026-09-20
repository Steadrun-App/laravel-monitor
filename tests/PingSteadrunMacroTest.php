<?php

namespace Steadrun\LaravelMonitor\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PingSteadrunMacroTest extends TestCase
{
    /**
     * @return array<int, string> "METHOD url" отправленных запросов по порядку
     */
    private function sentRequests(): array
    {
        return Http::recorded()
            ->map(fn (array $pair) => $pair[0]->method().' '.$pair[0]->url())
            ->values()
            ->all();
    }

    public function test_pings_start_and_success_urls_when_command_succeeds(): void
    {
        config(['steadrun.base_url' => 'https://steadrun.example']);
        Http::fake();

        $event = $this->app->make(Schedule::class)->command('inspire')->pingSteadrun('abc-123');

        $event->callBeforeCallbacks($this->app);
        $event->exitCode = 0;
        $event->callAfterCallbacks($this->app);

        $this->assertSame([
            'GET https://steadrun.example/ping/abc-123/start',
            'GET https://steadrun.example/ping/abc-123',
        ], $this->sentRequests());
    }

    public function test_pings_start_and_fail_urls_when_command_fails(): void
    {
        config(['steadrun.base_url' => 'https://steadrun.example']);
        Http::fake();

        $event = $this->app->make(Schedule::class)->command('inspire')->pingSteadrun('abc-123');

        $event->callBeforeCallbacks($this->app);
        $event->exitCode = 1;
        $event->callAfterCallbacks($this->app);

        $this->assertSame([
            'GET https://steadrun.example/ping/abc-123/start',
            'GET https://steadrun.example/ping/abc-123/fail',
        ], $this->sentRequests());
    }

    public function test_all_pings_use_short_timeout(): void
    {
        $timeouts = $this->fakeHttpRecordingTimeouts();

        $event = $this->app->make(Schedule::class)->command('inspire')->pingSteadrun('abc-123', withOutput: true);

        $event->callBeforeCallbacks($this->app);
        $event->exitCode = 0;
        $event->callAfterCallbacks($this->app);
        $event->exitCode = 1;
        $event->callAfterCallbacks($this->app);

        $this->assertSame([5, 5, 5], $timeouts->getArrayCopy());
    }

    public function test_unreachable_steadrun_does_not_break_the_task(): void
    {
        Http::fake(fn () => throw new RuntimeException('connection refused'));

        $event = $this->app->make(Schedule::class)->command('inspire')->pingSteadrun('abc-123', withOutput: true);

        $event->callBeforeCallbacks($this->app);
        $event->exitCode = 1;
        $event->callAfterCallbacks($this->app);

        $this->expectNotToPerformAssertions();
    }

    public function test_with_output_sends_output_tail_in_fail_body(): void
    {
        config(['steadrun.base_url' => 'https://steadrun.example']);
        Http::fake();

        $file = tempnam(sys_get_temp_dir(), 'steadrun');
        file_put_contents($file, 'SQLSTATE[HY000]: connection refused');

        $event = $this->app->make(Schedule::class)->command('inspire')->sendOutputTo($file)
            ->pingSteadrun('abc-123', withOutput: true);

        $event->exitCode = 1;
        $event->callAfterCallbacks($this->app);
        unlink($file);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://steadrun.example/ping/abc-123/fail'
            && $request->body() === 'SQLSTATE[HY000]: connection refused');
    }

    public function test_with_output_sends_only_tail_of_long_output_without_breaking_utf8(): void
    {
        Http::fake();

        $file = tempnam(sys_get_temp_dir(), 'steadrun');
        // Начало — 20 КБ «А» (2 байта на символ), хвост — маркер: обрезка должна оставить хвост
        file_put_contents($file, str_repeat('А', 10240).'КОНЕЦ-ВЫВОДА');

        $event = $this->app->make(Schedule::class)->command('inspire')->sendOutputTo($file)
            ->pingSteadrun('abc-123', withOutput: true);

        $event->exitCode = 1;
        $event->callAfterCallbacks($this->app);
        unlink($file);

        Http::assertSent(function (Request $request) {
            $body = $request->body();

            return str_ends_with($body, 'КОНЕЦ-ВЫВОДА')
                && strlen($body) <= 8192 + 3
                && mb_check_encoding($body, 'UTF-8');
        });
    }

    public function test_with_output_and_empty_output_sends_fail_without_body(): void
    {
        config(['steadrun.base_url' => 'https://steadrun.example']);
        Http::fake();

        $file = tempnam(sys_get_temp_dir(), 'steadrun');

        $event = $this->app->make(Schedule::class)->command('inspire')->sendOutputTo($file)
            ->pingSteadrun('abc-123', withOutput: true);

        $event->exitCode = 1;
        $event->callAfterCallbacks($this->app);
        unlink($file);

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'https://steadrun.example/ping/abc-123/fail'
            && $request->body() === '');
    }

    public function test_with_output_keeps_custom_output_file(): void
    {
        $event = $this->app->make(Schedule::class)->command('inspire')->sendOutputTo('/tmp/custom.log')
            ->pingSteadrun('abc-123', withOutput: true);

        $this->assertSame('/tmp/custom.log', $event->output);
    }

    public function test_without_output_does_not_capture_output(): void
    {
        $event = $this->app->make(Schedule::class)->command('inspire')->pingSteadrun('abc-123');

        $this->assertStringNotContainsString('schedule-', (string) $event->output);
    }

    public function test_with_output_sends_real_output_of_failing_command(): void
    {
        config(['steadrun.base_url' => 'https://steadrun.example']);
        Http::fake();

        // Через sh -c: Laravel дописывает редирект вывода в конец команды, и без
        // обёртки он бы относился только к последней команде после «;».
        $event = $this->app->make(Schedule::class)->exec("sh -c 'echo \"boom from command\"; exit 1'")
            ->pingSteadrun('abc-123', withOutput: true);

        $event->run($this->app);

        $this->assertSame([
            'GET https://steadrun.example/ping/abc-123/start',
            'POST https://steadrun.example/ping/abc-123/fail',
        ], $this->sentRequests());

        Http::assertSent(fn (Request $request) => str_contains($request->body(), 'boom from command'));

        @unlink((string) $event->output);
    }
}
