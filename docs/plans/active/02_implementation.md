# Phase 2 — Implementation (`store`, `destroy`, tests)

## Rules to satisfy
1. No overlapping bookings per room; back-to-back allowed (10:00–11:00 and 11:00–12:00).
2. Duration 15 min – 4 h, 15-minute step.
3. Booking fully inside 09:00–20:00 office-local time (Europe/Moscow, Asia/Novosibirsk); client sends ISO 8601 with any offset.
4. Only the author can cancel.
5. Validation errors as 422 with clear messages.
6. No other date restrictions (past dates are allowed).
7. Feature tests for every rule.

## Design (based on current code)
- `StoreBookingRequest`: `room_id` (required, exists), `title` (required string), `starts_at`/`ends_at` (required, ISO 8601 with offset). `user_id` is ignored from input; taken from `$request->user()`.
- Convert both instants to `$room->office->timezone` (available via `Room::office`). In local time: start ≥ 09:00, end ≤ 20:00, same local date, `ends_at > starts_at`, duration 15–240 min, both on a 15-minute boundary with 0 seconds.
- Overlap: `room_id = ? AND starts_at < new_end AND ends_at > new_start` via Eloquent/bindings (no raw interpolation).
- Persist in UTC (current `timestamp` columns + app tz UTC).
- Respond 201 with the created booking.
- `destroy`: `findOrFail`, 403 if `user_id` differs from the authenticated user, 404 if missing; past bookings can be cancelled.
- Race (bonus): `DB::transaction` + `lockForUpdate()` on the room row around check+insert; optional migration with PostgreSQL exclusion constraint as defense in depth; at minimum describe in README.
- Leave `index` and `today` untouched.

## Steps (TDD; commit after each group)
- [ ] `make:test --phpunit BookingStoreTest` and `BookingDestroyTest`.
- [ ] Tests — happy path: Moscow room and Novosibirsk room → 201.
- [ ] Tests — overlap: partial, contained, containing, identical → 422; other room same time → 201.
- [ ] Tests — back-to-back on both sides → 201.
- [ ] Tests — duration: 14 / 15 / 240 / 241 min; non-15 step (10:07) → 422; end ≤ start → 422.
- [ ] Tests — working hours per office: 09:00 ok, 08:45 no, end 20:00 ok, end 20:15 no; client sending `Z` / other offsets; the same instant valid for one office and invalid for the other; crossing midnight.
- [ ] Tests — validation: missing/invalid fields, unknown room → 422; `user_id` in payload ignored.
- [ ] Tests — destroy: author 204, other user 403, missing 404, unauthenticated 401; past booking can be cancelled.
- [ ] Run tests, confirm they fail for the right reasons.
- [ ] `make:request StoreBookingRequest`; implement validation + office-local checks (helper class only if the request gets unwieldy).
- [ ] Rewrite `store`: transaction, room lock, bound-parameter overlap check, `user_id` from auth, 201.
- [ ] Rewrite `destroy`: author check.
- [ ] Optional migration: index `(room_id, starts_at)`, CHECK `ends_at > starts_at`, exclusion constraint if `btree_gist` is available.
- [ ] Verify the race manually (two parallel requests) or document why not.
- [ ] README: race-condition section; re-check run/test commands.
- [ ] `vendor/bin/pint --dirty --format agent`; `php artisan test --compact`.

## Open questions / to verify
- Status code for overlap: 422 (README "validation 422") vs 409 (current). Default: 422 for all rule violations, documented in README.
- Is the 15-minute step measured in office-local or absolute time? Identical for MSK (+3) and NSK (+7); use local.
- Is an end time of exactly 20:00 allowed? Assumed yes (interval `[start, end)`).
- Exact ISO 8601 acceptance: `Z`, `±HH:MM`, `±HHMM`, fractional seconds — check what Laravel's `date` rule/Carbon accept and decide.
- Keep `timestamp` or migrate to `timestamptz`? Default: keep (storing UTC), no migration.
- Can `btree_gist` be created in the main and `testing` databases (`docker/create-testing-db.sql`)?
- Response shape for 201: the repo has no API Resource convention yet.
- `BookingFactory` default (tomorrow 10:00 UTC) vs office-local rules — override in tests.
- Does `Booking` casts/`#[Fillable]` need changes once `user_id` comes from auth?

## Definition of Done
- Each rule has at least one test that failed before and passes after the fix.
- `php artisan test --compact` is green; Pint is clean.
- `index` and `today` unchanged.
- No SQL string interpolation left in `store`.
- Race handled or described in README with the chosen approach.
- Each commit is self-consistent and I can explain every changed line.
