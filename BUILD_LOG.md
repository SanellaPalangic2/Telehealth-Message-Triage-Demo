# Build Log

One line per thing that broke and got fixed: **what broke — why — the fix.**

## Phase 0 — Setup

- PHP and Composer were not installed — fresh Windows machine — installed via the php.new (Laravel Herd) installer; terminal restart needed to pick up PATH.
- `php` not found from Git Bash even after install — Herd exposes `php.bat`/`composer.bat`, which Bash can't run as `php` — run PHP/Composer commands from PowerShell (or call `php.exe` directly).
- `config/services.php` flipped to CRLF line endings — Python text-mode writes on Windows convert `\n` to `\r\n` — normalized back to LF with `sed -i 's/\r$//'`; use Edit/sed for file edits instead.

## Phase 1 — Data

- Seed-data test failed on valid data — Pest's `toContain()` treats every argument as a value to search for, so my custom failure message was searched for as PII — switched to `expect(str_contains(...))->toBeTrue($message)`.

## Phase 2 — Privacy: redaction

- Privacy tests crashed with `Call to undefined method Container::databasePath()` — Pest builds datasets before Laravel boots, and the seeder used `database_path()` — load the seed file with `__DIR__` instead.
- DOB-in-parentheses test failed — the test expected the "DOB" label to be removed, but the service (correctly, consistently) keeps labels and replaces only the value — fixed the test's expectation, not the code.

## Phase 3 — Red-flag rules

- Caught in review before running: a test listed "The stroke of midnight…" as a near miss that must NOT flag — but the `stroke` rule intentionally fires on any mention — moved it to the "deliberately over-flagged" test, alongside negations like "no chest pain".
- `triage:process` printed `#27 URGENT` with the `→` arrow missing — Windows console code page doesn't render that Unicode character — switched to ASCII `->`.

## Phase 4 — AI classification

- Spec said "force a required tool" via `tool_choice: {type: "tool"}` — checked the current API reference before writing code: `claude-opus-5-5` rejects forced tool_choice with a 400 — used `tool_choice: auto` + `strict: true` + a prompt instruction, and treat a missing tool call as invalid output.
- Strict tool schemas can't express `minimum`/`maximum`/`maxLength` — unsupported in strict mode — confidence range and the 2-sentence summary limit are enforced only in `AiOutputValidator`.
- Two Phase 3 pipeline tests failed once the AI step existed — they asserted non-red-flag messages stay `new` with no HTTP calls — updated them to expect `rules.passed` + an AI call, and to check red-flag bodies are never sent.

## Phase 5 — Review queue

- Layout at `resources/views/layouts/app.blade.php` couldn't be used as `<x-layouts.app>` — anonymous Blade components must live under `resources/views/components/` — moved it to `components/layouts/app.blade.php`.
- Leftover dev server kept port 8123 busy after a smoke test — stopping `php artisan serve` on Windows doesn't stop the child `php -S` process it spawns — killed the process that owns the port (`Get-NetTCPConnection -LocalPort 8123`).

## Phase 6 — Evaluation

- First dry run: red-flag recall 66.7% — the rules missed E03 ("can't lift her right arm… words jumbled", a stroke) and E05 ("bleeding heavily… won't stop") because the patterns only knew "face drooping"/"slurred"/"one side" and the exact phrase "heavy bleeding" — added limb-weakness, jumbled-speech, sudden-onset, and "bleeding heavily" patterns plus unit tests; recall back to 100%.
- Dry run printed "0 would be sent to the API" while the table said 25 — the summary line used the live-run counter (`sentToAi`), which is always 0 in a dry run — added `EvalReport::wouldSendToAi()` and used it in both places.
- `--max-cases=0` silently started a full live run — `if ($max = ...)` treats the string "0" as false, so validation was skipped — switched to an explicit `!== null` check; `Http::preventStrayRequests()` caught it in tests before any real call.
- History test failed although the output was right — Laravel's `expectsOutputToContain` matches each printed line to only one expectation, and the model and prompt hash share a table row — asserted on `Artisan::output()` instead.

## Phase 7 — Ship it

