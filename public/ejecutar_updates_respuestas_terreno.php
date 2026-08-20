<?php
declare(strict_types=1);

ini_set('memory_limit', '512M');
ini_set('max_execution_time', '0');
@set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/functions.php';

const TERRENO_SQL_EXEC_SESSION_KEY = 'terreno_sql_exec_state';
const TERRENO_SQL_EXEC_CHUNK_SIZE = 250;

$idRol = (int)($_SESSION['auth']['id_rol'] ?? 0);
if ($idRol !== 1) {
    header('Location: ' . app_url('/public/general.php'));
    exit;
}

$pdo = db();
$sqlPath = dirname(__DIR__) . '/docs/update_respuestas_terreno_historicas.sql';
$mensaje = '';
$mensajeTipo = 'info';
$state = terrenoSqlExecLoadState();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? '');

    if ($accion === 'procesar_segmento') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            if (!is_array($state) || ($state['status'] ?? '') !== 'running') {
                throw new RuntimeException('No hay una ejecución en curso.');
            }
            $state = terrenoSqlExecRunChunk($pdo, $state, TERRENO_SQL_EXEC_CHUNK_SIZE);
            terrenoSqlExecSaveState($state);
            echo json_encode([
                'ok' => true,
                'done' => ($state['status'] ?? '') === 'done',
                'executed' => (int)($state['executed'] ?? 0),
                'failed' => (int)($state['failed'] ?? 0),
                'total' => (int)($state['total'] ?? 0),
                'percent' => terrenoSqlExecPercent((int)($state['executed'] ?? 0) + (int)($state['failed'] ?? 0), (int)($state['total'] ?? 0)),
                'last_error' => (string)($state['last_error'] ?? ''),
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($accion === 'iniciar') {
        try {
            terrenoSqlExecClearState();
            $state = terrenoSqlExecInit($sqlPath);
            terrenoSqlExecSaveState($state);
            $mensaje = 'Ejecución iniciada. La página aplicará el SQL por bloques.';
            $mensajeTipo = 'success';
        } catch (Throwable $e) {
            terrenoSqlExecClearState();
            $state = null;
            $mensaje = $e->getMessage();
            $mensajeTipo = 'danger';
        }
    } elseif ($accion === 'reiniciar') {
        terrenoSqlExecClearState();
        $state = null;
        $mensaje = 'Estado de ejecución eliminado.';
        $mensajeTipo = 'secondary';
    }
}

function terrenoSqlExecInit(string $sqlPath): array
{
    if (!is_file($sqlPath)) {
        throw new RuntimeException('No se encontró el archivo SQL esperado en docs/update_respuestas_terreno_historicas.sql');
    }

    $statements = terrenoSqlExecParseStatements($sqlPath);
    if ($statements === []) {
        throw new RuntimeException('El archivo SQL no contiene sentencias UPDATE para ejecutar.');
    }

    return [
        'status' => 'running',
        'sql_path' => $sqlPath,
        'statements' => $statements,
        'total' => count($statements),
        'offset' => 0,
        'executed' => 0,
        'failed' => 0,
        'errors' => [],
        'last_error' => '',
        'started_at' => date('Y-m-d H:i:s'),
        'finished_at' => '',
    ];
}

function terrenoSqlExecRunChunk(PDO $pdo, array $state, int $chunkSize): array
{
    $offset = (int)($state['offset'] ?? 0);
    $statements = $state['statements'] ?? [];
    $chunk = array_slice($statements, $offset, $chunkSize);
    if ($chunk === []) {
        $state['status'] = 'done';
        $state['finished_at'] = date('Y-m-d H:i:s');
        return $state;
    }

    foreach ($chunk as $statement) {
        try {
            $pdo->exec((string)$statement['sql']);
            $state['executed'] = (int)$state['executed'] + 1;
        } catch (Throwable $e) {
            $state['failed'] = (int)$state['failed'] + 1;
            $state['last_error'] = 'Sentencia ' . (int)$statement['index'] . ': ' . $e->getMessage();
            if (count($state['errors']) < 50) {
                $state['errors'][] = [
                    'index' => (int)$statement['index'],
                    'sql' => (string)$statement['sql'],
                    'error' => $e->getMessage(),
                ];
            }
        }
        $state['offset'] = (int)$state['offset'] + 1;
    }

    if ((int)$state['offset'] >= (int)$state['total']) {
        $state['status'] = 'done';
        $state['finished_at'] = date('Y-m-d H:i:s');
    }

    return $state;
}

function terrenoSqlExecParseStatements(string $sqlPath): array
{
    $lines = file($sqlPath, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        throw new RuntimeException('No fue posible leer el archivo SQL.');
    }

    $statements = [];
    $buffer = [];
    $index = 0;
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--')) {
            continue;
        }
        if (in_array(strtoupper($trimmed), ['START TRANSACTION;', 'COMMIT;'], true)) {
            continue;
        }

        $buffer[] = $line;
        if (str_ends_with($trimmed, ';')) {
            $sql = trim(implode("\n", $buffer));
            $buffer = [];
            if ($sql === '') {
                continue;
            }
            $index++;
            $statements[] = [
                'index' => $index,
                'sql' => $sql,
            ];
        }
    }

    return $statements;
}

