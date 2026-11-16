# Notification Service

A notification system that accepts SMS, Email and Push requests over a REST API and delivers them
asynchronously through queue workers, with per-channel rate limiting, priorities, retries and
delivery tracking.

Built with Laravel 12 (PHP 8.3), MySQL 8, Redis and Laravel Horizon.

- [Quick start](#quick-start)
- [Configuration and secrets](#configuration-and-secrets)
- [Architecture](#architecture)
- [Delivery and retry logic](#delivery-and-retry-logic)
  - [Rate limiting](#rate-limiting)
- [API examples](#api-examples)
- [Observability](#observability)
- [Tests and code quality](#tests-and-code-quality)
- [Found and fixed during testing](#found-and-fixed-during-testing)
- [Design decisions and limitations](#design-decisions-and-limitations)

## Quick start

Requirements: Docker with Docker Compose. No `.env` file or other setup is needed.

```bash
git clone https://github.com/ridvankapicioglu/laravel-webhook-notification-service.git
cd laravel-webhook-notification-service
docker-compose up
```

That single command builds the image, starts every service and runs the migrations. Both spellings
of the command work:

| Command | |
|---|---|
| `docker-compose up` | Compose v1 style, as written in the assessment. Still shipped with Docker Desktop |
| `docker compose up` | Compose v2 style (a Docker CLI plugin), the current default |

Add `-d` to run it in the background, and `--build` to rebuild the image after changing the code.
`docker compose down` stops everything; `docker compose down -v` also deletes the database.

Once it is up:

| URL | |
|---|---|
| http://localhost:8000/api | REST API |
| http://localhost:8000/docs | Swagger UI (OpenAPI spec in [`docs/openapi.yaml`](docs/openapi.yaml)) |
| http://localhost:8000/horizon | Horizon dashboard (queues, workers, failed jobs) |

```bash
curl -X POST http://localhost:8000/api/notifications \
  -H 'Content-Type: application/json' \
  -d '{"recipient":"+905551234567","channel":"sms","content":"Hello","priority":"high"}'
```

### External provider (webhook.site)

As the assessment asks, the external provider is simulated with webhook.site. Messages are sent to
`https://webhook.site/924656b7-59d1-49ef-bde5-124930bd4665`, which is configured to answer:

```
POST https://webhook.site/924656b7-59d1-49ef-bde5-124930bd4665
{"to": "+905551234567", "channel": "sms", "content": "Your message"}

202 Accepted
{"messageId": "<uuid>", "status": "accepted", "timestamp": "<ISO 8601>"}
```

To use your own URL: open https://webhook.site, in *Edit* set status `202`, content type
`application/json` and a body like the one above, then put it in a `.env` file next to
`docker-compose.yml` and run `docker compose up -d` again:

```
NOTIFICATION_PROVIDER_URL=https://webhook.site/<your-uuid>
```

Free webhook.site URLs accept a limited number of requests. Once the limit is reached webhook.site
answers `429 Request limit exceeded`; the service then treats every call as a temporary error and
keeps retrying with backoff, so nothing is lost, but nothing is delivered either. If that happens,
create your own URL as described above.

#### Protecting the request limit

An earlier URL ran out of requests during development because a 1000-message load test was sent
to it. Since then the configured URL is only used for real deliveries:

| Where | What keeps it away from webhook.site |
|---|---|
| Test suite | `Http::preventStrayRequests()` in [`tests/TestCase.php`](tests/TestCase.php): any HTTP call that is not faked fails the test instead of leaving the machine |
| CI | the Docker smoke test sets `NOTIFICATION_PROVIDER_URL=http://mock-provider:8080` ([`ci.yml`](.github/workflows/ci.yml)) |
| Load and failure tests | always run against the local mock provider (below) |

### Local mock provider (load and failure tests)

Because of that request limit, webhook.site is not the place for load tests. The `mock-provider`
service answers like the webhook.site setup above and can also fail on purpose:

```bash
# .env
NOTIFICATION_PROVIDER_URL=http://mock-provider:8080
MOCK_FAILURE_RATE=0.3   # 30% of requests get a 500, 503 or 429, to watch the retries
```

The load test numbers below were measured against the mock.

### Configuration and secrets

There is no `.env` file in the repository, on purpose. `.env` holds secrets (application key,
database passwords, API tokens) and is listed in `.gitignore`, as in any Laravel project.

- **For this assessment** every value the stack needs has a default in `docker-compose.yml`, so it
  runs with one command and no setup. Those defaults (`APP_KEY`, the `secret` database password)
  are for local development only. The webhook.site URL is not a secret.
- **`.env.example`** lists every setting the app reads. To change one, copy it to `.env`
  (`cp .env.example .env`) and edit the values; Docker Compose picks up `.env` automatically and it
  wins over the defaults.
- **In a real deployment** nothing secret goes into the repository or the image: no `.env`, no
  default passwords in `docker-compose.yml`. Values come from the environment of the platform
  (Kubernetes secrets, AWS Secrets Manager / SSM, GitHub Actions secrets for CI), a new `APP_KEY`
  is generated per environment (`php artisan key:generate --show`), database and Redis use strong
  passwords, and a real provider's API token is stored the same way. If a secret is ever committed
  by mistake, it is rotated, not just deleted from the history.

## Architecture

```
                 ┌────────────┐   insert (transaction)   ┌─────────┐
 client ──HTTP──▶│  API (app) │─────────────────────────▶│  MySQL  │◀───────────────┐
                 └─────┬──────┘                          └─────────┘                │ claim / result
                       │ dispatch job (id only)                                     │
                       ▼                                                            │
                 ┌──────────────────────────────┐   pop in order   ┌──────────────────┴──┐   POST   ┌──────────┐
                 │ Redis queues: high/normal/low│─────────────────▶│  Horizon workers    │────────▶│ provider │
                 └──────────────────────────────┘                  │  + rate limiter     │         └──────────┘
                                                                   └─────────────────────┘
 scheduler: notifications:recover (every minute) re-queues stuck or lost notifications
```

- **MySQL is the source of truth.** The API stores the notification and only then pushes a job
  that contains just the notification id. Workers always read the current row, so a cancelled
  notification is never sent.
- **Redis** holds the queues and the rate limit counters.
- **Priorities** are three queues. Horizon workers are configured with `balance=false` and the
  queue list `high, normal, low`, so a worker always takes high priority jobs first.
- **Docker services**: `app` (php-fpm), `nginx`, `horizon` (workers), `scheduler`, `mysql`, `redis`,
  `migrate` (runs once before the others start) and `mock-provider`.

Code layout:

```
app/
  Data/NotificationData.php          readonly DTO built from validated input
  Delivery/                          provider client, delivery result, retry policy, rate limiter
  Enums/                             Channel, Priority, NotificationStatus
  Http/Controllers/Api/              thin controllers
  Http/Requests/                     validation (channel dependent rules)
  Jobs/SendNotification.php          the worker side of the flow
  Models/                            Notification (with atomic status transitions), Batch, Attempt
  Services/                          NotificationService, MetricsService
  Console/Commands/RecoverNotifications.php
```

### Data model

- `notifications`: one row per message, its status, attempt count, scheduling and result.
  `idempotency_key` has a unique index. `dispatched_at` is when its job was last pushed to the queue
  (`NULL` = never reached the queue); writing it does not touch `updated_at`.
- `notification_batches`: a batch request, notifications point to it with `batch_id`.
- `notification_attempts`: every call made to the provider (status code, error, duration).
  This is what shows *why* a message was not delivered.

Statuses: `pending → processing → sent | failed`, and `pending → cancelled`.
A notification waiting for a retry is `pending` with `attempts > 0` and `last_error` set.

## Delivery and retry logic

What a worker does for each job:

1. **Quick rate limit check.** If the channel's window is already full, the job goes back to the
   queue for a second without touching the database.
2. **Claim.** `UPDATE notifications SET status='processing', attempts=attempts+1 WHERE id=? AND status='pending'`.
   If no row changed, someone else already took it (or it was cancelled), and the job stops. This is
   what makes duplicated jobs harmless: the same notification can't be sent twice in parallel.
3. **Take a rate limit permit**, as the very last step before the call (see [Rate limiting](#rate-limiting)).
   If there is none, the claim is undone (the attempt is not counted) and the job is released.
4. **Send** to the provider with a 5 second timeout. The notification id is sent as an
   `Idempotency-Key` header, so a provider that supports it can drop duplicates.
5. **Record** the attempt in `notification_attempts` (timestamped when the request was sent) and decide:

| Provider response | Treated as | Result |
|---|---|---|
| any 2xx | accepted | `sent`. Never retried, even if the body is unexpected, to avoid double sending. Anything other than `202` with `messageId` and `status: accepted` is logged as `provider.unexpected_response` |
| 408, 5xx, connection error / timeout | temporary | retry with backoff |
| 429 | temporary | retry, not earlier than `Retry-After` |
| other 4xx | permanent | `failed` right away, sending again would give the same answer |

Error messages longer than the 255-character column are shortened before they are stored, so a long
connection error can't make the result write fail after the provider was already called.

### Rate limiting

At most 100 provider calls per channel in **any** one second, across all workers:

- Each permit is a member of a Redis sorted set (one per channel) scored by its time in milliseconds.
  A Lua script removes permits older than the window, counts the rest and adds a new one only if
  there is room. Redis runs the script atomically, and the time comes from the Redis server
  (`TIME`), so all workers share one clock and two of them can never take the last slot.
- It is a sliding window, not a counter per calendar second: a counter allows 100 calls at the end
  of one second and 100 more at the start of the next, 200 within a few milliseconds.
- The permit is the last thing taken before the HTTP call (not at the start of the job, which let
  calls drift into the next second: 103-111 calls per second in a load test).
- The window is 1100 ms instead of 1000 ms (`NOTIFICATION_RATE_LIMIT_WINDOW_MS`). The time between
  taking a permit and the request reaching the provider varies by a few tens of milliseconds under
  load; measured at the provider, an exact 1000 ms window still let 105 requests arrive within one
  second. With the 100 ms margin the maximum measured at the provider is 100. The cost is a
  ceiling of ~91 instead of 100 calls per second.

**Backoff.** Up to 5 attempts in total. Waits before attempts 2-5 are 10s, 30s, 90s and 270s, plus
up to 20% random jitter. The jitter matters when a provider outage fails thousands of messages at
once: without it they would all come back at exactly the same second.

**Attempt counting.** Laravel's own job attempts are not used for this (`$tries = 0`), because
rate limit releases would use them up. The real count is the `attempts` column.

**Failure handling.**

- After the last attempt or a permanent error the notification is `failed`, with `last_error`
  and the full attempt history.
- `POST /api/notifications/{id}/retry` puts a failed notification back in the queue with a fresh
  budget of attempts. The attempt history is kept and keeps counting (e.g. `#1: 404`, `#2: 202`).
- If Redis cannot be reached when a request comes in, the notification is still saved and the API
  still answers 202; the failed push is logged and `dispatched_at` stays `NULL`.
- `notifications:recover` runs every minute. It looks at each notification, not at the queue size,
  so constant traffic or scheduled jobs sitting in the same queue cannot hide a lost one:
  - **stuck**: a worker died while a notification was `processing` (OOM, container restart). After
    5 minutes it goes back to `pending`, or to `failed` if no attempts are left;
  - **never queued**: `pending` with `dispatched_at IS NULL` for more than a minute (the push to Redis
    failed). Dispatched again;
  - **lost**: `pending`, due for more than 15 minutes and its job was pushed more than 15 minutes ago
    (e.g. Redis lost its data). Dispatched again. Each one at most once per 15 minutes and at most
    1000 per run, so a long backlog only gets a bounded number of extra jobs, which the claim makes
    harmless.
- Unexpected exceptions in the job (a bug, the database going away) are retried by the queue up
  to 3 times, after that the job is recorded in Horizon's failed jobs. If the exception happened
  after the claim, the notification is left in `processing` and the recovery command above picks it up.

## API examples

Full reference: http://localhost:8000/docs.

```bash
# Create (idempotency key is optional; also accepted as an Idempotency-Key header)
curl -X POST localhost:8000/api/notifications -H 'Content-Type: application/json' -d '{
  "recipient": "user@example.com", "channel": "email", "content": "Your invoice is ready",
  "priority": "normal", "idempotency_key": "invoice-1001"
}'
# 202 on first call; sending it again returns 200 with the same notification.
# The same key with a different payload is a client error: 409, nothing is created or changed.

# Schedule for later
curl -X POST localhost:8000/api/notifications -H 'Content-Type: application/json' -d '{
  "recipient": "+905551234567", "channel": "sms", "content": "Sale starts now",
  "scheduled_at": "2027-06-01T09:00:00+03:00"
}'

# Batch (1 to 1000 items, all validated before anything is saved)
curl -X POST localhost:8000/api/notifications/batch -H 'Content-Type: application/json' -d '{
  "notifications": [
    {"recipient": "+905551234567", "channel": "sms",  "content": "Flash sale!", "priority": "high"},
    {"recipient": "device-token-1", "channel": "push", "content": "Flash sale!"}
  ]
}'

# Status
curl localhost:8000/api/notifications/{id}         # includes delivery_attempts
curl localhost:8000/api/batches/{batch_id}         # counts per status

# List with filters and pagination
curl 'localhost:8000/api/notifications?status=failed&channel=sms&from=2026-11-01&to=2026-11-30&per_page=50&page=1'

# Cancel (only pending ones, otherwise 409)
curl -X POST localhost:8000/api/notifications/{id}/cancel

# Retry a failed one
curl -X POST localhost:8000/api/notifications/{id}/retry
```

Validation rules:

| Field | Rule |
|---|---|
| `channel` | required, `sms`, `email` or `push` |
| `recipient` | required; sms: E.164 number (`+905551234567`), email: valid address, push: device token up to 512 chars |
| `content` | required; up to 160 chars for sms, 10000 for email, 1000 for push |
| `priority` | `high`, `normal` (default) or `low` |
| `scheduled_at` | optional ISO 8601 date in the future |
| `idempotency_key` | optional, up to 128 chars; reusing it with a different payload returns 409 |

## Observability

- `GET /api/metrics`: queue depth per priority (ready / delayed / reserved), notification count
  per status, success and failure rate overall and per channel, provider latency and end-to-end
  latency (avg, p50, p95, max). Rates and latency cover the last 60 minutes by default (`?window=15`),
  counted by when each result happened (`sent_at` / `failed_at`).
- `GET /api/health`: checks MySQL, Redis and the workers (a running Horizon master, which keeps a
  heartbeat in Redis). Returns `503` if any of them is down; with Horizon stopped it switched to 503
  within 15 seconds.
- **Logs** are JSON lines on stderr (`docker compose logs -f horizon`). Every request gets an
  `X-Correlation-ID` (the client's own, or a generated one), which is returned in the response,
  stored on the notification and carried into the queued job through Laravel's `Context`. So one
  id links the API request with the worker logs of every delivery attempt:

```json
{"message":"notification.retry_scheduled","context":{"channel":"sms","priority":"high","attempt":1,"status_code":503,"duration_ms":48,"error":"provider_error: http 503","retry_in_seconds":11},"level_name":"WARNING","extra":{"correlation_id":"checkout-123","notification_id":"0199..."}}
```

## Tests and code quality

```bash
docker compose exec app composer test       # PHPUnit
docker compose exec app composer analyse    # PHPStan (Larastan, level 6)
docker compose exec app composer lint       # Pint
```

Tests run on MySQL like the application, but in a separate `notifications_test` database that
the mysql container creates on its first start (`docker/mysql/init`). The test bootstrap refuses
to run against any database whose name does not end in `_test`, so the application's data is never
touched. The queue and the HTTP client are faked, and any HTTP call that is not faked fails the
test (`Http::preventStrayRequests()`), so tests can never reach the real provider. The rate limiter
tests use real Redis (database 14, not the one the app uses), because the limiter is a Lua script.

They cover the API (validation, batch limits, filters, pagination, cancel, idempotency), the
delivery job (every provider response type, retry and attempt limits, duplicate jobs, manual
retry), the rate limiter, recovery and the metrics / health endpoints.

Two tests run real parallel processes (`pcntl_fork`, each with its own connections). The helper
([`RunsInParallel`](tests/Concerns/RunsInParallel.php)) fails the test unless every process was
forked, waited for and reported back without an error, so a crashing worker can't make it pass
(checked by [`RunsInParallelTest`](tests/Feature/RunsInParallelTest.php)):

- [`ConcurrentClaimTest`](tests/Feature/ConcurrentClaimTest.php): 10 workers load the same
  notification and claim it at the same moment; exactly one wins.
- [`RateLimitTest`](tests/Feature/RateLimitTest.php): 8 workers ask for 50 permits each at the same
  time with a limit of 100; exactly 100 are granted.

GitHub Actions (`.github/workflows/ci.yml`) runs lint, static analysis and tests on MySQL, then
starts the whole stack with Docker Compose (against the mock provider) and checks that a
notification is actually delivered.

### Load test

Run locally with Docker Desktop (20 Horizon workers, mock provider with 50 ms latency):

| Scenario | Result |
|---|---|
| 3000 SMS + 1000 Email in 4 batches | all 4000 sent in ~50 s; measured at the provider (arrival times logged by the mock, in ms): max **100** requests per channel in any 1000 ms window, two runs; no attempt used up by rate limiting |
| 300 low, 300 normal, 300 high SMS queued while workers were stopped, then workers started | high finished first, normal 8 s later, low 15 s later |
| 300 SMS with 30% provider errors (500/503/429) | 299 sent after retries (219 on the 1st attempt, 62 on the 2nd, 14 on the 3rd, 4 on the 4th), 1 waiting for its 4th attempt |
| provider returns 500 for every call | 1st retry after 12 s (10 s + jitter); `failed` after the 5th attempt; sent on the next retry once the provider is back |
| Redis stopped, then MySQL stopped | `/api/health` answers 503 with the failing dependency; delivery resumes after both come back |

The rate limit was checked from the provider side: the mock logs the arrival time of every request
in milliseconds (`docker compose logs mock-provider`), and the busiest 1000 ms window was counted
over those timestamps.

## Found and fixed during testing

Every requirement was also checked against the running Docker stack, and the code went through a
review afterwards. These are the problems found that way, each with what now guards against it:

| Problem | How it showed up | Fix | What prevents it from coming back |
|---|---|---|---|
| Rate limit went over 100/s (103-111 calls in one second) | load test, counting provider calls per second in `notification_attempts` | the permit is taken right before the HTTP call, not at the start of the job | load test re-run |
| A per-second counter still allowed ~200 calls across a second boundary, and the provider saw up to 105 requests in one second | code review, then measuring arrival times at the provider in ms | sliding-window limiter (Redis sorted set + Lua, Redis server time) with a 1100 ms window as a safety margin | `RateLimitTest` (incl. 8 parallel processes against real Redis); load test: max 100 in any 1000 ms window at the provider |
| A job lost from Redis was never recovered while the queue had other jobs | code review; reproduced with a scheduled job sitting in the same queue | recovery decides per notification using `dispatched_at` instead of "is the queue empty" | `test_a_lost_job_is_recovered_even_while_the_queue_is_busy` |
| A request accepted while Redis was down could be left without a job | code review | a failed push is logged, the row keeps `dispatched_at = NULL` and recovery queues it; the API still answers 202 | `test_the_notification_is_kept_when_the_queue_is_unreachable`, `test_notifications_that_never_reached_the_queue_are_dispatched` |
| Push tokens of 256-512 characters passed validation but failed on insert (500) | code review; reproduced with a 300-character token | `recipient` widened to 512 in a new migration | `test_push_tokens_up_to_512_characters_are_stored` |
| The same idempotency key with a different payload silently returned the old notification | code review | the request is compared with the stored notification's own fields (no extra hash column, so rows written before this check work too); a mismatch returns 409 | `test_same_key_with_a_different_payload_is_rejected`, the batch variant, `test_a_row_stored_before_this_check_existed_still_matches_a_retry` |
| Recording the dispatch also changed `updated_at`, which the metrics used | code review | the dispatch write skips the timestamps; metrics count by `sent_at` / `failed_at` | `test_recording_the_dispatch_does_not_touch_updated_at`, `test_rates_count_results_by_when_they_happened_not_by_updated_at` |
| Some settings in `.env.example` (rate limit window, max attempts, timeouts) never reached the containers | code review | every setting is passed through in `docker-compose.yml` | checked live: values from the environment are read by the workers |
| The parallel tests did not check that every process finished | code review | shared helper that requires every process to report back without an error | `RunsInParallelTest` |
| `/api/health` did not notice stopped workers | code review | health also checks for a running Horizon master | `test_health_returns_503_when_no_worker_is_running`; live: 503 within 15 s of stopping Horizon |
| An error message longer than 255 characters would fail the result write after the provider was called | code review | errors are shortened before they are stored | `test_a_very_long_error_message_is_stored_shortened` |
| A manual retry could send the message twice | `POST /notifications/{id}/retry` on a failed notification left it stuck in `processing` | attempt history numbering no longer restarts when the attempt budget is reset; before, the new attempt clashed with attempt #1 *after* the provider was called, and the recovery command would have sent it again | `test_a_manually_retried_notification_keeps_its_attempt_history` |
| API answered 502 after the `app` container was recreated | nginx logs during the failure tests | nginx resolves `app` through Docker's DNS on every request instead of once at startup | checked by recreating `app` with a different IP |
| The test suite could wipe the application database | running the tests inside the container emptied the `notifications` table | test settings use `<server force="true">` (Laravel reads `$_SERVER` first, where Docker puts its variables) and tests use their own `notifications_test` database | [`tests/TestCase.php`](tests/TestCase.php) refuses to run against a database whose name does not end in `_test` |
| Horizon started workers one by one | only 6 of 10 workers running during a burst | `minProcesses` = `maxProcesses`, all 20 workers start at once | [`config/horizon.php`](config/horizon.php) |
| webhook.site URL ran out of requests | a load test was sent to it | see [Protecting the request limit](#protecting-the-request-limit) | stray-request guard in tests, mock provider in CI |
| Throughput stuck at 20 messages/s | every job took ~1 s | the mock provider ran PHP's single-threaded built-in server; it now runs 32 workers (`PHP_CLI_SERVER_WORKERS`). Only the test tool was affected | [`docker-compose.yml`](docker-compose.yml) |

## Design decisions and limitations

- **At-least-once, not exactly-once.** If the provider accepts a message but the worker dies before
  writing the result, the recovery will send it again. Sending the notification id as
  `Idempotency-Key` lets a real provider remove such duplicates; webhook.site does not.
- **Rate limit margin.** The limit holds where it matters, at the provider, because of a 100 ms
  safety margin on the window. If the delay between permit and arrival grew beyond that (a very
  slow network), the margin would have to grow with it; it is configurable for that reason.
- **Lost-job recovery is time based.** A notification is re-queued if it has been due for 15
  minutes without being picked up. During a backlog longer than that, some notifications get a
  second job (bounded: once per 15 minutes each, 1000 per run); the claim makes the extra job a
  no-op. A transactional outbox would avoid the extra jobs at the cost of another table and relay.
- **Strict priority** means low priority messages wait while there is high priority work. That is
  the intended behaviour for flash sales and OTPs; a dedicated worker for `low` would avoid starvation
  if it became a problem.
- **Metrics** are computed from MySQL on request, limited to a time window and indexed columns.
  At real scale they would move to counters pushed to Prometheus or similar.
- **Not included:** authentication and multi-tenancy (not in the scope of the task), templates,
  WebSocket updates and distributed tracing.