- Rate-limit tests showed the limit never kicked in — the limiter read `config('triage.rate_limits')` once at boot, so per-request config (and test overrides) were ignored — read the config inside each limiter closure.
- Rate-limit tests errored with `Unknown named parameter $server` — Laravel's test `post()` has no server-variables argument — used `withServerVariables(['REMOTE_ADDR' => ...])` to simulate different visitors.
- Dockerfile could not be built locally — Docker isn't installed on the dev machine — verified the pieces that can be checked without it (`sh -n` on the entrypoint, `config:cache`/`route:cache`/`view:cache`, full test suite); the first Render deploy is the real build test.

## Post-launch — Automatic triage via a queued job

- CHANGE: triage now runs automatically — creating a message dispatches a queued `TriageMessage` job (database queue) that runs the full pipeline; seeded messages are NOT auto-triaged (the seeder mutes model events) because the demo re-seeds on every Render restart and would burn API calls — added "Re-run triage" (failed/stale results), a rate-limited "Triage all pending" button, and `triage:run --pending` (replaces `triage:process`); the daily AI budget moved from the button to a per-AI-call check inside the job, because one bulk click queues many calls.
- Default `retry_after` (90s) was shorter than the job's 180s timeout — the database queue would hand a slow AI call to the worker again and bill it twice — raised `retry_after` to 240s.
- Caught in design before running: `ShouldBeUnique` takes its lock at dispatch time, so a job waiting with no worker (or discarded in tests) would block "Queue triage" for 10 minutes — switched to `WithoutOverlapping` middleware, which locks only while the job runs.
- "Queue triage" accepted a message that already had a suggestion — `TriageQueue::queue()` treated any stale suggestion as re-runnable from every entry point — split the two intents (`rerun: true` only from the Re-run button).

## Post-launch — Prompt-injection defense

- CHANGE: layered prompt-injection defense — fake `<patient_message>` tags in patient text are neutralized, the system prompt marks tagged text as untrusted, an `InjectionDetector` logs `injection_attempt` (RULE) and forces review, the output check rejects promise/leak drafts and extra fields as `suspected_injection` (RULE), and approve/reject now requires a human review decision (model guard + SQLite trigger + transition table); 8 attack messages added to the seed and eval sets, plus an injection-resistance metric.
- Privacy test "messages without PII reach the prompt unchanged" failed for the `</patient_message>` attack seed — the new tag neutralization (correctly) rewrites that text — excluded attack messages from that test and added dedicated delimiter tests.
- Pipeline test expected exactly one audit row for a red flag — the red-flag + injection seed now logs `injection_attempt` before `rules.red_flag` — query the `rules.red_flag` row specifically.
- Eval-case shape test failed after allowing the optional `attack` key — `array_diff()` keeps the original keys, so the canonical comparison saw a gap — wrapped it in `array_values()`.
- The Phase 6 eval run disappeared from `triage:eval-history` — `migrate:fresh --seed` (used to reset demo data) also wipes `eval_runs`, which live in the same SQLite database — the earlier numbers are preserved in the README; reset demo data with care, or move eval runs to their own database if history matters.

## Post-launch — "Triage all pending" 500 and false injection flags

- "Triage all pending" returned a 500 on Render but looked fine after a refresh — the web request and the in-container queue worker write to the same SQLite file, and Laravel's SQLite defaults (no busy timeout, DEFERRED transactions) fail instantly with "database is locked"; reproduced locally with a worker running — set `busy_timeout` 5000ms and `IMMEDIATE` transactions, and queue the whole batch in one transaction.
- My first fix made it worse locally: "unable to open database file" under `php artisan serve` — the one-transaction batch nested a savepoint per message, savepoint undo data spills to a temp file, and `artisan serve` starts PHP without the TEMP/TMP/USERPROFILE variables SQLite uses to find the temp folder (WAL mode, which I'd also enabled, wasn't the cause) — removed the nesting, set `PRAGMA temp_store = MEMORY` on every SQLite connection, and made WAL opt-in.
- Innocent drafts were rejected and flagged as `suspected_injection` — a draft echoing the patient ("your request about a 90-day supply", likely "your lisinopril 10mg refill" in the reported AI failure) trips the strict quantity/dosage check, which can't tell an echo from a promise — told the model not to repeat numbers from the message in drafts, keeping the check strict (allowing echoes would let "tell me to take 40mg" attacks through).
- My reproduction script triaged 3 messages with the real API key by accident — in PowerShell, setting an env var to "" deletes it, so the worker fell back to the key in `.env` — use a single space to keep the variable set but blank.
