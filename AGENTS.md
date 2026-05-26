# AGENTS

## Repo Facts

- Procedural PHP app for CEONext; page controllers, views, AJAX endpoints, and Excel exports live under `public/`.
- `config/` holds DB/session/app helpers; `src/` has small shared classes such as `Auth` and `Csrf`.
- There is no root `composer.json`, `package.json`, CI workflow, lint config, or automated test runner in this repo.
- Runtime is expected through MAMP/Apache with MySQL; many routes assume `/ceo.noetica.cl` from `APP_BASE` or hard-coded links.
- `vendor/` is committed and includes dompdf, PhpSpreadsheet, PHPMailer, and related libraries.

## Verify Changes

- Syntax-check touched PHP files with `php -l path/to/file.php`.
- For functional checks, run the affected page or AJAX endpoint through the local MAMP site; there is no single-test command.
- If a page has a matching `*_excel.php` export, check whether the same data/format change must be mirrored there.

## Coding Constraints

- Keep changes small and local; most pages mix query, controller logic, HTML, inline CSS, and JS in one file.
- Use PDO prepared statements for user input; existing tables use the `ceo_` prefix.
- Escape HTML output with `esc()` or `htmlspecialchars`; use `json_encode(..., JSON_UNESCAPED_UNICODE)` for embedded JS data.
- Do not move files casually: links and redirects are often hard-coded.
- Keep Spanish UI terminology consistent: solicitud, habilitacion, evaluacion, terreno, pruebas, vigencia.

## Auth And Forms

- Protected pages either include `config/auth.php` or manually check `$_SESSION['auth']`; follow the local pattern of the file being edited.
- `config/auth.php` enforces a 2-hour idle timeout and no-cache headers.
- POST forms should use `src/Csrf.php` when the surrounding flow already uses CSRF tokens.

## Domain Gotchas

- Evaluation summaries often combine theoretical results from `ceo_resultado_prueba_intento` with terrain/practical results from `ceo_evaluacion_terreno` or `ceo_resultado_terreno_intento`.
- `config/functions.php::calcularNotaFinalDesdePorcentaje()` converts a percentage to the normalized 1-7 grade scale; do not replace percentage display with normalized grades unless the UI asks for it.
- Common statuses are stored as uppercase strings such as `APROBADO`, `REPROBADO`, `EJECUTADA`, and `A`.