function terrenoSqlExecStateFile(): string
{
    $dir = sys_get_temp_dir() . '/ceo_terreno_sql_exec';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('No fue posible crear el directorio temporal de ejecución.');
    }
    return $dir . '/state_' . session_id() . '.bin';
}

function terrenoSqlExecSaveState(array $state): void
{
    $path = terrenoSqlExecStateFile();
    if (file_put_contents($path, serialize($state)) === false) {
        throw new RuntimeException('No fue posible guardar el estado de ejecución.');
    }
    $_SESSION[TERRENO_SQL_EXEC_SESSION_KEY] = ['state_file' => $path];
}

function terrenoSqlExecLoadState(): ?array
{
    $meta = $_SESSION[TERRENO_SQL_EXEC_SESSION_KEY] ?? null;
    if (!is_array($meta) || empty($meta['state_file'])) {
        return null;
    }
    $path = (string)$meta['state_file'];
    if (!is_file($path)) {
        unset($_SESSION[TERRENO_SQL_EXEC_SESSION_KEY]);
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        unset($_SESSION[TERRENO_SQL_EXEC_SESSION_KEY]);
        return null;
    }
    $state = unserialize($raw, ['allowed_classes' => false]);
    return is_array($state) ? $state : null;
}

function terrenoSqlExecClearState(): void
{
    $meta = $_SESSION[TERRENO_SQL_EXEC_SESSION_KEY] ?? null;
    if (is_array($meta) && !empty($meta['state_file']) && is_file((string)$meta['state_file'])) {
        @unlink((string)$meta['state_file']);
    }
    unset($_SESSION[TERRENO_SQL_EXEC_SESSION_KEY]);
}

