# Daily Pass

Daily Pass is a small paid daily subscription built on top of a carrier billing API. The repository contains:

- `service/` — Laravel 13 / PHP 8.4 subscription service, PostgreSQL, PHPUnit.
- `platform-mock/` — plain PHP mock of the carrier platform, with configurable responses and audit history.
- `docker-compose.yml` — runs the service, mock, PostgreSQL and the scheduler with one command.

The money path is deliberately conservative: one `charge_operations` row per internal subscriber and billing day, a stored `externalTrxId` before any HTTP call, an attempt row for every call, and a database unique constraint as the final guard against double charging.

## Run

Only Docker with Compose v2 is required. No PHP, Composer, `.env` file or manual database setup is needed.

```bash
git clone https://github.com/q133ss/DailyPass.git
cd DailyPass
docker compose up --build
```

`--build` is only needed the first time (or after changing dependencies); plain `docker compose up` also builds missing images. Use `-d` to run in the background:

```bash
docker compose up -d --build
docker compose ps
```

Wait until the `service` container is healthy. Endpoints:

- Service: <http://localhost:8000>
- Health: <http://localhost:8000/up>
- Carrier mock: <http://localhost:8080>
- Mock audit state: <http://localhost:8080/mock/state>

Stop and wipe all data (including mock history):

```bash
docker compose down -v
```

The scheduler container runs `billing:run` every day at 00:05 in `BILLING_TIMEZONE` and reconciles unconfirmed opt-outs every 10 minutes. For the demo below the billing job is triggered manually.

## Demo scenarios

All JSON requests use `Content-Type: application/json`. The demo subscribers are `tel:1000000001`, `tel:1000000002`, `tel:1000000003` and `tel:1000000004`.

Reset the mock at any time:

```bash
curl -s -X POST http://localhost:8080/mock/reset -H 'Content-Type: application/json' -d '{}'
```

### 1. Opt-in and welcome SMS

```bash
curl -s -X POST http://localhost:8000/api/subscriptions \
  -H 'Content-Type: application/json' \
  -d '{"msisdn":"tel:1000000001"}'

curl -s http://localhost:8080/mock/state
```

The response is `ACTIVE`; the mock state contains one `subscriptionRequests` entry with `action:"1"` and one welcome SMS in `sms`.

A second identical opt-in returns `"alreadyActive":true` and does not call the carrier again.

### 2. Successful daily charge and receipt

```bash
docker compose exec service php artisan billing:run
```

Run it a second time: the operation is terminal, so the mock charge count stays at 1. Inspect the result:

```bash
curl -s 'http://localhost:8000/api/status?msisdn=tel%3A1000000001'
curl -s 'http://localhost:8000/api/charges?msisdn=tel%3A1000000001'
curl -s http://localhost:8080/mock/state
```

`charges[0].referenceId` in the mock equals `today.referenceId` from the status endpoint, and the mock `sms` array contains a receipt with the same reference. `debitRequests` shows the HTTP call; `charges` shows the actual money movement.

### 3. Insufficient balance (`E1326`) and retry

```bash
curl -s -X POST http://localhost:8000/api/subscriptions \
  -H 'Content-Type: application/json' \
  -d '{"msisdn":"tel:1000000002"}'

curl -s -X POST http://localhost:8080/mock/debit-response \
  -H 'Content-Type: application/json' \
  -d '{"statusCode":"E1326"}'

docker compose exec service php artisan billing:run
curl -s 'http://localhost:8000/api/status?msisdn=tel%3A1000000002'
```

The operation is `RETRY_SCHEDULED`, there is no charge and no receipt. The demo retry delay is 15 seconds. After waiting, run the command again:

```bash
sleep 16
docker compose exec service php artisan billing:run
curl -s 'http://localhost:8000/api/status?msisdn=tel%3A1000000002'
```

The second attempt reuses the same `externalTrxId`, succeeds and sends exactly one receipt. `debitRequests` has two entries, `charges` has one.

To shorten the wait, change `BILLING_RETRY_DELAYS_SECONDS` in `docker-compose.yml` and recreate the service.

### 4. Permanent error (`E1308`) suspends the subscriber

```bash
curl -s -X POST http://localhost:8000/api/subscriptions \
  -H 'Content-Type: application/json' \
  -d '{"msisdn":"tel:1000000003"}'

curl -s -X POST http://localhost:8080/mock/debit-response \
  -H 'Content-Type: application/json' \
  -d '{"statusCode":"E1308"}'

docker compose exec service php artisan billing:run
curl -s 'http://localhost:8000/api/status?msisdn=tel%3A1000000003'
```

The subscriber becomes `SUSPENDED`, the operation becomes `FAILED_PERMANENT`, and no retry is scheduled.

### 5. Duplicate subscription webhook

Push the same `REGISTERED` event twice to the service:

```bash
curl -s -X POST http://localhost:8080/mock/callback \
  -H 'Content-Type: application/json' \
  -d '{"subscriberId":"tel:1000000003","status":"REGISTERED","timeStamp":"2026-11-20T12:00:00+00:00","repeat":2}'

docker compose exec db psql -U dailypass -d dailypass \
  -c "select count(*) from platform_events where subscriber_id = 'tel:1000000003';"
```

