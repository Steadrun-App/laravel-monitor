<?php

namespace Steadrun\LaravelMonitor;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class QueueProbeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $uuid) {}

    /**
     * Сквозная проверка очереди: job проходит через общую очередь и
     * пингует check только когда до него дошёл воркер — поэтому пинг
     * опаздывает при бэклоге и не приходит, если воркер слушает не ту
     * очередь. Исключение не пробрасываем: упавший probe стал бы
     * failed-job'ом и ложным алертом на тот же check.
     */
    public function handle(): void
    {
        $baseUrl = rtrim((string) config('steadrun.base_url'), '/');

        try {
            Http::timeout(5)->get("{$baseUrl}/ping/{$this->uuid}");
        } catch (Throwable $e) {
            Log::warning('Steadrun: не удалось отправить пинг сквозной проверки очереди', ['exception' => $e]);
        }
    }
}
