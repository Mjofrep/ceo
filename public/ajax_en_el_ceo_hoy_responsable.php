<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/Csrf.php';

header('Content-Type: application/json; charset=utf-8');

function echrRespond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function echrEnsureTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS ceo_en_el_ceo_hoy_responsable (
        id INT NOT NULL AUTO_INCREMENT,
        fecha DATE NOT NULL,
        nsolicitud INT NOT NULL,
        responsable_nombre VARCHAR(160) NOT NULL,
        updated_by INT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_ceo_hoy_fecha_solicitud (fecha, nsolicitud),
        KEY idx_ceo_hoy_fecha (fecha)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echrRespond(405, ['ok' => false, 'error' => 'Metodo no permitido.']);
}

if (!Csrf::validate($_POST['csrf'] ?? null)) {
    echrRespond(419, ['ok' => false, 'error' => 'Token CSRF invalido.']);
}

$accion = trim((string)($_POST['accion'] ?? ''));
$fecha = trim((string)($_POST['fecha'] ?? ''));
$nsolicitud = (int)($_POST['nsolicitud'] ?? 0);
$responsable = trim((string)($_POST['responsable_nombre'] ?? ''));

if (!in_array($accion, ['guardar', 'limpiar'], true)) {
    echrRespond(400, ['ok' => false, 'error' => 'Accion invalida.']);
}

$dt = DateTimeImmutable::createFromFormat('Y-m-d', $fecha);
if (!$dt || $dt->format('Y-m-d') !== $fecha) {
    echrRespond(400, ['ok' => false, 'error' => 'Fecha invalida.']);
}

if ($nsolicitud <= 0) {
    echrRespond(400, ['ok' => false, 'error' => 'Solicitud invalida.']);
}

if ($accion === 'guardar' && $responsable === '') {
    echrRespond(400, ['ok' => false, 'error' => 'Debe ingresar un nombre.']);
}

if (mb_strlen($responsable, 'UTF-8') > 160) {
    echrRespond(400, ['ok' => false, 'error' => 'El nombre supera el largo permitido.']);
}

$pdo = db();

try {
    echrEnsureTable($pdo);

    $stmtSolicitud = $pdo->prepare('SELECT 1 FROM ceo_solicitudes WHERE nsolicitud = :nsolicitud AND fecha = :fecha AND estado = "A" LIMIT 1');
    $stmtSolicitud->execute([
        ':nsolicitud' => $nsolicitud,
        ':fecha' => $fecha,
    ]);

    if (!$stmtSolicitud->fetchColumn()) {
        echrRespond(404, ['ok' => false, 'error' => 'No se encontro una actividad autorizada para esa solicitud en la fecha indicada.']);
    }

    if ($accion === 'limpiar') {
        $stmtDelete = $pdo->prepare('DELETE FROM ceo_en_el_ceo_hoy_responsable WHERE fecha = :fecha AND nsolicitud = :nsolicitud');
        $stmtDelete->execute([
            ':fecha' => $fecha,
            ':nsolicitud' => $nsolicitud,
        ]);

        echrRespond(200, ['ok' => true, 'responsable' => null]);
    }

    $stmtUpsert = $pdo->prepare(
        'INSERT INTO ceo_en_el_ceo_hoy_responsable (fecha, nsolicitud, responsable_nombre, updated_by)
         VALUES (:fecha, :nsolicitud, :responsable_nombre, :updated_by)
         ON DUPLICATE KEY UPDATE
           responsable_nombre = VALUES(responsable_nombre),
           updated_by = VALUES(updated_by),
           updated_at = CURRENT_TIMESTAMP'
    );
    $stmtUpsert->execute([
        ':fecha' => $fecha,
        ':nsolicitud' => $nsolicitud,
        ':responsable_nombre' => $responsable,
        ':updated_by' => (int)($_SESSION['auth']['id'] ?? 0) ?: null,
    ]);

    echrRespond(200, ['ok' => true, 'responsable' => $responsable]);
} catch (Throwable $e) {
    error_log('ajax_en_el_ceo_hoy_responsable.php: ' . $e->getMessage());
    echrRespond(500, ['ok' => false, 'error' => 'No fue posible actualizar el responsable del dia.']);
}
