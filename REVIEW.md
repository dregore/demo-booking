# Booking API review

Based on reading the code (`BookingController`, models, migrations, routes, factories, seeder, tests) and on requests against the running stack (curl, OrbStack/Docker). Findings #2, #3, #4, #6 and #17 were reproduced against the live API.

## Summary

| # | Severity | Where | Summary |
| --- | --- | --- | --- |
| 1 | critical | `BookingController.php:23-26` | SQL injection in the overlap check |
| 2 | critical | `BookingController.php:47` | Any user can cancel anyone else's booking |
| 3 | critical | `BookingController.php:37`, `Booking.php:11` | Client sets the booking author (`user_id`), mass assignment |
| 4 | major | `BookingController.php:25-26` | Back-to-back bookings are treated as conflicts |
| 5 | major | `BookingController.php:32-35` | Working hours are not checked in the office timezone; end time is not checked |
| 6 | major | `BookingController.php:32,37`, `Booking.php:17-22` | ISO 8601 offset is lost when saving into `timestamp` |
| 7 | major | `BookingController.php:21-37` | No input validation; conflict query runs before any checks |
| 8 | major | `BookingController.php:23-37`, migration `…000003:11-19` | Check-then-insert race, no DB-level guard |
| 9 | major | `BookingController.php:55` | `today` uses "today" in UTC, not in the room's office timezone |
| 10 | minor | `BookingController.php:42` | 200 and a raw model instead of 201 |
| 11 | minor | `BookingController.php:57-60` | N+1 on `user`, no ordering, UTC strings |
| 12 | minor | `BookingController.php:39-40` | `Log::withContext` set after creation: wrong place and time |
| 13 | minor | migration `…000003:15-16` | No `(room_id, starts_at)` index, no CHECK `ends_at > starts_at` |
| 14 | minor | `routes/api.php:6-11` | No throttling; `{id}` is not route-model-bound |
| 15 | minor | `tests/Feature/`, `BookingFactory.php:17` | Only `index` is tested; factory times are UTC |
| 16 | minor | migration `…000001:14` | `offices.timezone` is a free-form string |
| 17 | minor | `bootstrap/app.php` | Request without token and without `Accept` returns 500 instead of 401 |

## critical

### 1. SQL injection — `BookingController.php:23-26`
`room_id`, `starts_at` and `ends_at` from the request are interpolated into the SQL string. For example `room_id = "1 OR 1=1"` or `ends_at = "x' OR '1'='1"` change the query; data can be read and corrupted (stacked queries depend on the driver, but subqueries always work).
**Fix:** validate first (`exists:rooms,id`, `date`), then query through Eloquent or with bindings: `Booking::where('room_id', $room->id)->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists()`.

### 2. No author check on cancel — `BookingController.php:47`
`Booking::findOrFail($id)->delete()` deletes any booking for any authenticated user. Violates the rule "only the author can cancel". **Reproduced:** Anna's token deleted Boris's booking and got 204.
**Fix:** `abort_unless($booking->user_id === $request->user()->id, 403)` (or a Policy). A missing booking still returns 404.

### 3. Author is set by the client — `BookingController.php:37`, `Booking.php:11`
`Booking::create($request->all())` while `user_id` is in `#[Fillable]`. Anna's token with `user_id: 2` created a booking owned by Boris (the README example itself sends `user_id`). This also lets a caller assign any other fillable field and lets a caller pin a booking on someone else, bypassing the "only the author can cancel" rule. **Reproduced.**
**Fix:** use validated data only; take `user_id` from `$request->user()->id`.

## major

### 4. Back-to-back is a conflict — `BookingController.php:25-26`
The condition `starts_at <= new_end AND ends_at >= new_start` includes the boundaries, so 10:00–11:00 and 11:00–12:00 overlap. **Reproduced:** the second booking returned 409. Strict inequalities are needed: `starts_at < new_end AND ends_at > new_start`.

### 5. Working hours — `BookingController.php:32-35`
- `Carbon::parse` keeps the client's offset, so `$start->hour` is the hour **in the client's timezone**, not the office's. A booking at 10:00+03:00 for a Novosibirsk room (14:00 local) passes, while a valid 09:30+07:00 booking may be rejected depending on the offset sent.
- Only the start is checked: 19:00–23:00 or a booking crossing midnight passes.
- There is no check that the end is after the start.
**Fix:** convert both instants to `$room->office->timezone` (`->setTimezone(...)`), then check `start >= 09:00`, `end <= 20:00`, same local date, `end > start`.

### 6. Offset is lost on save — `BookingController.php:32,37`
`timestamp` columns in PostgreSQL are `timestamp without time zone`. Eloquent casts `2026-11-02T10:00:00+03:00` to Carbon (offset preserved) and formats it as `Y-m-d H:i:s` without converting to the app timezone (UTC), so the database gets `10:00:00` instead of `07:00:00`. The raw query in `store` compares a literal whose offset is dropped as well. As a result, overlaps and "today" are wrong for any non-UTC request. **Reproduced:** `starts_at: 2026-11-02T10:00:00+03:00` was stored as `2026-11-02 10:00:00` (should be `07:00:00`) and returned by the API as `10:00:00Z`.
**Fix:** convert to UTC explicitly before saving (`->utc()`), and/or switch to `timestampTz`.

