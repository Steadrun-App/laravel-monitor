<?php

namespace Steadrun\LaravelMonitor;

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class SteadrunMonitorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/steadrun.php', 'steadrun');

        $this->app->singleton(WorkerHeartbeat::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/steadrun.php' => $this->app->configPath('steadrun.php'),
        ], 'steadrun-config');

        $this->registerScheduleMacro();
        $this->registerFailedJobListener();
        $this->registerWorkerHeartbeat();
    }

    /**
     * ->pingSteadrun($uuid) на Schedule\Event — комбинация штатных
     * pingBefore/pingOnSuccess/pingOnFailure под структуру ping-эндпоинтов
     * Steadrun, чтобы не собирать три вызова вручную.
     */
    private function registerScheduleMacro(): void
    {
        ScheduledEvent::macro('pingSteadrun', function (string $uuid) {
            /** @var ScheduledEvent $this */
            $baseUrl = rtrim((string) config('steadrun.base_url'), '/');

            return $this
                ->pingBefore("{$baseUrl}/ping/{$uuid}/start")
                ->pingOnSuccess("{$baseUrl}/ping/{$uuid}")
                ->pingOnFailure("{$baseUrl}/ping/{$uuid}/fail");
        });
    }

    /**
     * Очередь может быть сопоставлена со своим check (steadrun.queue_check_uuids)
     * — иначе падение алертит на общий steadrun.queue_check_uuid, если задан.
     */
    private function registerFailedJobListener(): void
    {
        $defaultUuid = config('steadrun.queue_check_uuid');
        $queueUuids = config('steadrun.queue_check_uuids', []);

        if (! $defaultUuid && $queueUuids === []) {
            return;
        }

        Queue::failing(function (JobFailed $event) use ($defaultUuid, $queueUuids): void {
            $uuid = $queueUuids[$event->job->getQueue()] ?? $defaultUuid;

            if (! $uuid) {
                return;
            }

            $this->app->make(FailedJobReporter::class)->report($uuid, $event);
        });
    }

    /**
     * Queue::looping() срабатывает на каждой итерации цикла воркера, до
     * проверки наличия job — единственный способ отличить «воркер жив, но
     * очередь пуста» от «воркер упал», не завязываясь на факт обработки job'а.
     */
    private function registerWorkerHeartbeat(): void
    {
        $uuid = config('steadrun.worker_heartbeat_uuid');

        if (! $uuid) {
            return;
        }

        $intervalSeconds = (int) config('steadrun.worker_heartbeat_interval_seconds', 60);

        Queue::looping(function () use ($uuid, $intervalSeconds): void {
            $this->app->make(WorkerHeartbeat::class)->ping($uuid, $intervalSeconds);
        });
    }
}
