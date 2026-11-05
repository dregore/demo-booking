# Phase 1 — Review (`REVIEW.md`)

Output: `REVIEW.md`, one entry per finding with severity (critical / major / minor), `file:line`, description, fix. Plus a section for suspicious-but-fine code.

## Scope
`BookingController` (`store`, `destroy`, `index`, `today`), `app/Models/*`, `database/migrations/*`, `routes/api.php`, `tests/Feature/*`; also factory, seeder, `bootstrap/app.php`, `config/logging.php`.

## Steps
- [x] Re-read each target with line numbers; note every suspicious spot.
- [x] `store`: check SQL building (L23-26), overlap operators, validation order, timezone handling (L32-35), mass assignment (L37), status codes, logging (L39-40), race.
- [x] `destroy`: check authorization (L47) and 404 behaviour.
- [x] `index`: check `orderByRaw`, scoping to the authenticated user, pagination output.
- [x] `today`: check `whereDate(..., today())` timezone, N+1 on `user`, ordering, string formatting of datetimes.
- [x] Models: `#[Fillable]` (incl. `user_id`), casts, relations.
- [x] Migrations: column types (`timestamp`), indexes, CHECK/exclusion constraints, `offices.timezone` as free string.
- [x] Routes: `{id}` not model-bound, no throttle, auth group.
- [x] Tests: what `BookingIndexTest` covers; gaps; factory default times.
- [x] Verify uncertain items (see open questions) by reading framework code or running a test; do not assume.
- [x] Rank candidates (starting point below), write `REVIEW.md`.
- [x] Add "Suspicious but fine" section.
- [x] Self-check against Definition of Done.

## Candidate findings (to confirm)
**Critical**
- SQL injection via interpolated request values — `BookingController.php:23-26`. Fix: validation + bound parameters / Eloquent.
- `destroy` has no author check — `BookingController.php:47`. Fix: compare `user_id` with authenticated user → 403.
- `Booking::create($request->all())` lets the client set `user_id` (and any fillable field) — `BookingController.php:37`. Fix: take `user_id` from `$request->user()`, use validated data only.

**Major**
- Overlap uses `<=` / `>=`, so back-to-back bookings are rejected — `:25-26`. Fix: `starts_at < :end AND ends_at > :start`.
- Working hours checked via `$start->hour` in the parsed offset/UTC, not office-local; end time not checked; midnight crossing possible — `:32-35`.
- No input validation (missing fields, bad dates → 500, end ≤ start, duration, 15-min step, unknown `room_id` → FK 500), and validation runs after the conflict query.
- Check-then-insert race, no transaction or DB constraint.
- `today` filters by app timezone (UTC), not the room's office timezone — `:55`.

**Minor**
- Returns 200 with raw model instead of 201.
- `today` N+1 (`$b->user->name`), no ordering, UTC strings in output.
- `Log::withContext` after create — check whether it has any effect on the `bookings` channel.
- No index on `bookings(room_id, starts_at)`; no CHECK `ends_at > starts_at`.
- No rate limiting; `{id}` untyped/not route-model-bound.

**Suspicious but fine (confirm)**
- `orderByRaw('starts_at DESC')` — static string, not injectable (`orderByDesc` is cleaner).
- `findOrFail` → 404 for missing booking.
- `index` correctly scoped to the authenticated user.
- Foreign keys on `room_id`/`user_id`.

## Open questions / to verify
- Does `Log::withContext` affect the `bookings` channel?
- README says a request without `Accept` returns 500 instead of 401; `bootstrap/app.php` forces JSON for `api/*` — is the claim stale?
- How `timestamp` (without tz) columns behave if the app timezone is not UTC (`config/app.php:68`).
- Does `today` output datetimes in UTC strings?
- Is `btree_gist` available in the Docker PostgreSQL (affects the suggested constraint fix)?
- Language of `REVIEW.md` (README is Russian — default to Russian).

## Definition of Done
- Every finding has severity, `file:line`, description, fix.
- At least one "suspicious but fine" entry with reasoning.
- Critical items traced to a concrete line (or reproduced).
- No application code changed.
