# Triage Desk

A generic telehealth message triage demo, built as a Laravel portfolio project
to show safe AI workflow design.

Stack: Laravel 13, PHP 8.4, SQLite, Blade, Pest. Claude is called through the
Laravel Http client against the Anthropic Messages API
(`POST https://api.anthropic.com/v1/messages`, headers `x-api-key` and
`anthropic-version: 2023-06-01`).

## Ground rules

### Data
- Use ONLY synthetic data. No real company names, real people, or real
  patient data anywhere: code, seeds, tests, docs, or commit messages.

### Secrets
- `ANTHROPIC_API_KEY` and `ANTHROPIC_MODEL` come from `.env`, read through
  `config('services.anthropic.*')`. Never call `env()` outside `config/`.
- Never hardcode the key, never log it, never send it to the browser, never
  include it in exceptions or error messages shown to users.
- `.env` is gitignored. Only `.env.example` (with empty/placeholder values) is
  committed.

### AI safety
- `App\Services\AiTriageService` is the ONLY class that calls Claude.
- Text is passed through `RedactionService` BEFORE it reaches the AI. Only
  redacted text is stored in `ai_analyses`.
- Red-flag (emergency) messages are caught by the deterministic `RulesEngine`
  and SKIP the AI entirely.
- The AI never changes data on its own. It only creates suggestions that a
  human approves, edits, or rejects.
- AI output is forced through a required tool with a JSON schema and is
  validated server-side (`AiOutputValidator`). Invalid output is rejected.
  Current models (e.g. `claude-opus-5-5`) return 400 for a forced
  `tool_choice` (`tool`/`any`), so we use `tool_choice: auto` +
  `strict: true` + a system-prompt instruction, and reject any response
  without the tool call. Don't "fix" this back to a forced tool_choice.
- If the AI call fails or returns invalid output, show a clear error. NO fake
  or fallback output, ever.
- Confidence below 0.6 always requires human review.
- Render all model text escaped (`{{ }}` in Blade, never `{!! !!}`).
- Every action writes an append-only audit log entry tagged `AI`, `RULE`, or
  `HUMAN`. Audit rows are never updated or deleted.

### Tests
- Tests must NEVER call the real API. Use `Http::fake()`.
  `Tests\TestCase` calls `Http::preventStrayRequests()` as a safety net.
- Run tests with `php artisan test` (or `vendor/bin/pest`).

### Process
- Work in phases. After each phase: stop, summarize, explain the Laravel
  concepts, give manual test steps, and wait for "next".
- Every time something breaks and we fix it, add ONE line to `BUILD_LOG.md`:
  what broke, why, the fix.
- Commits must NOT include a `Co-Authored-By: Claude` trailer.
