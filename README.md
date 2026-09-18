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

### Конфигурация

```bash
php artisan vendor:publish --tag=steadrun-config
```

- `STEADRUN_BASE_URL` — адрес Steadrun, по умолчанию `https://steadrun.ru`. Переопределите, если используете self-hosted инстанс.
- `STEADRUN_QUEUE_UUID` — UUID check'а для алертов о упавших job'ах (общий, для очередей вне `queue_check_uuids`).
- `queue_check_uuids` — сопоставление имени очереди с UUID check'а, для случая нескольких очередей с раздельными алертами (задаётся в опубликованном конфиге, отдельных env-переменных для этого массива нет).

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

### Configuration

```bash
php artisan vendor:publish --tag=steadrun-config
```

- `STEADRUN_BASE_URL` — Steadrun instance URL, defaults to `https://steadrun.ru`. Override if self-hosting.
- `STEADRUN_QUEUE_UUID` — check UUID for failed-job alerts (shared, for queues not in `queue_check_uuids`).
- `queue_check_uuids` — queue name → check UUID mapping for separate per-queue alerts (set in the published config file; no dedicated env vars for this array).

### Requirements

PHP 8.1+, Laravel 10/11/12/13.

### License

MIT.