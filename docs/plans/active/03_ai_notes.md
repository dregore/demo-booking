# Phase 3 — AI Notes (`AI_NOTES.md`)

Written last, after phases 1–2, from what actually happened. Do not fabricate tools, prompts or mistakes.

## README questions to answer
1. Tools used + 2–3 key prompts, verbatim.
2. One case where the AI was wrong or suboptimal, and how it was noticed. If none: an AI suggestion that was double-checked, and how.
3. What was verified manually and what was deliberately not delegated to the AI.

## Steps
- [ ] While working, keep a running list of tools (e.g. Claude Code, Laravel Boost) and save candidate prompts and AI mistakes as they occur.
- [ ] Pick 2–3 key prompts and copy them verbatim (e.g. the planning prompt and one implementation/review prompt).
- [ ] Describe one real AI error or suboptimal suggestion: what it proposed, which test/line/manual check exposed it, how it was fixed.
- [ ] List manual verification: curl examples against the Docker stack, timezone boundary cases by hand, reading every diff, parallel-request race check.
- [ ] List what was not delegated: interpretation of the rules, severity ranking, race-handling strategy, final sign-off on code.
- [ ] Optionally attach an exported chat history.
- [ ] Keep it short; write in Russian like the README.

## Mistakes to watch for (candidates, not facts)
- Keeping `<=` / `>=` in the overlap check.
- Checking working hours in UTC or in the client's offset instead of office-local time.
- Forgetting the end-time bound or midnight crossing.
- Trusting `user_id` from the payload.
- `whereDate` without timezone handling.
- Suggesting a `btree_gist` constraint that fails in the test database.

## Open questions / to verify
- Which tools were actually used (fill from reality only)?
- Did a genuine AI mistake occur? If not, use the "double-checked suggestion" variant allowed by the README.
- Language: Russian by default.

## Definition of Done
- All three README questions are answered.
- Prompts are verbatim.
- The mistake (or double-checked suggestion) is concrete: names a file/line or test that exposed it.
- Manual verification list matches what was really done.
