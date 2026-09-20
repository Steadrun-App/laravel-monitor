<?php

namespace Steadrun\LaravelMonitor;

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Stringable;
use Throwable;

class SchedulerPing
{
    /**
     * start/success/fail-пинги задачи планировщика под структуру
     * ping-эндпоинтов Steadrun. Собственные колбэки вместо штатных
     * pingBefore/pingOnSuccess/pingOnFailure: те ходят через Guzzle Laravel
     * с таймаутом 30 с и не дают его задать, а упавший пинг не должен ни
     * задерживать schedule:run, ни ронять задачу — только логируется.
     *
     * $withOutput — при падении отправить на /fail хвост вывода задачи.
     * onFailureWithOutput включает захват вывода: Laravel начинает писать
     * его в storage/logs/schedule-<hash>.log, если не задан свой sendOutputTo().
     */
    public static function attach(ScheduledEvent $event, string $uuid, bool $withOutput = false): ScheduledEvent
    {
        $ping = static function (string $path, ?string $body = null) use ($uuid): void {
            $baseUrl = rtrim((string) config('steadrun.base_url'), '/');
            $url = "{$baseUrl}/ping/{$uuid}{$path}";

            try {
                $body === null
                    ? Http::timeout(5)->get($url)
                    : Http::timeout(5)->withBody($body, 'text/plain')->post($url);
            } catch (Throwable $e) {
                Log::warning('Steadrun: не удалось отправить пинг планировщика', ['exception' => $e]);
            }
        };

        $event
            ->before(fn () => $ping('/start'))
            ->onSuccess(fn () => $ping(''));

        // Хвост, а не начало: сервер режет тело по началу, а причина падения
        // в выводе команды обычно в конце. mb_strcut не разрезает UTF-8.
        return $withOutput
            ? $event->onFailureWithOutput(function (Stringable $output) use ($ping) {
                $output = (string) $output;

                $ping('/fail', $output === '' ? null : mb_strcut($output, max(0, strlen($output) - 8192)));
            })
            : $event->onFailure(fn () => $ping('/fail'));
    }
}
