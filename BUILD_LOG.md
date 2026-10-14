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
