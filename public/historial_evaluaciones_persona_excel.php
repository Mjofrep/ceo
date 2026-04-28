<?php
declare(strict_types=1);
session_start();

require_once '../config/db.php';
require_once '../config/app.php';

if (empty($_SESSION['auth'])) {
    exit('No autorizado');
}

$pdo = db();

$rut = trim($_GET['rut'] ?? '');
$rutNormalizado = preg_replace('/\s+/', '', $rut);
if ($rutNormalizado === '') exit('RUT requerido');

$stmt = $pdo->prepare("
    SELECT *
    FROM (
        SELECT
            'TEORICA' AS tipo_evaluacion,
            sp.servicio AS servicio,
            CONCAT(rpi.fecha_rendicion, ' ', rpi.hora_rendicion) AS fecha_hora,
            CASE
                WHEN rpi.puntaje_total >= 80 THEN 'APROBADO'
                ELSE 'REPROBADO'
            END AS resultado_mostrado,
            rpi.notafinal AS nota_mostrada,
            emp.nombre AS empresa,
            cargo.cargo AS cargo,
            CASE
                WHEN rpi.id_evaluador IS NULL THEN 'Carga histórica'
                ELSE TRIM(CONCAT(COALESCE(usr.nombres, ''), ' ', COALESCE(usr.apellidos, '')))
            END AS evaluador,
            uo.desc_uo AS uo,
            '' AS region
        FROM ceo_resultado_prueba_intento rpi
        INNER JOIN ceo_servicios_pruebas sp ON sp.id = rpi.id_servicio
        LEFT JOIN ceo_contratistas ct ON ct.rut = rpi.rut
        LEFT JOIN ceo_empresas emp ON emp.id = ct.id_empresa
        LEFT JOIN ceo_cargo_contratistas cargo ON cargo.id = ct.id_cargo
        LEFT JOIN ceo_uo uo ON uo.id = ct.uo
        LEFT JOIN ceo_usuarios usr ON usr.id = rpi.id_evaluador
        WHERE rpi.rut = :rut_teorica

        UNION ALL

        SELECT
            'PRACTICA' AS tipo_evaluacion,
            sp2.servicio AS servicio,
            et.fecha_evaluacion AS fecha_hora,
            CASE
                WHEN CAST(REPLACE(COALESCE(et.resultado, '0'), ',', '.') AS DECIMAL(10,2)) >= 70 THEN 'APROBADO'
                ELSE 'REPROBADO'
            END AS resultado_mostrado,
            CAST(REPLACE(COALESCE(et.resultado, '0'), ',', '.') AS DECIMAL(10,2)) AS nota_mostrada,
            emp2.nombre AS empresa,
            COALESCE(et.cargo, cargo2.cargo) AS cargo,
            COALESCE(et.evaluador, '') AS evaluador,
            uo2.desc_uo AS uo,
            '' AS region
        FROM ceo_evaluacion_terreno et
        INNER JOIN ceo_servicios_pruebas sp2 ON sp2.id = et.id_servicio
        LEFT JOIN ceo_contratistas ct2 ON ct2.rut = et.rut
        LEFT JOIN ceo_empresas emp2 ON emp2.id = ct2.id_empresa
        LEFT JOIN ceo_cargo_contratistas cargo2 ON cargo2.id = ct2.id_cargo
        LEFT JOIN ceo_uo uo2 ON uo2.id = ct2.uo
        WHERE et.rut = :rut_terreno
    ) historial
    ORDER BY fecha_hora DESC
");
$stmt->execute([
    ':rut_teorica' => $rutNormalizado,
    ':rut_terreno' => $rutNormalizado,
]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/vnd.ms-excel');
header("Content-Disposition: attachment; filename=historial_evaluaciones_$rut.xls");

echo "<table border='1'>";
echo "<tr>
<th>Tipo</th><th>Servicio</th><th>Fecha</th><th>Resultado</th>
<th>Nota</th><th>Empresa</th><th>Cargo</th>
<th>Evaluador</th><th>UO</th><th>Región</th>
</tr>";

foreach ($rows as $r) {
    echo "<tr>
    <td>{$r['tipo_evaluacion']}</td>
    <td>{$r['servicio']}</td>
    <td>{$r['fecha_hora']}</td>
    <td>{$r['resultado_mostrado']}</td>
    <td>{$r['nota_mostrada']}</td>
    <td>{$r['empresa']}</td>
    <td>{$r['cargo']}</td>
    <td>{$r['evaluador']}</td>
    <td>{$r['uo']}</td>
    <td>{$r['region']}</td>
    </tr>";
}
echo "</table>";
