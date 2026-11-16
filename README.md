# Notification Service

A notification system that accepts SMS, Email and Push requests over a REST API and delivers them
asynchronously through queue workers, with per-channel rate limiting, priorities, retries and
delivery tracking.

Built with Laravel 12 (PHP 8.3), MySQL 8, Redis and Laravel Horizon.

- [Quick start](#quick-start)
- [Architecture](#architecture)
- [Delivery and retry logic](#delivery-and-retry-logic)
- [API examples](#api-examples)
- [Observability](#observability)
- [Tests and code quality](#tests-and-code-quality)
- [Design decisions and limitations](#design-decisions-and-limitations)

## Quick start

Requirements: Docker with Docker Compose.

```bash
docker compose up -d --build
```

That starts everything and runs the migrations. Then:

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
`https://webhook.site/6ae5e09c-75d6-4a28-931b-91043e0fbef6`, which is configured to answer:

```
POST https://webhook.site/6ae5e09c-75d6-4a28-931b-91043e0fbef6
{"to": "+905551234567", "channel": "sms", "content": "Your message"}

202 Accepted
{"messageId": "9f0c5a43-1b7e-4c2a-9d1f-3e8a6b2c7d10", "status": "accepted", "timestamp": "2026-11-16T10:00:00Z"}
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

### Local mock provider (load and failure tests)

Because of that request limit, webhook.site is not the place for load tests. The `mock-provider`
service answers like the webhook.site setup above and can also fail on purpose:

```bash
# .env
NOTIFICATION_PROVIDER_URL=http://mock-provider:8080
MOCK_FAILURE_RATE=0.3   # 30% of requests get a 500, 503 or 429, to watch the retries
```

The load test numbers below were measured against the mock.

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
  `idempotency_key` has a unique index.
- `notification_batches`: a batch request, notifications point to it with `batch_id`.
- `notification_attempts`: every call made to the provider (status code, error, duration).
  This is what shows *why* a message was not delivered.

Statuses: `pending → processing → sent | failed`, and `pending → cancelled`.
A notification waiting for a retry is `pending` with `attempts > 0` and `last_error` set.

## Delivery and retry logic

What a worker does for each job:

1. **Quick rate limit check.** If the channel's counter for the current second is already full,
   the job goes back to the queue for the next second without touching the database.
2. **Claim.** `UPDATE notifications SET status='processing', attempts=attempts+1 WHERE id=? AND status='pending'`.
   If no row changed, someone else already took it (or it was cancelled), and the job stops. This is
   what makes duplicated jobs harmless: the same notification can't be sent twice in parallel.
3. **Take a rate limit slot** right before the call: `INCR` on a per-channel, per-second counter in
   Redis, atomic across all workers. If there is no slot left, the claim is undone (the attempt is
   not counted) and the job is released for the next second. The slot is taken this late on purpose:
   taking it at the start of the job let calls drift into the next second under load, which showed
   up as 103-111 calls per second in a load test. The limiter also re-takes the slot when the Redis
   round trip itself crosses a second boundary.
4. **Send** to the provider with a 5 second timeout. The notification id is sent as an
   `Idempotency-Key` header, so a provider that supports it can drop duplicates.
5. **Record** the attempt in `notification_attempts` (timestamped when the request was sent) and decide:

| Provider response | Treated as | Result |
|---|---|---|
| any 2xx | accepted | `sent`. Never retried, even if the body is unexpected, to avoid double sending |
| 408, 5xx, connection error / timeout | temporary | retry with backoff |
| 429 | temporary | retry, not earlier than `Retry-After` |
| other 4xx | permanent | `failed` right away, sending again would give the same answer |

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
- `notifications:recover` runs every minute and covers two crash cases:
  - a worker died while a notification was `processing` (OOM, container restart). After 5 minutes it
    goes back to `pending` or to `failed` if no attempts are left;
  - Redis lost its jobs. When a priority queue is completely empty but due `pending` notifications
    exist, they are dispatched again. The empty-queue check keeps this from adding duplicate jobs
    while a large backlog is still being processed.
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
| `idempotency_key` | optional, up to 128 chars |

## Observability

- `GET /api/metrics`: queue depth per priority (ready / delayed / reserved), notification count
  per status, success and failure rate overall and per channel, provider latency and end-to-end
  latency (avg, p50, p95, max). Rates and latency cover the last 60 minutes by default (`?window=15`).
- `GET /api/health`: checks MySQL and Redis, returns `503` if one of them is down.
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
touched. The queue and the HTTP client are faked, Redis is not used by the tests.

They cover the API (validation, batch limits, filters, pagination, cancel, idempotency), the
delivery job (every provider response type, retry and attempt limits, duplicate jobs, manual
retry), the rate limiter, recovery and the metrics / health endpoints.

GitHub Actions (`.github/workflows/ci.yml`) runs lint, static analysis and tests on MySQL, then
starts the whole stack with Docker Compose (against the mock provider) and checks that a
notification is actually delivered.

### Load test

Run locally with Docker Desktop (20 Horizon workers, mock provider with 50 ms latency):

| Scenario | Result |
|---|---|
| 3000 SMS + 1000 Email in 4 batches | all 4000 sent in 41 s; max **100** provider calls in any second per channel; no attempt used up by rate limiting |
| 300 low, 300 normal, 300 high SMS queued while workers were stopped, then workers started | high finished first, normal 8 s later, low 15 s later |
| 300 SMS with 30% provider errors (500/503/429) | 299 sent after retries (219 on the 1st attempt, 62 on the 2nd, 14 on the 3rd, 4 on the 4th), 1 waiting for its 4th attempt |
| provider returns 500 for every call | 1st retry after 12 s (10 s + jitter); `failed` after the 5th attempt; sent on the next retry once the provider is back |
| Redis stopped, then MySQL stopped | `/api/health` answers 503 with the failing dependency; delivery resumes after both come back |

The query used to check the rate limit:

```sql
SELECT n.channel, DATE_FORMAT(a.created_at, '%H:%i:%s') AS second, COUNT(*) AS calls
FROM notification_attempts a JOIN notifications n ON n.id = a.notification_id
GROUP BY n.channel, second ORDER BY calls DESC LIMIT 5;
```

## Design decisions and limitations

- **At-least-once, not exactly-once.** If the provider accepts a message but the worker dies before
  writing the result, the recovery will send it again. Sending the notification id as
  `Idempotency-Key` lets a real provider remove such duplicates; webhook.site does not.
- **Rate limit window.** The limiter counts per calendar second. Within any one second a channel
  never goes over 100 calls, but 100 at the end of one second and 100 at the start of the next is
  possible. A sliding window would smooth that out at the cost of a more complex Redis script.
- **Strict priority** means low priority messages wait while there is high priority work. That is
  the intended behaviour for flash sales and OTPs; a dedicated worker for `low` would avoid starvation
  if it became a problem.
- **Metrics** are computed from MySQL on request, limited to a time window and indexed columns.
  At real scale they would move to counters pushed to Prometheus or similar.
- **Not included:** authentication and multi-tenancy (not in the scope of the task), templates,
  WebSocket updates and distributed tracing.
