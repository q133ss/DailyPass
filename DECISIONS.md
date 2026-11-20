# Decisions

## Billing day and timezone

The billing day is a calendar day in `BILLING_TIMEZONE` (UTC by default), half-open `[00:00, 24:00)`. All timestamps are stored in UTC; only the day boundary uses the configured zone. `billing_day` is a PostgreSQL `date`, so a subscriber has one operation per internal subscriber id and day. The unique index `(subscriber_id, billing_day)` is the final guard, not just application logic.

## Idempotency key and operation state

`externalTrxId` is deterministic: `DP-{YYYYMMDD}-{subscribers.id}`. The operation row is committed **before** the first debit request, and every retry/restart reuses the same key. A retry changes the attempt and operation state, never the key. Each attempt is a separate `charge_attempts` row with time, status code, reference id (when present) and raw response for audit.

The operation claims a row with `SELECT ... FOR UPDATE`, writes the attempt row and marks the operation `PROCESSING` before doing HTTP. Another billing process cannot claim it. A stale `PROCESSING` row older than 10 minutes is recovered and retried with the same key.

## Retry policy

- `S1000` — store the result and send a receipt containing `referenceId`.
- `E1326` (no balance) and `E1318` (rate limit) — retryable. Bounded by `BILLING_MAX_ATTEMPTS` (3) with `BILLING_RETRY_DELAYS_SECONDS` (demo 15/60s), state stored in PostgreSQL.
- `E1308` — permanent; operation `FAILED_PERMANENT`, subscriber `SUSPENDED`, no retry.
- `E1337` — terminates the operation. If the carrier returns the original transaction data, it confirms a lost success and a receipt is sent. Without original data, the operation is `UNKNOWN` for manual review: no invented reference and no receipt.
- Timeout/connection loss — ambiguous but safe to retry with the same key. If retries are exhausted or a STOP arrived, the operation becomes `UNKNOWN`.
- Boundary: a retry is never scheduled past its billing day. A failure near midnight closes the operation as `EXPIRED`; a previous day's pending operation is expired at the next run. The next day is a new operation with a new key.

## STOP, in-flight charges and the API limitation

STOP is committed locally (status `INACTIVE`, `stopped_at`) **before** the carrier opt-out call. That commit cancels pending/retry-scheduled operations and is the point from which billing refuses new attempts. The carrier call and goodbye SMS happen afterwards.

If the debit claim already committed, the charge is considered in flight. The carrier API has no cancel/debit-reversal operation, so an HTTP request that has already left the service cannot be unsent. The service records the actual outcome honestly: a confirmed success is stored and receipted even if STOP arrived meanwhile; a retryable failure schedules no retry; an ambiguous timeout becomes `UNKNOWN` for review. This is a contract limitation, not something application code can fix.

If the opt-out call times out, local state remains `INACTIVE`, so billing is blocked, and `optOutStatus=UNCONFIRMED` is stored. `subscriptions:retry-optouts` (scheduled every 10 minutes) retries the idempotent action `"0"` up to 5 times. A repeated STOP is acknowledged without a second goodbye.

Late or repeated `REGISTERED` events never reactivate a stopped subscriber. Only an explicit new opt-in via `/api/subscriptions` (which records `opt_in_requested_at` after `stopped_at`) can reactivate one. `PENDING`/`CHARGE` events are recorded but do not change state.

## Carrier API assumptions and demo mapping

The contract has no webhook signature, event id or sequence number. Idempotency is a SHA-256 of `applicationId|subscriberId|frequency|status|version|timeStamp`; the same delivery is ignored. A platform retry with a different timestamp is treated as a new observation. Only `applicationId` is validated on incoming calls.

`subscriberId` is opaque. By default the demo uses `tel:<digits>` for both MSISDN and subscriber id; `BILLING_ALIASES` can map an MSISDN to a masked alias, and the code never parses a phone number out of an alias. SMS delivery still uses the real MSISDN because the SMS endpoint expects a destination number.

Mock assumptions, tested explicitly:

- `E1326`/`E1318` mean no completed transaction was stored by the carrier; a repeat with the same key is processed as a new attempt and may succeed.
- A successful charge is stored by `externalTrxId`; a repeat returns `E1337` with the original transaction data by default.
- `duplicateDetails:false` simulates `E1337` without original data.
- Tests count `charges` (actual money) separately from `debitRequests` (HTTP attempts).

## Scope and known gaps

Out of scope: auth/SSO, USSD, DLR, Query Balance, multi-currency, production infrastructure and a UI. The mock admin endpoints (`/mock/*`) are intentionally unauthenticated and demo-only. The demo password in `docker-compose.yml` is a placeholder, not a real secret. Money-critical gaps that remain are operational: webhook authenticity is not cryptographic (the contract provides no mechanism), stale `UNKNOWN` operations need a human/finance review process, and the carrier API cannot cancel an already-sent debit.
