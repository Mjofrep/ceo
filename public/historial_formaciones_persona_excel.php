<?php
declare(strict_types=1);
session_start();

require_once '../config/db.php';
require_once '../config/app.php';

if (empty($_SESSION['auth'])) {
    exit('No autorizado');
}

$pdo = db();

$rolUsuario = strtolower((string)($_SESSION['auth']['rol'] ?? ''));
$idEmpresaUser = (int)($_SESSION['auth']['id_empresa'] ?? 0);
$esContratista = ($rolUsuario === 'contratista');

$rut = trim((string)($_GET['rut'] ?? ''));
if ($rut === '') {
    exit('RUT requerido');
}

if ($esContratista) {
    $stmtAuth = $pdo->prepare('
        SELECT 1
        FROM ceo_contratistas
        WHERE rut = :rut AND id_empresa = :empresa
    ');
    $stmtAuth->execute([
        ':rut' => $rut,
        ':empresa' => $idEmpresaUser,
    ]);

    if (!$stmtAuth->fetch()) {
        exit('No autorizado para ver este RUT.');
    }
}

$stmt = $pdo->prepare("
    SELECT
        fp.rut,
        COALESCE(p.nombre, '') AS nombre,
        COALESCE(p.apellidos, '') AS apellidos,
        fs.servicio,
        COALESCE(fp.fecha_resultado, fp.fecha_termino, fp.fecha_programacion) AS fecha_hora,
        UPPER(TRIM(COALESCE(fp.resultado, 'PENDIENTE'))) AS resultado_mostrado,
        (
            SELECT ri.notafinal
            FROM ceo_resultado_formacion_intento ri
            WHERE ri.rut = fp.rut
              AND ri.id_servicio = fp.id_servicio
              AND (
                fp.fecha_resultado IS NULL
                OR TIMESTAMP(ri.fecha_rendicion, ri.hora_rendicion) <= fp.fecha_resultado
              )
            ORDER BY TIMESTAMP(ri.fecha_rendicion, ri.hora_rendicion) DESC, ri.id DESC
            LIMIT 1
        ) AS nota_mostrada,
        (
            SELECT ri.puntaje_total
            FROM ceo_resultado_formacion_intento ri
            WHERE ri.rut = fp.rut
              AND ri.id_servicio = fp.id_servicio
              AND (
                fp.fecha_resultado IS NULL
                OR TIMESTAMP(ri.fecha_rendicion, ri.hora_rendicion) <= fp.fecha_resultado
              )
            ORDER BY TIMESTAMP(ri.fecha_rendicion, ri.hora_rendicion) DESC, ri.id DESC
            LIMIT 1
        ) AS porcentaje_mostrado,
        ce.nombre AS empresa,
        p.cargo,
        uo.desc_uo AS uo,
        f.cuadrilla,
        fp.intento,
        (
            SELECT CONCAT(COALESCE(u.nombres, ''), ' ', COALESCE(u.apellidos, ''))
            FROM ceo_resultado_formacion_intento ri
            LEFT JOIN ceo_usuarios u ON u.id = ri.id_evaluador
            WHERE ri.rut = fp.rut
              AND ri.id_servicio = fp.id_servicio
              AND (
                fp.fecha_resultado IS NULL
                OR TIMESTAMP(ri.fecha_rendicion, ri.hora_rendicion) <= fp.fecha_resultado
              )
            ORDER BY TIMESTAMP(ri.fecha_rendicion, ri.hora_rendicion) DESC, ri.id DESC
            LIMIT 1
        ) AS evaluador
    FROM ceo_formacion_programadas fp
    LEFT JOIN (
        SELECT f1.*
        FROM ceo_formacion f1
        INNER JOIN (
            SELECT cuadrilla, MAX(id) AS max_id
            FROM ceo_formacion
            GROUP BY cuadrilla
        ) f2 ON f1.id = f2.max_id
    ) f ON f.cuadrilla = fp.cuadrilla
    LEFT JOIN ceo_formacion_servicios fs ON fs.id = fp.id_servicio
    LEFT JOIN ceo_formacion_participantes p ON p.rut = fp.rut AND p.id_cuadrilla = fp.cuadrilla
    LEFT JOIN ceo_empresas ce ON ce.id = f.empresa
    LEFT JOIN ceo_uo uo ON uo.id = f.uo
    WHERE fp.rut = :rut
      AND UPPER(TRIM(COALESCE(fp.estado, ''))) <> 'ANULADA'
    ORDER BY fecha_hora DESC, fp.id DESC
");
$stmt->execute([':rut' => $rut]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/vnd.ms-excel');
header("Content-Disposition: attachment; filename=historial_formaciones_$rut.xls");

echo "<table border='1'>";
echo '<tr>';
echo '<th>RUT</th>';
echo '<th>Nombre</th>';
echo '<th>Apellido</th>';
echo '<th>Servicio</th>';
echo '<th>Fecha</th>';
echo '<th>Resultado</th>';
echo '<th>Nota</th>';
echo '<th>Porcentaje</th>';
echo '<th>Empresa</th>';
echo '<th>Cargo</th>';
echo '<th>UO</th>';
echo '<th>Cuadrilla</th>';
echo '<th>Intento</th>';
echo '<th>Evaluador</th>';
echo '</tr>';

foreach ($rows as $r) {
    echo '<tr>';
    echo '<td>' . htmlspecialchars((string)($r['rut'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)($r['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)($r['apellidos'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)($r['servicio'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)($r['fecha_hora'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)($r['resultado_mostrado'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)($r['nota_mostrada'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)($r['porcentaje_mostrado'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)($r['empresa'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)($r['cargo'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)($r['uo'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)($r['cuadrilla'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars((string)($r['intento'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars(trim((string)($r['evaluador'] ?? '')), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '</tr>';
}

echo '</table>';
