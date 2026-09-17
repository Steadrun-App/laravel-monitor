<?php

namespace Steadrun\LaravelMonitor\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Scheduling\Schedule;

class PingSteadrunMacroTest extends TestCase
{
    /**
     * Планировщик шлёт пинги через сырой Guzzle-клиент из контейнера
     * (Illuminate\Console\Scheduling\Event::getHttpClient()), а не через
     * фасад Http — поэтому вместо Http::fake() подменяем сам GuzzleHttp\ClientInterface
     * и читаем историю запросов из него.
     *
     * ArrayObject, а не обычный массив — Middleware::history() принимает
     * контейнер по ссылке, а возврат обычного массива из метода эту ссылку
     * разрывает (return копирует значение).
     */
    private function fakeGuzzle(int $responses = 3): \ArrayObject
    {
        $history = new \ArrayObject;
        $stack = HandlerStack::create(new MockHandler(array_fill(0, $responses, new Response(200))));
        $stack->push(Middleware::history($history));

        $this->app->instance(ClientInterface::class, new Client(['handler' => $stack]));

        return $history;
    }

    public function test_ping_steadrun_pings_before_and_success_urls_when_command_succeeds(): void
    {
        config(['steadrun.base_url' => 'https://steadrun.example']);
        $history = $this->fakeGuzzle();

        $schedule = $this->app->make(Schedule::class);
        $event = $schedule->command('inspire')->pingSteadrun('abc-123');

        $event->exitCode = 0;
        $event->callBeforeCallbacks($this->app);
        $event->callAfterCallbacks($this->app);

        $urls = array_map(fn (array $t) => (string) $t['request']->getUri(), $history->getArrayCopy());

        $this->assertContains('https://steadrun.example/ping/abc-123/start', $urls);
        $this->assertContains('https://steadrun.example/ping/abc-123', $urls);
        $this->assertNotContains('https://steadrun.example/ping/abc-123/fail', $urls);
    }

    public function test_ping_steadrun_pings_fail_url_when_command_fails(): void
    {
        config(['steadrun.base_url' => 'https://steadrun.example']);
        $history = $this->fakeGuzzle(2);

        $schedule = $this->app->make(Schedule::class);
        $event = $schedule->command('inspire')->pingSteadrun('abc-123');

        $event->exitCode = 1;
        $event->callBeforeCallbacks($this->app);
        $event->callAfterCallbacks($this->app);

        $urls = array_map(fn (array $t) => (string) $t['request']->getUri(), $history->getArrayCopy());

        $this->assertContains('https://steadrun.example/ping/abc-123/start', $urls);
        $this->assertContains('https://steadrun.example/ping/abc-123/fail', $urls);
        $this->assertNotContains('https://steadrun.example/ping/abc-123', $urls);
    }
}
