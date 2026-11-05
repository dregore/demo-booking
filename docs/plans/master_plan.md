# Master Plan — Meeting Room Booking take-home

Mirrors the three parts of the assignment in `README.md`. This is a planning document only; no application code is part of this step.

## Goal
Review the existing booking API, fix `store`/`destroy` to satisfy the booking rules (with feature tests), and document AI usage.

## Phases

| # | Phase | Plan file | Output |
|---|-------|-----------|--------|
| 1 | Review | [active/01_review.md](active/01_review.md) | `REVIEW.md` |
| 2 | Implementation | [active/02_implementation.md](active/02_implementation.md) | fixed `store`/`destroy` + feature tests |
| 3 | AI Notes | [active/03_ai_notes.md](active/03_ai_notes.md) | `AI_NOTES.md` |

Order: 1 → 2 → 3. Review findings drive the fixes; AI notes are written last because they need real prompts and real mistakes from the session.

## Files per phase

### Phase 1 — Review
- Creates: `REVIEW.md`
- Read-only: `app/Http/Controllers/BookingController.php`, `app/Models/*`, `database/migrations/*`, `database/seeders/DatabaseSeeder.php`, `database/factories/*`, `routes/api.php`, `bootstrap/app.php`, `config/logging.php`, `tests/Feature/*`

### Phase 2 — Implementation
- Creates:
  - `app/Http/Requests/StoreBookingRequest.php` (via `php artisan make:request`)
  - optionally one small helper class (e.g. office-hours/duration rule) via `make:class`
  - one migration (index on `room_id, starts_at`; optional CHECK / exclusion constraint)
  - `tests/Feature/BookingStoreTest.php`, `tests/Feature/BookingDestroyTest.php` (via `make:test --phpunit`)
- Modifies:
  - `app/Http/Controllers/BookingController.php` (`store`, `destroy` only; `index`/`today` untouched)
  - `app/Models/Booking.php` / `app/Models/Room.php` (only if helpers/scopes are needed)
  - `database/factories/BookingFactory.php` (only if states are needed)
  - `README.md` (race-condition section)

### Phase 3 — AI Notes
- Creates: `AI_NOTES.md`

## Delivery requirements
- **README:** run and test commands already exist (`docker compose up -d --wait`, `docker compose exec app php artisan migrate:fresh --seed`, `docker compose exec app php artisan test`). Re-verify they work after the changes; add the race-condition note.
- **Commit history:** small, meaningful commits in English. Suggested split: review → tests for a rule + fix → ... → race handling → README → AI notes. Each commit should leave tests green.
- **Race-condition note:** state the problem (check-then-insert, two concurrent requests for one slot), the chosen fix (row lock on the room inside a transaction, optionally a PostgreSQL exclusion constraint), and how it was verified — or, if not implemented, how it would be done.
- **Access:** if the repo is private, add `rcsi-science` as collaborator (or send an archive including `.git`).
- Every line must be explainable at the follow-up call.

## Global constraints
- Run `vendor/bin/pint --dirty --format agent` after touching PHP.
- Use `php artisan make:*` with `--no-interaction`.
- No new dependencies; no new base folders.
- Do not fix `index`/`today` — review only.

## Status
- [x] Phase 1 — Review done
- [x] Phase 2 — Implementation done
- [x] Phase 3 — AI Notes done
- [x] Delivery checklist verified
