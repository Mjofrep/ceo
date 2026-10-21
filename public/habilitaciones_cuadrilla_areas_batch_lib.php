<?php
declare(strict_types=1);

// Compatibility layer for PHP workers that still have the previous areas library cached.
if (!function_exists('hcaHeadersFromRequest')) {
    function hcaHeadersFromRequest(PDO $pdo, array $request): array
    {
        $rawIds = [];
        if (array_key_exists('ids', $request)) {
            if (!is_array($request['ids'])) {
                throw new InvalidArgumentException('Selección de cuadrillas inválida.');
            }
            $rawIds = $request['ids'];
        }
        if (array_key_exists('id', $request)) {
            $rawIds[] = $request['id'];
        }
        if (!$rawIds) {
            throw new InvalidArgumentException('Debe seleccionar al menos una cuadrilla.');
        }

        $ids = [];
        foreach ($rawIds as $rawId) {
            if (!is_scalar($rawId) || filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new InvalidArgumentException('Selección de cuadrillas inválida.');
            }
            $ids[(int)$rawId] = (int)$rawId;
        }

        $headers = [];
        $serviceId = null;
        foreach ($ids as $id) {
            $header = hcaHeader($pdo, $id);
            if (!$header) {
                throw new OutOfBoundsException('Una de las habilitaciones seleccionadas no existe o no está autorizada.');
            }
            if ($serviceId !== null && $serviceId !== (int)$header['id_servicio']) {
                throw new InvalidArgumentException('Las cuadrillas seleccionadas deben pertenecer al mismo servicio.');
            }
            $serviceId = (int)$header['id_servicio'];
            $headers[] = $header;
        }
        return $headers;
    }
}

if (!function_exists('hcaCombinedSummary')) {
    function hcaCombinedSummary(PDO $pdo, array $headers, string $type): array
    {
        if (!in_array($type, ['teorica', 'terreno'], true)) {
            throw new InvalidArgumentException('Tipo de resumen inválido.');
        }

        $areas = [];
        $participants = [];
        foreach ($headers as $header) {
            $summary = $type === 'teorica'
                ? hcaTheoreticalSummary($pdo, $header)
                : hcaTerrainSummary($pdo, $header);
            foreach ($summary['areas'] as $area) {
                $areas[(string)$area['id_area']] = $area;
            }
            foreach ($summary['participants'] as $participant) {
                $participant['cuadrilla'] = (string)$header['cuadrilla'];
                $participants[] = $participant;
            }
        }
        return ['areas' => array_values($areas), 'participants' => $participants];
    }
}