Both deliveries return `S1000`, but exactly one event row exists. Webhooks never start a charge; running `billing:run` again does not add a charge.

### 6. STOP and goodbye SMS

Use a fresh subscriber so it has not been charged yet:

```bash
curl -s -X POST http://localhost:8000/api/subscriptions \
  -H 'Content-Type: application/json' \
  -d '{"msisdn":"tel:1000000004"}'

curl -s -X POST http://localhost:8000/api/webhooks/sms \
  -H 'Content-Type: application/json' \
  -d '{"applicationId":"APP_000123","sourceAddress":"tel:1000000004","message":"STOP","encoding":"0","version":"1.0"}'

curl -s 'http://localhost:8000/api/status?msisdn=tel%3A1000000004'
docker compose exec service php artisan billing:run
curl -s 'http://localhost:8000/api/charges?msisdn=tel%3A1000000004'
curl -s http://localhost:8080/mock/state
```

The subscriber is `INACTIVE` with `optOutStatus:"CONFIRMED"`, the mock received `action:"0"`, and the goodbye SMS is visible in the mock. The billing run creates no operation and no debit. A repeated STOP is acknowledged but does not send another goodbye.

If the carrier opt-out times out, local state is still `INACTIVE` and billing is blocked; `optOutStatus` is `UNCONFIRMED`. Reconciliation can be triggered manually:

```bash
docker compose exec service php artisan subscriptions:retry-optouts
```

### 7. Audit history

```bash
curl -s 'http://localhost:8000/api/charges?msisdn=tel%3A1000000001'
docker compose exec db psql -U dailypass -d dailypass -c \
  "select external_trx_id, status, reference_id, attempts_count from charge_operations order by id;"
docker compose exec db psql -U dailypass -d dailypass -c \
  "select charge_operation_id, attempt_no, status, status_code, reference_id, started_at from charge_attempts order by id;"
```

For "was this MSISDN charged today, and what is the reference?" the answer is in `charge_operations.reference_id` and the `referenceId` from the mock.

## Tests

Tests run against the real mock and a separate PostgreSQL database `dailypass_test`; they do not touch demo data.

```bash
docker compose exec service php artisan test
```

Coverage includes:

- charge-once for two runs in one day and concurrent billing processes;
- duplicate webhooks and late `REGISTERED` after STOP;
- `E1326`/`E1318` retry classification, `E1308` suspension, `E1337` with and without original data;
- same `externalTrxId` across retries and across a lost success response;
- retry boundary at the billing-day end;
- STOP before a charge, STOP during an in-flight success/retryable response, and opt-out timeout;
- actual mock charges counted separately from HTTP debit requests;
- PostgreSQL state assertions and secret-redaction checks.

## Service API

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/api/subscriptions` | Opt-in `{ "msisdn": "tel:..." }` |
| POST | `/api/webhooks/subscription` | Carrier `REGISTERED`/`UNREGISTERED`/`PENDING`/`CHARGE` callback |
| POST | `/api/webhooks/sms` | Inbound MO SMS, including `STOP` |
| GET | `/api/status?msisdn=tel%3A...` | Current subscriber state and today's operation |
| GET | `/api/charges?msisdn=tel%3A...&date=YYYY-MM-DD` | Charge history with attempts |

## Mock controls

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/health` | Mock health |
| POST | `/subscription/send` | Opt-in/opt-out |
| POST | `/sms/send` | MT SMS |
| POST | `/caas/direct/debit` | Direct debit with `externalTrxId` idempotency |
| POST | `/mock/debit-response` | Queue a response: `{ "statusCode": "E1326", "count": 1, "delayMs": 0 }` |
| POST | `/mock/config` | `duplicateDetails`, `optinStatus`, `optoutStatus`, `optoutDelayMs`, `smsStatus` |
| POST | `/mock/callback` | Push a subscription callback to the service |
| GET | `/mock/state` | Audit state: requests, actual charges, SMS, callbacks |
| POST | `/mock/reset` | Clear all mock state |

## Configuration

Defaults live in the `x-service-environment` block of `docker-compose.yml`; no `.env` is required. The values can be overridden by exporting the matching variables (for example `PLATFORM_PASSWORD`, `PLATFORM_APPLICATION_ID`, `BILLING_TIMEZONE`, `BILLING_RETRY_DELAYS_SECONDS`) before `docker compose up`, or by editing that block. The application and the mock read the same credential variables.

- `BILLING_TIMEZONE=UTC` — billing day boundary.
- `BILLING_DAILY_AMOUNT=5.00`, `BILLING_CURRENCY=XTS`.
- `BILLING_MAX_ATTEMPTS=3`, `BILLING_RETRY_DELAYS_SECONDS=15,60`.
- `BILLING_ALIASES={}` — optional JSON map from MSISDN to an opaque carrier `subscriberId`; identity mapping by default.
- `PLATFORM_*` — carrier URL, application id, password, timeout, SMS source.

`applicationId` and `password` are read only from configuration. They are never logged, and the mock rejects wrong credentials.

## Key design documents

See [DECISIONS.md](DECISIONS.md) for the billing day, idempotency key, retries, STOP synchronization and API assumptions.
