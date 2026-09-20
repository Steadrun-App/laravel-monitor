<?php

namespace Steadrun\LaravelMonitor\Tests;

use ArrayObject;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Steadrun\LaravelMonitor\SteadrunMonitorServiceProvider;

abstract class TestCase extends BaseTestCase
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [SteadrunMonitorServiceProvider::class];
    }

    /**
     * Http::assertSent() не показывает опции клиента, а fake-колбэк получает
     * их вторым аргументом — так проверяем таймаут запроса.
     *
     * @return ArrayObject<int, mixed> таймауты отправленных запросов
     */
    protected function fakeHttpRecordingTimeouts(): ArrayObject
    {
        $timeouts = new ArrayObject;

        Http::fake(function ($request, $options) use ($timeouts) {
            $timeouts[] = $options['timeout'] ?? null;

            return Http::response();
        });

        return $timeouts;
    }
}
