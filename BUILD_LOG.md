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
