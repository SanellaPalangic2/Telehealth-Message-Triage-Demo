# Build Log

One line per thing that broke and got fixed: **what broke — why — the fix.**

## Phase 0 — Setup

- PHP and Composer were not installed — fresh Windows machine — installed via the php.new (Laravel Herd) installer; terminal restart needed to pick up PATH.
- `php` not found from Git Bash even after install — Herd exposes `php.bat`/`composer.bat`, which Bash can't run as `php` — run PHP/Composer commands from PowerShell (or call `php.exe` directly).
- `config/services.php` flipped to CRLF line endings — Python text-mode writes on Windows convert `\n` to `\r\n` — normalized back to LF with `sed -i 's/\r$//'`; use Edit/sed for file edits instead.
