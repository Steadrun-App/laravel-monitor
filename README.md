# Steadrun Laravel Monitor

[Русский](#русский) | [English](#english)

---

## Русский

Клиент [Steadrun](https://steadrun.ru) для Laravel — мониторинг фоновой инфраструктуры по принципу dead man's switch: детекция «тихих» отказов cron-задач и очередей/воркеров. Пакет даёт хелпер пинга для планировщика и автоматические алерты о упавших job'ах.

### Установка

```bash
composer require steadrun/laravel-monitor
```

### Cron-задачи (Scheduler)

```php
$schedule->command('emails:send')->daily()->pingSteadrun('{uuid-check-а}');
```

`pingSteadrun()` — макрос на `Illuminate\Console\Scheduling\Event`: пинг `/start` перед задачей, `/ping/{uuid}` при успехе и `/fail` при падении. Запросы идут с таймаутом 5 с; сбой пинга пишется в лог (`Log::warning`) и не мешает ни задаче, ни `schedule:run`.

**Причина падения в алерте** (опционально, по умолчанию выключено):

```php
$schedule->command('emails:send')->daily()->pingSteadrun('{uuid-check-а}', withOutput: true);
```

При падении задачи на `/fail` уходит хвост её вывода — последние 8 КБ. Что нужно знать перед включением:

- вывод упавшей задачи попадает в Steadrun, а оттуда — в email/Telegram владельца check'а и в кабинет. Не включайте для задач, чей вывод может содержать секреты или персональные данные;
- Laravel начинает писать вывод этой задачи в `storage/logs/schedule-<hash>.log` (файл перезаписывается при каждом запуске). Если вы задали свой `sendOutputTo()`/`appendOutputTo()` — он не переопределяется.

**Cron без своей задачи**: если нужно просто знать, что `schedule:run` тикает, добавьте в `.env`

```
STEADRUN_CRON_UUID=uuid-вашего-check-а
```

— пакет сам зарегистрирует пустую задачу `steadrun-cron-heartbeat` с пингом каждые 5 минут. Период check'а в Steadrun настройте под эти 5 минут.

### Очереди (алерты о упавших job'ах)

Добавьте в `.env`:

```
STEADRUN_QUEUE_UUID=uuid-вашего-check-а
```

После этого любой упавший job в любой очереди автоматически отправит алерт на указанный check — без дополнительного кода.

**Несколько очередей, разные checks**: если падения в разных очередях должны алертить на разные checks — опубликуйте конфиг (см. «Конфигурация» ниже) и заполните `queue_check_uuids` в `config/steadrun.php`:

```php
'queue_check_uuid' => env('STEADRUN_QUEUE_UUID'), // общий fallback для остальных очередей

'queue_check_uuids' => [
    'emails' => env('STEADRUN_QUEUE_UUID_EMAILS'),
    'imports' => env('STEADRUN_QUEUE_UUID_IMPORTS'),
],
```

Очередь, которой нет в `queue_check_uuids`, попадает под общий `queue_check_uuid`. Если общего UUID тоже нет — падения в такой очереди не алертятся.

**Какие данные уходят в Steadrun**: класс job'а, соединение, очередь и исключение целиком — текст и трейс. Сервер обрезает тело до 10 КБ, сохраняет его и присылает владельцу check'а в email и Telegram. Тексты исключений могут содержать пароли из DSN, токены и персональные данные (например, email пользователя в сообщении) — учитывайте это, если такие job'ы у вас есть. Клиентской обрезки или фильтрации пакет не делает.

### Heartbeat воркера (жив ли сам процесс)

Алерт о упавшем job'е не поможет, если воркер целиком встал (OOM, потеря соединения с очередью) — тогда просто не будет ни новых job'ов, ни падений. Добавьте в `.env`:

```
STEADRUN_WORKER_HEARTBEAT_UUID=uuid-check-а-типа-queue
```

Пакет подписывается на `Queue::looping()` — событие Laravel срабатывает на каждой итерации цикла воркера, до проверки наличия job, и отправляет пинг на check не чаще раза в `STEADRUN_WORKER_HEARTBEAT_INTERVAL` секунд (по умолчанию 60). Так отслеживается именно «жив ли процесс», а не «есть ли задачи» — воркер с пустой очередью продолжает пинговать.

**Границы heartbeat'а.** Он отвечает только на вопрос «процесс воркера жив». Он не заметит, что очередь отстаёт на часы, или что воркер слушает не ту очередь — для этого есть сквозная проверка (см. ниже). Два нюанса, из-за которых можно получить ложный `down`:

- **Режим обслуживания.** При `php artisan down` воркер не берёт job'ы, а `Queue::looping()` не срабатывает — heartbeat замолкает. Перед плановым `down` ставьте check на паузу в кабинете Steadrun.
- **Долгие job'ы.** `Queue::looping()` срабатывает только между job'ами. Пока воркер выполняет один долгий job (импорт, отчёт), пингов нет. Grace-период у heartbeat-check'а должен быть не меньше самого долгого job'а очереди.

Троттлинг — на процесс, не общий: если Supervisor поднимает несколько процессов воркера на одну очередь (`numprocs > 1`), каждый пингует независимо на тот же UUID, суммарная частота = `numprocs × (1 / STEADRUN_WORKER_HEARTBEAT_INTERVAL)`. При типичных значениях (единицы процессов, интервал 60с) это далеко от серверного лимита `/ping/*` (120 запросов/мин на один check).

### Сквозная проверка очереди (не отстаёт ли очередь)

Heartbeat не видит бэклог и воркер, слушающий не ту очередь. Эти случаи ловит job, положенный в общую очередь: он выполнится, только когда до него дойдёт воркер. Добавьте в `.env`:

```
STEADRUN_QUEUE_PROBE_UUID=uuid-check-а-типа-queue
```

Пакет каждые 5 минут ставит в планировщик `QueueProbeJob`: он попадает в очередь по умолчанию и пингует check, когда его берёт воркер. При бэклоге пинг опаздывает, при воркере на другой очереди — не приходит. Нужен работающий `schedule:run`; период check'а в Steadrun настройте под 5 минут. Долгий job в очереди задержит и проверку (то же ограничение, что у heartbeat'а). Класс публичный — при необходимости поставьте его в расписание сами, с другой частотой:

```php
$schedule->job(new \Steadrun\LaravelMonitor\QueueProbeJob('{uuid-check-а}'))->everyMinute();
```

### Обновление с v0.2.0

- Пинги планировщика (`pingSteadrun()`) идут через фасад `Http`, а не через `GuzzleHttp\ClientInterface` из контейнера. Если вы подменяли Guzzle (например, прокси для исходящих запросов), на пинги это больше не действует; зато в ваших тестах работает `Http::fake()`.
- Неудавшийся пинг планировщика пишется в лог как warning и не передаётся в `ExceptionHandler`.
- Все запросы пакета идут с таймаутом 5 с (раньше — 30 с по умолчанию).

### Конфигурация

```bash
php artisan vendor:publish --tag=steadrun-config
```

- `STEADRUN_BASE_URL` — адрес Steadrun, по умолчанию `https://steadrun.ru`. Переопределите, если используете self-hosted инстанс.
- `STEADRUN_QUEUE_UUID` — UUID check'а для алертов о упавших job'ах (общий, для очередей вне `queue_check_uuids`).
- `queue_check_uuids` — сопоставление имени очереди с UUID check'а, для случая нескольких очередей с раздельными алертами (задаётся в опубликованном конфиге, отдельных env-переменных для этого массива нет).
- `STEADRUN_WORKER_HEARTBEAT_UUID` — UUID check'а для heartbeat воркера (см. «Heartbeat воркера» выше).
- `STEADRUN_WORKER_HEARTBEAT_INTERVAL` — минимальный интервал между пингами воркера, в секундах (по умолчанию 60).
- `STEADRUN_QUEUE_PROBE_UUID` — UUID check'а для сквозной проверки очереди (см. «Сквозная проверка очереди»).
- `STEADRUN_CRON_UUID` — UUID check'а для cron-heartbeat'а (см. «Cron-задачи»).

### Требования

PHP 8.1+, Laravel 10/11/12/13.

### Лицензия

MIT.

---

## English

[Steadrun](https://steadrun.ru) client for Laravel: dead man's switch monitoring for background infrastructure — detects silent failures of cron jobs and queue workers. Provides a Scheduler ping helper and automatic failed-job alerts.

### Install

```bash
composer require steadrun/laravel-monitor
```

### Cron jobs (Scheduler)

```php
$schedule->command('emails:send')->daily()->pingSteadrun('{check-uuid}');
```

`pingSteadrun()` is a macro on `Illuminate\Console\Scheduling\Event`: a `/start` ping before the task, `/ping/{uuid}` on success and `/fail` on failure. Requests use a 5 s timeout; a failed ping is logged (`Log::warning`) and never affects the task or `schedule:run`.

**Failure reason in the alert** (optional, off by default):

```php
$schedule->command('emails:send')->daily()->pingSteadrun('{check-uuid}', withOutput: true);
```

When the task fails, the tail of its output — the last 8 KB — is sent to `/fail`. Before enabling it, know that:

- the failed task's output goes to Steadrun and from there to the check owner's email/Telegram and the dashboard. Don't enable it for tasks whose output may contain secrets or personal data;
- Laravel starts writing this task's output to `storage/logs/schedule-<hash>.log` (overwritten on every run). A `sendOutputTo()`/`appendOutputTo()` you set yourself is not overridden.

**Cron without a task of your own**: if you just need to know that `schedule:run` is ticking, add to `.env`

```
STEADRUN_CRON_UUID=your-check-uuid
```

— the package registers an empty `steadrun-cron-heartbeat` task that pings every 5 minutes. Set the check's period in Steadrun to match those 5 minutes.

### Queue workers (failed-job alerts)

Add to `.env`:

```
STEADRUN_QUEUE_UUID=your-check-uuid
```

Any failed job on any queue will automatically send an alert to that check — no other code required.

**Multiple queues, separate checks**: if failures on different queues should alert different checks, publish the config (see "Configuration" below) and fill in `queue_check_uuids` in `config/steadrun.php`:

```php
'queue_check_uuid' => env('STEADRUN_QUEUE_UUID'), // shared fallback for the rest

'queue_check_uuids' => [
    'emails' => env('STEADRUN_QUEUE_UUID_EMAILS'),
    'imports' => env('STEADRUN_QUEUE_UUID_IMPORTS'),
],
```

A queue not listed in `queue_check_uuids` falls back to `queue_check_uuid`. If that's not set either, failures on that queue aren't reported.

**What is sent to Steadrun**: the job class, connection, queue and the full exception — message and stack trace. The server truncates the body to 10 KB, stores it and sends it to the check owner by email and Telegram. Exception messages can contain DSN passwords, tokens and personal data (for example a user's email inside the message) — keep that in mind if you have such jobs. The package does no client-side truncation or filtering.

### Worker heartbeat (is the process itself alive)

A failed-job alert doesn't help if the worker itself has died (OOM, lost connection to the queue) — then there are simply no new jobs and no failures either. Add to `.env`:

```
STEADRUN_WORKER_HEARTBEAT_UUID=your-queue-type-check-uuid
```

The package hooks into `Queue::looping()` — a Laravel event fired on every iteration of the worker's loop, before it checks for a job — and pings that check at most once every `STEADRUN_WORKER_HEARTBEAT_INTERVAL` seconds (default 60). This tracks whether the process itself is alive, not whether there's work to do — a worker with an empty queue keeps pinging.

**Limits of the heartbeat.** It only answers "is the worker process alive". It won't notice a queue that is hours behind, or a worker listening on the wrong queue — that is what the end-to-end probe (below) is for. Two cases can produce a false `down`:

- **Maintenance mode.** With `php artisan down` the worker doesn't take jobs and `Queue::looping()` doesn't fire — the heartbeat goes silent. Pause the check in the Steadrun dashboard before a planned `down`.
- **Long jobs.** `Queue::looping()` fires only between jobs. While the worker runs one long job (an import, a report) there are no pings. The heartbeat check's grace period must be at least as long as your longest queue job.

Throttling is per process, not shared: if Supervisor runs multiple worker processes on the same queue (`numprocs > 1`), each one pings independently against the same UUID, so the combined rate is `numprocs × (1 / STEADRUN_WORKER_HEARTBEAT_INTERVAL)`. At typical values (a handful of processes, a 60s interval) this stays well under the server's `/ping/*` rate limit (120 requests/min per check).

### Queue round-trip probe (is the queue keeping up)

The heartbeat can't see a backlog or a worker listening on the wrong queue. A job put on the shared queue catches both: it runs only when a worker actually reaches it. Add to `.env`:

```
STEADRUN_QUEUE_PROBE_UUID=your-queue-type-check-uuid
```

Every 5 minutes the package schedules a `QueueProbeJob`: it lands on the default queue and pings the check when a worker picks it up. With a backlog the ping arrives late; with a worker on another queue it never arrives. This needs a working `schedule:run`; set the check's period in Steadrun to match 5 minutes. A long job on the queue delays the probe too (same limit as the heartbeat). The class is public — schedule it yourself at a different frequency if needed:

```php
$schedule->job(new \Steadrun\LaravelMonitor\QueueProbeJob('{check-uuid}'))->everyMinute();
```

### Upgrading from v0.2.0

- Scheduler pings (`pingSteadrun()`) now go through the `Http` facade instead of the container's `GuzzleHttp\ClientInterface`. If you swapped Guzzle (for example to route outbound traffic through a proxy), that no longer applies to these pings; in return, `Http::fake()` works in your tests.
- A failed scheduler ping is logged as a warning and is not passed to the `ExceptionHandler`.
- All package requests use a 5 s timeout (previously the 30 s default).

### Configuration

```bash
php artisan vendor:publish --tag=steadrun-config
```

- `STEADRUN_BASE_URL` — Steadrun instance URL, defaults to `https://steadrun.ru`. Override if self-hosting.
- `STEADRUN_QUEUE_UUID` — check UUID for failed-job alerts (shared, for queues not in `queue_check_uuids`).
- `queue_check_uuids` — queue name → check UUID mapping for separate per-queue alerts (set in the published config file; no dedicated env vars for this array).
- `STEADRUN_WORKER_HEARTBEAT_UUID` — check UUID for the worker heartbeat (see "Worker heartbeat" above).
- `STEADRUN_WORKER_HEARTBEAT_INTERVAL` — minimum interval between worker pings, in seconds (default 60).
- `STEADRUN_QUEUE_PROBE_UUID` — check UUID for the queue round-trip probe (see "Queue round-trip probe").
- `STEADRUN_CRON_UUID` — check UUID for the cron heartbeat (see "Cron jobs").

### Requirements

PHP 8.1+, Laravel 10/11/12/13.

### License

MIT.