<?php

namespace Steadrun\LaravelMonitor;

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
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
        $this->registerScheduledChecks();
    }

    /**
     * ->pingSteadrun($uuid, withOutput: false) на Schedule\Event — см. SchedulerPing.
     */
    private function registerScheduleMacro(): void
    {
        ScheduledEvent::macro('pingSteadrun', function (string $uuid, bool $withOutput = false) {
            /** @var ScheduledEvent $this */
            return SchedulerPing::attach($this, $uuid, $withOutput);
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

    /**
     * Задачи расписания, которые пакет регистрирует сам: сквозная проверка
     * очереди (QueueProbeJob) и cron-heartbeat — пустая задача, чей пинг
     * говорит «schedule:run тикает». Частота зашита, период check'а на
     * сервере настраивается под неё.
     */
    private function registerScheduledChecks(): void
    {
        $probeUuid = config('steadrun.queue_probe_uuid');
        $cronUuid = config('steadrun.cron_check_uuid');

        if (! $probeUuid && ! $cronUuid) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) use ($probeUuid, $cronUuid): void {
            if ($probeUuid) {
                $schedule->job(new QueueProbeJob($probeUuid))->everyFiveMinutes();
            }

            if ($cronUuid) {
                SchedulerPing::attach(
                    $schedule->call(fn () => null)->name('steadrun-cron-heartbeat')->everyFiveMinutes(),
                    $cronUuid,
                );
            }
        });
    }
}
