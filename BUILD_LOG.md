# Build Log

One line per thing that broke and got fixed: **what broke — why — the fix.**

## Phase 0 — Setup

- PHP and Composer were not installed — fresh Windows machine — installed via the php.new (Laravel Herd) installer; terminal restart needed to pick up PATH.
- `php` not found from Git Bash even after install — Herd exposes `php.bat`/`composer.bat`, which Bash can't run as `php` — run PHP/Composer commands from PowerShell (or call `php.exe` directly).
- `config/services.php` flipped to CRLF line endings — Python text-mode writes on Windows convert `\n` to `\r\n` — normalized back to LF with `sed -i 's/\r$//'`; use Edit/sed for file edits instead.

## Phase 1 — Data

- Seed-data test failed on valid data — Pest's `toContain()` treats every argument as a value to search for, so my custom failure message was searched for as PII — switched to `expect(str_contains(...))->toBeTrue($message)`.
