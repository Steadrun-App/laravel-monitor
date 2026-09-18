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

`pingSteadrun()` — макрос на `Illuminate\Console\Scheduling\Event`, комбинирующий штатные `pingBefore`/`pingOnSuccess`/`pingOnFailure` под структуру ping-эндпоинтов Steadrun. Отдельно оборачивать вызовы не нужно.

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

### Heartbeat воркера (жив ли сам процесс)

Алерт о упавшем job'е не поможет, если воркер целиком встал (OOM, потеря соединения с очередью) — тогда просто не будет ни новых job'ов, ни падений. Добавьте в `.env`:

```
STEADRUN_WORKER_HEARTBEAT_UUID=uuid-check-а-типа-queue
```

Пакет подписывается на `Queue::looping()` — событие Laravel срабатывает на каждой итерации цикла воркера, до проверки наличия job, и отправляет пинг на check не чаще раза в `STEADRUN_WORKER_HEARTBEAT_INTERVAL` секунд (по умолчанию 60). Так отслеживается именно «жив ли процесс», а не «есть ли задачи» — воркер с пустой очередью продолжает пинговать.

Троттлинг — на процесс, не общий: если Supervisor поднимает несколько процессов воркера на одну очередь (`numprocs > 1`), каждый пингует независимо на тот же UUID, суммарная частота = `numprocs × (1 / STEADRUN_WORKER_HEARTBEAT_INTERVAL)`. При типичных значениях (единицы процессов, интервал 60с) это далеко от серверного лимита `/ping/*` (120 запросов/мин на один check).

### Конфигурация

```bash
php artisan vendor:publish --tag=steadrun-config
```

- `STEADRUN_BASE_URL` — адрес Steadrun, по умолчанию `https://steadrun.ru`. Переопределите, если используете self-hosted инстанс.
- `STEADRUN_QUEUE_UUID` — UUID check'а для алертов о упавших job'ах (общий, для очередей вне `queue_check_uuids`).
- `queue_check_uuids` — сопоставление имени очереди с UUID check'а, для случая нескольких очередей с раздельными алертами (задаётся в опубликованном конфиге, отдельных env-переменных для этого массива нет).
- `STEADRUN_WORKER_HEARTBEAT_UUID` — UUID check'а для heartbeat воркера (см. «Heartbeat воркера» выше).
- `STEADRUN_WORKER_HEARTBEAT_INTERVAL` — минимальный интервал между пингами воркера, в секундах (по умолчанию 60).

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

`pingSteadrun()` is a macro on `Illuminate\Console\Scheduling\Event` that combines Laravel's built-in `pingBefore`/`pingOnSuccess`/`pingOnFailure` to match Steadrun's ping endpoint structure — no manual wiring needed.

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

### Worker heartbeat (is the process itself alive)

A failed-job alert doesn't help if the worker itself has died (OOM, lost connection to the queue) — then there are simply no new jobs and no failures either. Add to `.env`:

```
STEADRUN_WORKER_HEARTBEAT_UUID=your-queue-type-check-uuid
```

The package hooks into `Queue::looping()` — a Laravel event fired on every iteration of the worker's loop, before it checks for a job — and pings that check at most once every `STEADRUN_WORKER_HEARTBEAT_INTERVAL` seconds (default 60). This tracks whether the process itself is alive, not whether there's work to do — a worker with an empty queue keeps pinging.

Throttling is per process, not shared: if Supervisor runs multiple worker processes on the same queue (`numprocs > 1`), each one pings independently against the same UUID, so the combined rate is `numprocs × (1 / STEADRUN_WORKER_HEARTBEAT_INTERVAL)`. At typical values (a handful of processes, a 60s interval) this stays well under the server's `/ping/*` rate limit (120 requests/min per check).

### Configuration

```bash
php artisan vendor:publish --tag=steadrun-config
```

- `STEADRUN_BASE_URL` — Steadrun instance URL, defaults to `https://steadrun.ru`. Override if self-hosting.
- `STEADRUN_QUEUE_UUID` — check UUID for failed-job alerts (shared, for queues not in `queue_check_uuids`).
- `queue_check_uuids` — queue name → check UUID mapping for separate per-queue alerts (set in the published config file; no dedicated env vars for this array).
- `STEADRUN_WORKER_HEARTBEAT_UUID` — check UUID for the worker heartbeat (see "Worker heartbeat" above).
- `STEADRUN_WORKER_HEARTBEAT_INTERVAL` — minimum interval between worker pings, in seconds (default 60).

### Requirements

PHP 8.1+, Laravel 10/11/12/13.

### License

MIT.