function terrenoSqlExecPercent(int $done, int $total): int
{
    if ($total <= 0) {
        return 0;
    }
    return (int)max(0, min(100, round(($done / $total) * 100)));
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Ejecutar Updates Respuestas Terreno | <?= esc(APP_NAME) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { min-height: 100vh; background: #f8fbff; color: #0f172a; }
.shell { max-width: 1100px; }
.card-soft { background: #fff; border: 1px solid rgba(148,163,184,.18); border-radius: 24px; box-shadow: 0 16px 40px rgba(15,23,42,.08); }
.tiny-note { font-size: .82rem; color: #64748b; }
</style>
</head>
<body>
<div class="container py-4 shell">
    <div class="card-soft p-4 p-lg-5 mb-4">
        <h1 class="h3 mb-3">Ejecutar Updates Respuestas Terreno</h1>
        <p class="tiny-note mb-2">Esta página ejecuta por bloques el archivo <code>docs/update_respuestas_terreno_historicas.sql</code>.</p>
        <p class="tiny-note mb-4">Úsala una vez, valida el resultado y luego elimínala del hosting si así lo deseas.</p>

        <?php if ($mensaje !== ''): ?>
            <div class="alert alert-<?= esc($mensajeTipo) ?>"><?= esc($mensaje) ?></div>
        <?php endif; ?>

        <div class="mb-3"><strong>Archivo SQL:</strong> <code><?= esc($sqlPath) ?></code></div>

        <?php if (!is_file($sqlPath)): ?>
            <div class="alert alert-danger mb-0">No existe el archivo SQL esperado en <code>docs/update_respuestas_terreno_historicas.sql</code>.</div>
        <?php else: ?>
            <div class="d-flex flex-wrap gap-2 mb-4">
                <?php if (!is_array($state) || ($state['status'] ?? '') === 'done'): ?>
                    <form method="post">
                        <input type="hidden" name="accion" value="iniciar">
                        <button type="submit" class="btn btn-primary">Iniciar Ejecución</button>
                    </form>
                <?php endif; ?>
                <?php if (is_array($state)): ?>
                    <form method="post">
                        <input type="hidden" name="accion" value="reiniciar">
                        <button type="submit" class="btn btn-outline-secondary">Limpiar Estado</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (is_array($state)): ?>
            <div class="progress mb-3" role="progressbar" aria-valuemin="0" aria-valuemax="100">
                <div class="progress-bar progress-bar-striped <?= ($state['status'] ?? '') === 'running' ? 'progress-bar-animated' : '' ?>" id="execBar" style="width: <?= terrenoSqlExecPercent((int)($state['executed'] ?? 0) + (int)($state['failed'] ?? 0), (int)($state['total'] ?? 0)) ?>%"></div>
            </div>
            <div class="row g-3 mb-4">
                <div class="col-md-3"><div class="border rounded p-3"><div class="tiny-note">Estado</div><div id="execStatus"><?= esc((string)($state['status'] ?? '')) ?></div></div></div>
                <div class="col-md-3"><div class="border rounded p-3"><div class="tiny-note">Total</div><div id="execTotal"><?= (int)($state['total'] ?? 0) ?></div></div></div>
                <div class="col-md-3"><div class="border rounded p-3"><div class="tiny-note">Ejecutadas</div><div id="execDone"><?= (int)($state['executed'] ?? 0) ?></div></div></div>
                <div class="col-md-3"><div class="border rounded p-3"><div class="tiny-note">Fallidas</div><div id="execFailed"><?= (int)($state['failed'] ?? 0) ?></div></div></div>
            </div>
            <div class="tiny-note mb-3" id="execError"><?= esc((string)($state['last_error'] ?? '')) ?></div>

            <?php if (!empty($state['errors']) && is_array($state['errors'])): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="table-light"><tr><th>#</th><th>Error</th></tr></thead>
                        <tbody>
                        <?php foreach ($state['errors'] as $error): ?>
                            <tr>
                                <td><?= (int)($error['index'] ?? 0) ?></td>
                                <td><?= esc((string)($error['error'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if (is_array($state) && ($state['status'] ?? '') === 'running'): ?>
<script>
let sqlRunning = false;
async function runChunk() {
    if (sqlRunning) return;
    sqlRunning = true;
    try {
        const formData = new FormData();
        formData.append('accion', 'procesar_segmento');
        const response = await fetch(window.location.href, { method: 'POST', body: formData, credentials: 'same-origin' });
        const result = await response.json();
        if (!result.ok) throw new Error(result.error || 'No fue posible continuar la ejecución.');
        document.getElementById('execBar').style.width = `${result.percent}%`;
        document.getElementById('execDone').textContent = String(result.executed || 0);
        document.getElementById('execFailed').textContent = String(result.failed || 0);
        document.getElementById('execTotal').textContent = String(result.total || 0);
        document.getElementById('execStatus').textContent = result.done ? 'done' : 'running';
        document.getElementById('execError').textContent = result.last_error || '';
        if (result.done) {
            window.location.reload();
            return;
        }
        sqlRunning = false;
        setTimeout(runChunk, 100);
    } catch (error) {
        sqlRunning = false;
        document.getElementById('execError').textContent = error.message || 'Error inesperado durante la ejecución.';
    }
}
runChunk();
</script>
<?php endif; ?>
</body>
</html>
