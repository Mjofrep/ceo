# AGENTS

Purpose: instructions for agentic coding in this repository.

## Repo overview

- Stack: PHP (procedural pages) + MySQL via PDO.
- Webroot: `public/` contains page controllers, views, and AJAX endpoints.
- Config: `config/` contains app settings, auth/session checks, DB connection.
- Shared logic: minimal classes in `src/` (Auth, Csrf) and helpers in `config/functions.php` and `public/functions.php`.
- Vendor libs: `vendor/` includes dompdf, phpspreadsheet, phpmailer, etc.

## Cursor / Copilot rules

- No `.cursor/rules/`, `.cursorrules`, or `.github/copilot-instructions.md` found.

## Build, lint, test commands

- No build system detected (no `package.json`, no root `composer.json`).
- No lint/test runner detected.

Suggested lightweight checks (manual):

- PHP syntax check for a file:
  - `php -l path/to/file.php`
- Run a specific page in browser via local server (MAMP/Apache).

Single-test equivalent:

- There is no test framework. The closest analog is to execute the single PHP page or AJAX endpoint that implements the feature.

## Code style guidelines

### General

- Prefer minimal changes scoped to the relevant page or helper.
- Keep PHP procedural style consistent with existing pages in `public/`.
- Use ASCII-only content unless the file already contains UTF-8 Spanish text (which many files do).

### Files and naming

- Files are snake_case for pages and actions (e.g., `nueva_solicitud.php`, `ajax_*`).
- Functions are camelCase or lower_snake_case depending on existing file; follow local style.
- Variables tend to be lower_snake_case or short Spanish names; match the surrounding file.

### Imports / includes

- Use `require_once` for shared config and helpers when needed.
- Login and session-protected pages commonly include:
  - `config/auth.php` (session check)
  - `config/db.php` (PDO connection)
  - `config/functions.php` or `public/functions.php`

### Formatting

- Keep indentation consistent with the file (often 2 or 4 spaces).
- Avoid large refactors; keep edits near the related logic.

### Types

- Some files use `declare(strict_types=1);` (not universal). Do not add it globally.
- Use explicit casts when reading DB data (e.g., `(int)$row['id']`).

### Database access

- Use PDO prepared statements for user input.
- Keep SQL inline within the page, consistent with existing patterns.
- Return associative arrays with `PDO::FETCH_ASSOC` when needed.
- Avoid echoing SQL or debug output in production flows.

### Error handling

- Use basic guards and early returns for validation errors.
- Display user-friendly messages for authentication and validation errors.
- Use try/catch when accessing DB in critical flows; keep messages generic.

### Security

- Use CSRF tokens for POST forms via `src/Csrf.php`.
- Respect session handling in `config/auth.php` (idle timeout and no-cache headers).
- Do not introduce direct output of unescaped user input; use `esc()` helper when available.

### Output / escaping

- For HTML output, wrap dynamic values with `esc()` or `htmlspecialchars`.
- Use `json_encode` for embedding data in JS, with `JSON_UNESCAPED_UNICODE` when needed.

### Naming and domain conventions

- Tables are prefixed `ceo_`.
- Common domain terms: solicitud, habilitacion, evaluacion, terreno, pruebas, vigencia.
- Status fields often store values like `APROBADO`, `REPROBADO`, `EJECUTADA`, `A`.

## Frontend conventions

- Bootstrap 5 is used via CDN in key pages.
- Inline `<style>` blocks are common per page; avoid new global CSS unless needed.
- Keep responsive layout using Bootstrap grid and utility classes.

## Common page patterns

- Validate request method: `$_SERVER['REQUEST_METHOD'] === 'POST'`.
- Read inputs with `$_POST` or `$_GET`, trim strings, validate empties.
- On success, redirect with `header('Location: ...')` and `exit`.
- Show errors as Bootstrap alerts when present.

## Logging and debugging

- `config/functions.php` includes a `debug()` helper gated by `APP_DEBUG`.
- Avoid leaving debug output or `echo $sql` in final changes.

## Files to know

- `config/app.php`: branding and base path constants.
- `config/db.php`: PDO setup (contains credentials).
- `config/auth.php`: session checks and idle timeout.
- `src/Auth.php`: login logic.
- `src/Csrf.php`: CSRF token management.
- `public/functions.php`: business helpers for evaluation results.

## Editing guidance

- Do not move files unless required; links are often hard-coded.
- Maintain Spanish UI copy; keep consistent terminology.
- Be careful with `APP_BASE` and hard-coded routes under `/ceo.noetica.cl`.

## When adding new endpoints

- Follow the naming pattern `ajax_<feature>.php` for async actions.
- Validate input and return JSON consistently.
- Include `config/auth.php` for protected endpoints.

## When adding new pages

- Include required config and auth.
- Use Bootstrap structure and existing layout conventions.
- Update navigation (if any) only where it exists in current page templates.

## Notes about tests

- There is no automated test suite in this repo.
- Manual testing is expected by running the target page or flow in a local server.