### 7. No validation — `BookingController.php:21-37`
There is no `validate()`: missing `room_id`/`title`/dates → 500 from the DB or from `Carbon::parse`; a nonexistent room → FK violation → 500; duration is not checked (0 minutes, 10 hours, 15-minute step); `ends_at < starts_at` is accepted. The conflict check runs before the time check, and errors use an ad-hoc `error` key instead of the standard 422 with `errors`.
**Fix:** a `StoreBookingRequest` with format rules plus one business-rule check; return 422 in the standard Laravel shape.

### 8. Race condition — `BookingController.php:23-37`, `…000003_create_bookings_table.php:11-19`
Check and insert are not atomic: two parallel requests can both see a free slot and both insert. Nothing at the database level prevents it (no exclusion constraint, no uniqueness).
**Fix:** `DB::transaction` plus `Room::lockForUpdate()` around check and insert; optionally a PostgreSQL exclusion constraint (`btree_gist`, `tsrange(starts_at, ends_at, '[)')`) if the extension is available.

### 9. `today` and timezone — `BookingController.php:55`
`whereDate('starts_at', today())` compares with "today" in UTC (`config/app.php:68`). For Novosibirsk (UTC+7) "today" is still yesterday from 00:00 to 07:00 local time; for Moscow from 00:00 to 03:00. Fixing it is out of scope for the assignment, but it is a bug.
**Fix:** compute day boundaries in `$room->office->timezone`, convert to UTC, and query a range `starts_at >= start AND starts_at < end` (which, unlike `whereDate`, can use an index).

## minor

### 10. Create response — `BookingController.php:42`
Returns a raw model with status 200. Prefer 201 and a consistent JSON shape (Resource).

### 11. Small issues in `today` — `BookingController.php:57-60`
`$b->user->name` inside the loop is an N+1 (`->with('user')`); there is no `orderBy('starts_at')`; `$b->starts_at . ' – ' . $b->ends_at` outputs UTC time without a zone marker, so the client cannot tell it is not office-local time.

### 12. `Log::withContext` — `BookingController.php:39-40`
Context is set after the record is created and is shared by all later logs in the process (in a long-lived worker it leaks between requests). Simpler: `Log::channel('bookings')->info('Booking created', ['booking_id' => $booking->id])`. Whether `withContext` actually affects the `bookings` channel was not verified.

### 13. Indexes and constraints — `…000003_create_bookings_table.php:15-16`
No `(room_id, starts_at)` index for the overlap check and `today`; no `CHECK (ends_at > starts_at)`; no DB-level protection against overlaps (see #8).

### 14. Routes — `routes/api.php:6-11`
- No `throttle` on `POST /bookings` — bookings can be created in bulk.
- `{id}` in `destroy` is not route-model-bound. It works through `findOrFail`, but `Booking $booking` binding plus a Policy would be cleaner.

### 15. Tests — `tests/Feature/BookingIndexTest.php`, `BookingFactory.php:17`
Only `index` is covered; there are no tests for `store`, `destroy` or `today`, so none of the bugs above would have been caught. The factory uses `now()->addDay()->setTime(10, 0)` in UTC rather than office time; rule tests must set times explicitly.

### 16. `offices.timezone` — `…000001_create_offices_table.php:14`
A free-form string with no validation; an invalid zone name would break `setTimezone` at runtime. Data currently comes only from the seeder, so the risk is low; as the system grows, use an enum or validate on office creation.

### 17. 500 instead of 401 without `Accept` — `bootstrap/app.php`
**Reproduced:** `curl localhost:8000/api/bookings` with no token and no `Accept` → 500, with `Route [login] not defined` in the log (`UrlGenerator.php:542`). The README's claim is correct: `shouldRenderJsonWhen` does not help, because the redirect to the nonexistent `login` route is built first. With a valid token authentication works; only the guest path is broken.
**Fix:** `$middleware->redirectGuestsTo(fn () => null)` (or return a JSON 401 for `api/*`).

## Looks suspicious but is not a problem

- **`orderByRaw('starts_at DESC')`** — `BookingController.php:17`. The string is static and no user input is interpolated, so there is no injection. `orderByDesc('starts_at')` would be more readable.
- **`index` returns only the user's own bookings** — `where('user_id', $request->user()->id)` filters by the authenticated user; other users' bookings are not exposed. Pagination of 20 is reasonable.
- **`Booking::findOrFail($id)`** in `destroy` — a nonexistent id correctly yields 404. The only problem is the missing author check (#2).
- **Foreign keys** `room_id`, `user_id` with `constrained()` — correct; without validation they turn into 500s (#7), but they are fine themselves.
- **`Room $room` in `today`** — route model binding returns 404 for a nonexistent room.
