<?php

namespace Steadrun\LaravelMonitor\Tests;

use Illuminate\Foundation\Application;
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
}
