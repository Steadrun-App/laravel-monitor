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

### Конфигурация

```bash
php artisan vendor:publish --tag=steadrun-config
```

- `STEADRUN_BASE_URL` — адрес Steadrun, по умолчанию `https://steadrun.ru`. Переопределите, если используете self-hosted инстанс.
- `STEADRUN_QUEUE_UUID` — UUID check'а для алертов о упавших job'ах.

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

### Configuration

```bash
php artisan vendor:publish --tag=steadrun-config
```

- `STEADRUN_BASE_URL` — Steadrun instance URL, defaults to `https://steadrun.ru`. Override if self-hosting.
- `STEADRUN_QUEUE_UUID` — check UUID for failed-job alerts.

### Requirements

PHP 8.1+, Laravel 10/11/12/13.

### License

MIT.