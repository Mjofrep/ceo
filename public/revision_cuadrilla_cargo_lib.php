<?php
declare(strict_types=1);

function revisionCargoHabilitacionCuadrilla(PDO $pdo, string $rut, int $cuadrilla): array
{
    $stmt = $pdo->prepare('
        SELECT hp.cargo AS cargo_cuadrilla, ch.id AS id_cargo, ch.cargo AS cargo_habilitacion
        FROM ceo_habilitacion_participantes hp
        LEFT JOIN ceo_cargos_habilitacion ch ON UPPER(TRIM(ch.cargo)) COLLATE utf8mb4_unicode_ci = UPPER(TRIM(hp.cargo)) COLLATE utf8mb4_unicode_ci
        WHERE REPLACE(REPLACE(REPLACE(UPPER(hp.rut), ".", ""), "-", ""), " ", "") = REPLACE(REPLACE(REPLACE(UPPER(:rut), ".", ""), "-", ""), " ", "")
          AND hp.id_cuadrilla = :cuadrilla
        ORDER BY hp.id DESC
        LIMIT 1
    ');
    $stmt->execute([
        ':rut' => trim($rut),
        ':cuadrilla' => $cuadrilla,
    ]);
    $cargo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cargo) {
        throw new RuntimeException('No se encontró el participante en la cuadrilla.');
    }
    if ((int)($cargo['id_cargo'] ?? 0) <= 0) {
        throw new RuntimeException('El cargo de la cuadrilla no existe en los cargos de habilitación.');
    }

    return $cargo;
}
