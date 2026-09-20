<?php

namespace Steadrun\LaravelMonitor;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WorkerHeartbeat
{
    private ?int $lastPingedAt = null;

    /**
     * Вызывается на каждой итерации Queue::looping() — событие срабатывает
     * до проверки наличия job, поэтому отражает «жив ли процесс воркера»,
     * а не «есть ли задачи». looping() может срабатывать много раз в
     * секунду — троттлинг обязателен, иначе Steadrun завалит лишними
     * запросами. Состояние хранится в самом объекте: сервис-провайдер
     * регистрирует его как singleton, воркер живёт одним процессом.
     */
    public function ping(string $uuid, int $intervalSeconds): void
    {
        $now = time();

        if ($this->lastPingedAt !== null && $now - $this->lastPingedAt < $intervalSeconds) {
            return;
        }

        $this->lastPingedAt = $now;

        $baseUrl = rtrim((string) config('steadrun.base_url'), '/');

        try {
            Http::timeout(5)->get("{$baseUrl}/ping/{$uuid}");
        } catch (Throwable $e) {
            Log::warning('Steadrun: не удалось отправить heartbeat воркера', ['exception' => $e]);
        }
    }
}
