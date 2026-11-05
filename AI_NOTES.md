# AI notes

## 1. Tools and key prompts

**Tools**
- Claude Code (VS Code extension, model Claude Sonnet 5.5) as the only coding agent. It read the code, wrote `REVIEW.md`, the tests and the fix, ran commands, and made the commits.
- Project guidelines from Laravel Boost (`CLAUDE.md` / `AGENTS.md`) were loaded into the agent's context. The Boost MCP server was not used.
- The `superpowers:test-driven-development` skill, invoked before phase 2 (tests first, watch them fail, then implement).
- Docker Compose on OrbStack for the app and PostgreSQL; the agent ran `migrate:fresh --seed`, curl requests and the test suite in the container.

**Prompt 1: planning (verbatim, trimmed only of the surrounding system text)**

```
Read CLAUDE.md, README.md, AGENTS.md, and the existing code first:
- app/Http/Controllers/BookingController.php
- app/Models/*
- database/migrations/*
- database/seeders/*
- routes/api.php
- tests/Feature/*

Context: README.md (in Russian) describes a take-home assignment with three parts:
1. Code review of the existing booking API → produce REVIEW.md
2. Fix store/destroy per the booking rules + feature tests
3. Write AI_NOTES.md

Do NOT write application code yet. Only produce planning/architecture markdown.

Perform these file operations strictly:
1. Create docs/plans/master_plan.md — a master plan that mirrors the three parts of the assignment (Review, Implementation, AI Notes), plus a short section on delivery requirements (README with run/test commands, commit history, race-condition note).
2. For each part, create a separate file in docs/plans/active/:
   - 01_review.md — plan for analyzing BookingController (store, destroy, index, today), models, migrations, routes, tests; output is REVIEW.md with severity (critical/major/minor), file:line, description, fix.
   - 02_implementation.md — plan for fixing store and destroy to satisfy: no overlapping bookings (back-to-back allowed), duration 15 min–4 h in 15-min steps, booking fully inside 09:00–20:00 office-local time (Europe/Moscow, Asia/Novosibirsk), only author can cancel, 422 validation errors, no other date restrictions; plus feature tests for each rule.
   - 03_ai_notes.md — plan for AI_NOTES.md (tools used, 2–3 verbatim prompts, one case where AI was wrong and how it was caught, what was verified manually).
3. Each phase file must contain clear checkboxes (- [ ]) for step-by-step execution and a short "Definition of Done" section.
4. In master_plan.md, list the files each phase will create or modify.

Constraints:
- Do not generate REVIEW.md, code, or tests yet — only the plan documents.
- Base the plan on the actual current code, not on assumptions. If something in the code is unclear (e.g. Booking model $fillable, office timezone storage), add a "Open questions / to verify" section to the relevant phase file.
- Keep the plan concise and actionable; no generic architecture boilerplate.
```

**Prompt 2: pushing back on a false limitation (verbatim)**

> why can't you run docker? Also, I have orbStack locally installed. You can use it.

**Prompt 3: questioning a review entry (verbatim)**

> Why does selected text looks suspicious?

(The selection was the "`auth:sanctum` on the whole group is fine" line in `REVIEW.md`.)

## 2. Where the AI was wrong

The agent made several mistakes during the session. They are listed by how much they could have cost.

**Main case: a wrong claim about the README, written without testing it.** While reviewing, the agent saw `shouldRenderJsonWhen(...)` in `bootstrap/app.php` and concluded that the README's statement "a request without `Accept` and without a token returns 500 instead of 401" was probably stale. It put that into `REVIEW.md` as a "looks suspicious but fine" item. This was an inference from reading code, and it was wrong. Once the stack was running, a plain `curl localhost:8000/api/bookings` returned 500 with `Route [login] not defined` in the log: the redirect to the missing `login` route is built before the JSON renderer is consulted. The review was corrected and the issue became finding #17 with a fix.

How it was caught: by running the request against the live API instead of trusting the code reading.

**Related mistake: claiming it could not run Docker.** After finishing phase 1, the agent said it could not start Docker and asked the user to do it. It had not tried. The Docker socket was missing only because OrbStack was not running; `orbctl start` fixed it. Caught by the user's question in Prompt 2.

**A review entry that overclaimed.** After the live check, the agent shortened the `auth:sanctum` entry to "authentication is configured correctly", which contradicts finding #17 and is not a suspicious spot anyway. Caught by the user's question in Prompt 3; the line was removed and its one valid point folded into #17.

**A check that looked like proof but proved nothing.** The agent fired 10 parallel curl requests at a double-booking race and got one `201` and nine `422`. That would look like "the lock works", but `php artisan serve` handles one request at a time. A multi-worker server and 60 requests at 30 in parallel still produced exactly one booking when the lock was removed, so the race was never reproduced. The README states this explicitly and relies on the PostgreSQL exclusion constraint (tested directly) as the real guarantee.

The pre-seeded failure ideas from the plan (keeping `<=`/`>=`, checking hours in UTC, trusting `user_id`) did not happen in the final code: the tests were written first and fail against the old behaviour. To confirm the back-to-back tests have teeth, the agent temporarily switched the overlap operators back to `<=`/`>=`; exactly the two back-to-back tests failed.

## 3. What was verified, and what was not delegated

**Verified by running (done in the session by the agent, output seen)**
- The original API's bugs, reproduced with curl: a user deleting another user's booking (204), a spoofed `user_id`, a back-to-back booking rejected (409), a `+03:00` time stored without conversion (`10:00` instead of `07:00` UTC), the 500 without `Accept`.
- The new tests were run red before implementing (45 failed, 5 passed), then green after (52, then 55 with the database-constraint tests). The full suite passes.
- The mutation check for the overlap operators described above.
- The race attempts described above, including that they were inconclusive.

**To be completed by the author before submitting**

The items below can only be written by a person who did them. Fill in what is true and delete the rest:
- [ ] Read the full diff (`git diff ef7025b^..HEAD -- app database tests`) and can explain each changed line, in particular `StoreBookingRequest` (office-local checks) and the transaction plus room lock in `BookingController::store`.
- [ ] Ran the README commands from scratch (`docker compose up -d --wait`, `migrate:fresh --seed`, `php artisan test`).
- [ ] Hand-checked boundary cases against the running API, e.g. a Novosibirsk room at 09:00+07:00, 19:45–20:00+07:00, and the same instant sent in UTC.
- [ ] Decisions made by the author rather than the AI (for example: all rule violations, including overlap, are `422`; times are stored in UTC; whether to keep the exclusion constraint).

**Deliberately not trusted without a check**
- Claims about framework behaviour made from reading code (the `Accept` claim above is the example).
- Any statement that the race fix works: only the database constraint is covered by a test.
