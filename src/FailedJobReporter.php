<?php

namespace Steadrun\LaravelMonitor;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FailedJobReporter
{
    /**
     * Отправляет тело исключения на /ping/{uuid}/fail. Ошибки самой отправки
     * (Steadrun недоступен и т.п.) не должны ронять приложение клиента —
     * только логируются.
     */
    public function report(string $uuid, JobFailed $event): void
    {
        $baseUrl = rtrim((string) config('steadrun.base_url'), '/');

        $body = sprintf(
            "Job: %s\nConnection: %s\nQueue: %s\n\n%s",
            $event->job->resolveName(),
            $event->connectionName,
            $event->job->getQueue(),
            (string) $event->exception,
        );

        try {
            Http::timeout(5)->withBody($body, 'text/plain')->post("{$baseUrl}/ping/{$uuid}/fail");
        } catch (Throwable $e) {
            Log::warning('Steadrun: не удалось отправить failed-job пинг', ['exception' => $e]);
        }
    }
}
