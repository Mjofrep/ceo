<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/functions.php';

if (!function_exists('iheNormalizeText')) {
    function iheNormalizeText(?string $value): string
    {
        $text = trim((string)$value);
        if ($text === '') {
            return '';
        }

        $text = str_replace(["\xC2\xA0", "\xE2\x80\x8B"], ' ', $text);
        $text = mb_strtoupper($text, 'UTF-8');
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($converted !== false && $converted !== '') {
            $text = $converted;
        }
        $text = preg_replace('/[^A-Z0-9]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        return trim($text);
    }
}

if (!function_exists('iheNormalizeRut')) {
    function iheNormalizeRut(?string $rut): string
    {
        return str_replace(['.', '-', ' '], '', iheNormalizeText((string)$rut));
    }
}

if (!function_exists('iheTrimmedNumber')) {
    function iheTrimmedNumber(?float $value, int $decimals = 1): string
    {
        if ($value === null) {
            return '';
        }

        return rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.');
    }
}

if (!function_exists('iheFmtDate')) {
    function iheFmtDate(?DateTimeImmutable $date): string
    {
        return $date ? $date->format('d-m-Y') : '';
    }
}

if (!function_exists('iheParseDate')) {
    function iheParseDate(?string $value): ?DateTimeImmutable
    {
        $text = trim((string)$value);
        if ($text === '' || $text === '0000-00-00' || $text === '0000-00-00 00:00:00') {
            return null;
        }

        try {
            return new DateTimeImmutable($text);
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('iheParseNullableScore')) {
    function iheParseNullableScore($value): ?float
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }

        $text = str_replace(',', '.', $text);
        return is_numeric($text) ? (float)$text : null;
    }
}

if (!function_exists('iheResolvePesosPorCargoServicio')) {
    function iheResolvePesosPorCargoServicio(?string $cargo, ?int $idCargo = null): ?array
    {
        $operadorIds = [266, 268, 287];
        $supervisorIds = [294];

        if ($idCargo !== null) {
            if (in_array($idCargo, $supervisorIds, true)) {
                return ['teorica' => 0.6, 'terreno' => 0.4];
            }
            if (in_array($idCargo, $operadorIds, true)) {
                return ['teorica' => 0.4, 'terreno' => 0.6];
            }
        }

        $cargoNorm = iheNormalizeText($cargo);
        if ($cargoNorm === '') {
            return null;
        }

        if (
            str_contains($cargoNorm, 'SUPERVISOR') ||
            str_contains($cargoNorm, 'LIDER') ||
            str_contains($cargoNorm, 'CAPATAZ') ||
            str_contains($cargoNorm, 'MAESTRO')
        ) {
            return ['teorica' => 0.6, 'terreno' => 0.4];
        }

        if (
            str_contains($cargoNorm, 'OPERADOR') ||
            str_contains($cargoNorm, 'AYUDANTE') ||
            str_contains($cargoNorm, 'ACOMPAN')
        ) {
            return ['teorica' => 0.4, 'terreno' => 0.6];
        }

        return null;
    }
}

if (!function_exists('iheMapTerrenoRespuestaFlags')) {
    function iheMapTerrenoRespuestaFlags(?string $value): array
    {
        $norm = iheNormalizeText($value);
        if ($norm === '') {
            return [false, false, false];
        }

        if (
            str_contains($norm, 'NO APLICA') ||
            $norm === 'NA' ||
            $norm === 'N A'
        ) {
            return [false, false, true];
        }

        if (
            str_contains($norm, 'ALCANZO') ||
            $norm === 'SI' ||
            str_contains($norm, 'CUMPLE') ||
            str_contains($norm, 'APROB')
        ) {
            return [true, false, false];
        }

        if (
            $norm === 'NO' ||
            str_contains($norm, 'NO ALCANZO') ||
            str_contains($norm, 'REPROB')
        ) {
            return [false, true, false];
        }

        return [false, false, false];
    }
}

if (!function_exists('iheBuildMatchCandidates')) {
    function iheBuildMatchCandidates(string $label): array
    {
        $candidates = [];
        $base = iheNormalizeText($label);
        if ($base !== '') {
            $candidates[] = $base;
        }

        $withoutDots = preg_replace('/\.\d+$/', '', $base) ?? $base;
        if ($withoutDots !== '' && !in_array($withoutDots, $candidates, true)) {
            $candidates[] = $withoutDots;
        }

        $withoutQuotes = str_replace(['"', "'"], '', $withoutDots);
        if ($withoutQuotes !== '' && !in_array($withoutQuotes, $candidates, true)) {
            $candidates[] = $withoutQuotes;
        }

        $collapsed = str_replace([' MT', ' BT'], '', $withoutQuotes);
        if ($collapsed !== '' && !in_array($collapsed, $candidates, true)) {
            $candidates[] = $collapsed;
        }

        return $candidates;
    }
}

if (!function_exists('iheFindBestMatchValue')) {
    function iheFindBestMatchValue(string $label, array $map): ?float
    {
        $candidates = iheBuildMatchCandidates($label);
        foreach ($candidates as $candidate) {
            if (isset($map[$candidate])) {
                return (float)$map[$candidate];
            }
        }

        foreach ($candidates as $candidate) {
            foreach ($map as $key => $value) {
                if ($key === '' || $candidate === '') {
                    continue;
                }
                if (str_contains($key, $candidate) || str_contains($candidate, $key)) {
                    return (float)$value;
                }
            }
        }

        return null;
    }
}

if (!function_exists('iheRowMatchesSearch')) {
    function iheRowMatchesSearch(array $row, array $columns, string $searchTerm): bool
    {
        $needle = iheNormalizeText($searchTerm);
        if ($needle === '') {
            return true;
        }

        foreach ($columns as $column) {
            $value = iheNormalizeText((string)($row[$column] ?? ''));
            if ($value !== '' && str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('iheFilterReportRowsBySearch')) {
    function iheFilterReportRowsBySearch(array $report, string $searchTerm): array
    {
        $needle = iheNormalizeText($searchTerm);
        if ($needle === '') {
            return $report;
        }

        foreach ($report['definitions'] as $definition) {
            $sheetKey = (string)($definition['key'] ?? '');
            if ($sheetKey === '' || !isset($report['sheets'][$sheetKey]['rows'])) {
                continue;
            }

            if (($definition['mode'] ?? '') === 'legend') {
                continue;
            }

            $report['sheets'][$sheetKey]['rows'] = array_values(array_filter(
                $report['sheets'][$sheetKey]['rows'],
                static fn(array $row): bool => iheRowMatchesSearch($row, $definition['columns'] ?? [], $needle)
            ));
        }

        return $report;
    }
}

if (!function_exists('iheNormalizeHabilitadoFilter')) {
    function iheNormalizeHabilitadoFilter(?string $value): string
    {
        $normalized = iheNormalizeText($value);
        return in_array($normalized, ['SI', 'NO'], true) ? $normalized : '';
    }
}

if (!function_exists('iheFilterReportRowsByHabilitado')) {
    function iheFilterReportRowsByHabilitado(array $report, string $habilitadoFilter): array
    {
        $expected = iheNormalizeHabilitadoFilter($habilitadoFilter);
        if ($expected === '') {
            return $report;
        }

        foreach ($report['definitions'] as $definition) {
            $sheetKey = (string)($definition['key'] ?? '');
            if ($sheetKey === '' || !isset($report['sheets'][$sheetKey]['rows'])) {
                continue;
            }

            $columns = $definition['columns'] ?? [];
            if (!in_array('Habilitado', $columns, true)) {
                continue;
            }

            $report['sheets'][$sheetKey]['rows'] = array_values(array_filter(
                $report['sheets'][$sheetKey]['rows'],
                static function (array $row) use ($expected): bool {
                    return iheNormalizeText((string)($row['Habilitado'] ?? '')) === $expected;
                }
            ));
        }

        return $report;
    }
}

if (!function_exists('iheServiceMatchesAliases')) {
    function iheServiceMatchesAliases(string $serviceName, array $aliases): bool
    {
        $serviceNorm = iheNormalizeText($serviceName);
        if ($serviceNorm === '') {
            return false;
        }

        foreach ($aliases as $alias) {
            $aliasNorm = iheNormalizeText((string)$alias);
            if ($aliasNorm === '') {
                continue;
            }
            if ($serviceNorm === $aliasNorm || str_contains($serviceNorm, $aliasNorm) || str_contains($aliasNorm, $serviceNorm)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('iheCompanyNamesMatch')) {
    function iheCompanyNamesMatch(?string $left, ?string $right): bool
    {
        $leftNorm = iheNormalizeText($left);
        $rightNorm = iheNormalizeText($right);
        if ($leftNorm === '' || $rightNorm === '') {
            return false;
        }

        return $leftNorm === $rightNorm
            || str_contains($leftNorm, $rightNorm)
            || str_contains($rightNorm, $leftNorm);
    }
}

if (!function_exists('iheFetchEmpresas')) {
    function iheFetchEmpresas(PDO $pdo): array
    {
        return $pdo->query('SELECT id, nombre FROM ceo_empresas ORDER BY nombre ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('iheResolveEmpresaSeleccionada')) {
    function iheResolveEmpresaSeleccionada(array $sessionAuth, int $requestedEmpresaId): int
    {
        $rol = strtolower(trim((string)($sessionAuth['rol'] ?? '')));
        $empresaSesion = (int)($sessionAuth['id_empresa'] ?? 0);
        if ($rol === 'contratista') {
            return $empresaSesion;
        }

        return $requestedEmpresaId;
    }
}

if (!function_exists('iheFetchLatestWfRowsByRut')) {
    function iheFetchLatestWfRowsByRut(PDO $pdo, array $ruts): array
    {
        $rutKeys = [];
        foreach ($ruts as $rut) {
            $rutKey = iheNormalizeRut((string)$rut);
            if ($rutKey !== '') {
                $rutKeys[$rutKey] = true;
            }
        }

        if (empty($rutKeys)) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($rutKeys), '?'));
        $sql = "
            SELECT id, rut_empleado, contratista, cargo, fecha_carga
            FROM ceo_reportewf
            WHERE REPLACE(REPLACE(REPLACE(UPPER(rut_empleado), '.', ''), '-', ''), ' ', '') IN ($placeholders)
            ORDER BY COALESCE(fecha_carga, '0000-00-00 00:00:00') DESC, id DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_keys($rutKeys));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $byRut = [];
        foreach ($rows as $row) {
            $rutKey = iheNormalizeRut((string)$row['rut_empleado']);
            if ($rutKey === '' || isset($byRut[$rutKey])) {
                continue;
            }
            $byRut[$rutKey] = $row;
        }

        return $byRut;
    }
}

if (!function_exists('iheFetchCompanyContractors')) {
    function iheFetchCompanyContractors(PDO $pdo, int $empresaId): array
    {
        $stmt = $pdo->prepare("
            SELECT
                c.rut,
                c.nombre,
                c.apellidos,
                c.id_cargo,
                cc.cargo,
                c.id_empresa,
                e.nombre AS empresa,
                uo.desc_uo AS uo
            FROM ceo_contratistas c
            LEFT JOIN ceo_cargo_contratistas cc ON cc.id = c.id_cargo
            LEFT JOIN ceo_empresas e ON e.id = c.id_empresa
            LEFT JOIN ceo_uo uo ON uo.id = c.uo
            WHERE c.id_empresa = :empresa
            ORDER BY c.apellidos ASC, c.nombre ASC, c.rut ASC
        ");
        $stmt->execute([':empresa' => $empresaId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $wfByRut = iheFetchLatestWfRowsByRut($pdo, array_column($rows, 'rut'));

        $byRut = [];
        foreach ($rows as $row) {
            $rutKey = iheNormalizeRut((string)$row['rut']);
            if ($rutKey === '') {
                continue;
            }

            $wfRow = $wfByRut[$rutKey] ?? null;
            $wfContratista = trim((string)($wfRow['contratista'] ?? ''));
            if ($wfContratista !== '' && !iheCompanyNamesMatch($wfContratista, (string)($row['empresa'] ?? ''))) {
                continue;
            }

            $byRut[$rutKey] = $row;
        }

        return $byRut;
    }
}

if (!function_exists('iheFetchServicios')) {
    function iheFetchServicios(PDO $pdo): array
    {
        return $pdo->query('SELECT id, servicio FROM ceo_servicios_pruebas ORDER BY servicio ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('iheGetSheetDefinitions')) {
    function iheGetSheetDefinitions(): array
    {
        return [
            [
                'key' => 'estados',
                'title' => 'ESTADOS',
                'mode' => 'legend',
                'columns' => ['ESTADO', 'OBSERVACION'],
            ],
            [
                'key' => 'ssee_dom',
                'title' => 'SSEE DOM',
                'mode' => 'standard',
                'aliases' => ['SSEE DOM', 'DOMICILIO'],
                'cuadrilla_label' => 'Domicilio',
                'columns' => ['RUT', 'Nombres', 'Apellidos', 'Cargos', 'Empresas', 'Cuadrilla', 'Aprobación Prueba', 'NOTA', 'Aprobación Terreno', 'NOTA TERRENO', 'Nota Ponderada', 'Habilitado', 'Fecha de Evaluación', 'Fecha Prueba', 'Contratista', 'Mandante', 'SAGE', 'Estado', 'Empalme', 'Equipo Monofásico', 'Equipos Trifásicos', 'Otros Trabjos Domicilio', 'Seguridad', 'Trabajo en Cajas DAE', 'APERTURA CAJA DE EMPALME', 'CAMBIO BLOCK PRUEBA', 'CAMBIO DE REGLETA', 'CAMBIO DEL INTERRUPTOR MONOFÁSICO', 'CAMBIO DEL INTERRUPTOR TRIFÁSICO', 'CAMBIO PLACA DE LOZA A NUEVO SISTEMA', 'CIERRE DE LA TAPA DE CAJA DE EMPALME', 'EJECUCIÓN CAMBIO O NORMALIZACION DE ACOMETIDA', 'INICIO DE FAENA', 'NOTIFICACIÓN AL CLIENTE', 'Preparación del entorno de trabajo', 'REVISIÓN FINAL', 'USO DE EPP'],
            ],
            [
                'key' => 'cuadrillas_cyr_bt',
                'title' => 'Cuadrillas CyR BT',
                'mode' => 'cuadrillas',
                'aliases' => ['CYR BT', 'CUADRILLAS CYR BT', 'CYR'],
                'columns' => ['RUT', 'Nombre', 'Apellidos', 'Cargo', 'Empresa', 'Fecha Prueba', 'Nota Prueba', 'Prueba', 'Fecha Terreno', 'Nota Terreno', 'Terreno', 'Habilitado', 'Estado', 'RDO'],
            ],
            [
                'key' => 'sub_bt',
                'title' => 'SUB BT',
                'mode' => 'standard',
                'aliases' => ['SUB BT', 'SUBTERRANEA BT'],
                'columns' => ['RUT', 'Nombres', 'Apellidos', 'Empresas', 'Cargos', 'Aprobación Prueba', 'NOTA', 'Aprobación Terreno', 'Nota Terreno', 'Nota Ponderada', 'Habilitado', 'Fecha de Evaluación', 'Fecha Prueba', 'Contratista', 'Mandante', 'SAGE', 'Electricidad', 'Identificacion de Fallas', 'Instalación de Cables Subterráneos', 'Mantenimiento en Redes Subterráneas', 'Medición y Análisis de Parámetros Subterráneos', 'Montaje e Intervención de Uniones de Cables', 'Operación de Equipos', 'Revisión de Equipos', 'Seguridad', 'Ingreso A Espacios Confinados', 'Instalación De Cables Subterráneos Bt', 'Mantenimiento En Redes Subterráneas.1', 'Montaje E Intervención De Uniones De Cables Bt', 'Operaciones En Barras De Distribución Subterráneas Bt', 'Preparación Del Entorno De Trabajo'],
            ],
            [
                'key' => 'ssee_red',
                'title' => 'SSEE RED',
                'mode' => 'dual',
                'components' => [
                    ['slot' => 'MT', 'aliases' => ['SSEE RED MT', 'SSEE RED']],
                    ['slot' => 'BT', 'aliases' => ['SSEE RED BT', 'SSEE RED']],
                ],
                'columns' => ['RUT', 'Nombres', 'Apellidos', 'Cargos', 'Empresas', 'Prueba MT', 'NOTA MT', 'Terreno MT', 'Nota Terreno MT', 'Prueba BT', 'NOTA BT', 'Terreno BT', 'Nota Terreno BT', 'Habilitado MT', 'Habilitado BT', 'Habilitado', 'Fecha de Evaluación', 'Fecha Prueba BT', 'Fecha Prueba MT', 'Contratista', 'Mandante', 'SAGE', 'Estado', 'Operación de Equipos MT', 'Otros Trabajos MT', 'Seguridad', 'Trabajo en Aisladores MT', 'Trabajos en Línea MT', 'Operación de Equipos BT', 'Operación Máquina de prueba BT', 'Otros Trabajos BT', 'Seguridad_1', 'Trabajos de Equilibro de Carga BT', 'Trabajos de Podas BT', 'Trabajos en Equipos BT', 'Trabjos en líneas BT', 'Trabjos en Puentes BT', 'CAMBIO AISLADOR DISCO', 'CAMBIO AISLADOR ESPIGO', 'INICIO DE FAENA', 'INSPECCIÓN VISUAL RED MT', 'OPERACIÓN FUSIBLE MT', 'Preparación del entorno de trabajo', 'REPARACIÓN RED MT TRADICIONAL', 'RETIRO DE OBJETOS EXTRAÑOS DE RED MT', 'REVISIÓN FINAL Y TERMINO DE FAENA', 'USO DE EPP', '"OPERACIÓN BARRA DISTRIBUCIÓN SUBTERRÁNEAS  (MÁQUINA DE PRUEBA)"', 'AISLACIÓN  DE LINEAS BT CORTADAS', 'APERTURA FASES TRIPOLAR O NH', 'APERTURA PUENTES BT', 'CIERRE FASES DEL TRIPOLAR Y NH CON PERTIGA (conexión)', 'CIERRE PUENTES BT', 'EQUILIBRIO Y/O TRASPASO DE CARGAS', 'INICIO DE FAENA_2', 'PODA MENOR', 'Preparación del entorno de trabajo_3', 'REPARACIÓN DE PORTA NH FUNDIDO', 'REPARACIÓN Y TEMPLADO DE LÏNEAS', 'REPOSICIÓN DE FUSBILE BT AEREO', 'REVISIÓN FINAL Y TERMINO DE FAENA_4', 'USO DE EPP_5'],
            ],
            [
                'key' => 'sub_mt',
                'title' => 'SUB MT',
                'mode' => 'standard',
                'aliases' => ['SUB MT', 'SUBTERRANEA MT'],
                'columns' => ['RUT', 'Nombres', 'Apellidos', 'Empresas', 'Cargos', 'Aprobación Prueba', 'NOTA', 'Aprobación Terreno', 'Nota Terreno', 'Nota Ponderada', 'Habilitado', 'Fecha de Evaluación', 'Fecha Prueba', 'Contratista', 'Mandante', 'SAGE', 'Electricidad', 'Identificacion de Fallas', 'Instalación de Cables Subterráneos', 'Mantenimiento en Redes Subterráneas', 'Medición y Análisis de Parámetros Subterráneos', 'Montaje e Intervención de Uniones de Cables', 'Operación de Equipos', 'Revisión de Equipos', 'Seguridad', 'Identificacion De Fallas.1', 'Ingreso A Espacios Confinados', 'Instalación De Cables Subterráneos Mt', 'Mantenimiento En Redes Subterráneas.1', 'Medición Y Análisis De Parámetros Subterráneos.1', 'Montaje E Intervención De Uniones De Cables Mt', 'Operación De Equipos Mt', 'Preparación Del Entorno De Trabajo', 'Revisión De Equipos Mt'],
            ],
            [
                'key' => 'sub_ooee',
                'title' => 'SUB OOEE',
                'mode' => 'standard',
                'aliases' => ['SUB OOEE', 'SUBESTACIONES OOEE'],
                'columns' => ['RUT', 'Nombres', 'Apellidos', 'Empresas', 'Cargos', 'Aprobación Prueba', 'NOTA', 'Aprobación Terreno', 'Nota Terreno', 'Nota Ponderada', 'Habilitado', 'Fecha de Evaluación', 'Fecha Prueba', 'Contratista', 'Mandante', 'SAGE', 'Electricidad', 'Instalación de Cables Subterráneos', 'Medición y Análisis de Parámetros Subterráneos', 'Montaje e Intervención De Uniones De Cables', 'Revisión de Equipos', 'Seguridad', 'Conocimientos  Redes Subterráneas', 'Ingreso A Espacios Confinados', 'Instalación De Cables Subterráneos Bt', 'Instalación De Cables Subterráneos Mt', 'Montaje E Intervención De Uniones De Cables Bt', 'Montaje E Intervención De Uniones De Cables Mt', 'Operación De Equipos Mt', 'Preparación Del Entorno De Trabajo', 'Revisión De Equipos Mt'],
            ],
            [
                'key' => 'oocc',
                'title' => 'OOCC',
                'mode' => 'standard',
                'aliases' => ['OOCC', 'OBRAS CIVILES'],
                'columns' => ['RUT', 'Nombres', 'Apellidos', 'Empresas', 'Cargos', 'Aprobación Prueba', 'NOTA', 'Aprobación Terreno', 'Nota Terreno', 'Nota Ponderada', 'Habilitado', 'Fecha de Evaluación', 'Fecha Prueba', 'Contratista', 'Mandante', 'SAGE', 'Estado', 'Costrucción de oocc', 'Excavaciones y Calicata', 'Instalación de ductos', 'Material Residual', 'Reposición de Pavimento', 'Seguridad', 'Señalización vial', 'Construcción De Oocc', 'Entibaciones', 'Excavaciones Y Calicata.1', 'Ingreso A Espacios Confinados', 'Inicio De Faena', 'Instalación De Ductos.1', 'Interpretación De Planos', 'Mezclas', 'Preparación Del Entorno De Trabajo', 'Reposición De Pavimento.1', 'Revisión Final Y Termino De Faena'],
            ],
            [
                'key' => 'llee',
                'title' => 'LLEE',
                'mode' => 'standard',
                'aliases' => ['LLEE', 'LINEAS VIVAS'],
                'columns' => ['RUT', 'Nombres', 'Apellidos', 'Empresas', 'Cargos', 'Aprobación Prueba', 'NOTA', 'Aprobación Terreno', 'Aprobación Hot Line', 'Habilitado', 'Fecha de Evaluación', 'Fecha Prueba', 'Fecha Prueba Hot Line', 'Contratista', 'Mandante', 'SAGE', 'Estado', 'Infraestructura Aérea', 'Operación de equipo', 'Seguridad', 'Trabajo con LLEE', 'Habilitación', 'APROBADO TERRENO'],
            ],
            [
                'key' => 'empalme_mt',
                'title' => 'Empalme MT',
                'mode' => 'standard',
                'aliases' => ['EMPALME MT'],
                'columns' => ['RUT', 'Nombres', 'Apellidos', 'Empresas', 'Cargos', 'Aprobación Prueba', 'NOTA', 'Aprobación Terreno', 'NOTA TERRENO', 'Nota Ponderada', 'Habilitado', 'Fecha de Evaluación', 'Fecha Prueba', 'Contratista', 'Mandante', 'SAGE', 'Estado', 'Block de prueba', 'Conocimiento de equipos y Herramientas', 'Equipo de Medida de MT', 'Mantenimiento', 'Procerdimiento de Trabajo', 'Seguridad', 'Finalización de trabajos', 'Inspección medidor', 'INSTALACIÓN DE FUSIBLES MT AÉREO', 'INSTALACION Y/O RETIRO DE EQUIPO', 'Preparación del entorno de trabajo', 'Revisión de la Caja de Empalme', 'Revisión de Tranformadores de Corriente'],
            ],
            [
                'key' => 'inf_aerea',
                'title' => 'Inf  Aérea',
                'mode' => 'standard',
                'aliases' => ['INF AEREA', 'INFRAESTRUCTURA AEREA'],
                'cuadrilla_label' => 'Aérea MT',
                'columns' => ['RUT', 'Nombres', 'Apellidos', 'Empresas', 'Cargos', 'Cuadrilla', 'Aprobación Prueba', 'NOTA', 'Aprobación Terreno', 'NOTA TERRENO', 'Nota Ponderada', 'Habilitado', 'Fecha de Evaluación', 'Fecha Prueba', 'Contratista', 'Mandante', 'SAGE', 'Estado', 'Clientes', 'Electricidad', 'Operación de Equipos', 'Postes, Tirante y Tierra', 'Seguridad', 'Trabajos Aereos', 'Preparación del entorno de trabajo MT', 'USO DE EPP MT', 'INICIO DE FAENA MT', 'OPERACIÓN EQUIPOS  MT', 'CAMBIO TRANSFORMADOR AÉREO MT', 'CAMBIO DE AISLADORES: ESPIGO/DISCO MT', 'CAMBIO DE CRUCETA MT', 'INSTALACIÓN DE FUSIBLES  AÉREO MT', 'INSTALACIÓN / CAMBIO DE RED  AÉREA MT', 'REVISIÓN FINAL Y TERMINO DE FAENA MT', 'Preparación del entorno de trabajo BT', 'USO DE EPP BT', 'INICIO DE FAENA BT', 'INSTALACIÓN TIERRAS DE PROTECCIÓN Y SERVICIO BT', 'INSTALACIÓN/ CAMBIO DE RED AÉREA BT A CALPE BT', 'INSTALACIÓN CAJAS DAE BT', 'INSTALACIÓN DE FUSIBLES BT AÉREO BT', 'DESENERGIZADO DE RED BT PARA TRABAJOS DE MANTENCIÓN BT', 'REVISIÓN FINAL Y TÉRMINO DE FAENA BT'],
            ],
            [
                'key' => 'llee_podas',
                'title' => 'LLEE Podas',
                'mode' => 'standard',
                'aliases' => ['LLEE PODAS', 'PODAS'],
                'columns' => ['RUT', 'Nombres', 'Apellidos', 'Empresas', 'Cargos', 'Aprobación Prueba', 'NOTA', 'Aprobación Terreno', 'Habilitado', 'Fecha de Evaluación', 'Fecha Prueba', 'Contratista', 'Mandante', 'SAGE', 'Estado', 'Infraestructura Aérea', 'Operación de equipo', 'Seguridad', 'Trabajo con LLEE', 'Manejo Ambiental', 'Trabajo de Podas', 'Habilitación', 'APROBADO TERRENO'],
            ],
            [
                'key' => 'empalme',
                'title' => 'Empalme',
                'mode' => 'standard',
                'aliases' => ['EMPALME'],
                'columns' => ['RUT', 'Nombres', 'Apellidos', 'Empresas', 'Cargos', 'Aprobación Prueba', 'NOTA', 'Aprobación Terreno', 'NOTA TERRENO', 'Nota Ponderada', 'Habilitado', 'Fecha de Evaluación', 'Fecha Prueba', 'Contratista', 'Mandante', 'SAGE', 'Estado', 'Procerdimiento de Trabajo', 'Seguridad', 'Mantenimiento', 'Block de prueba', 'Conocimiento de equipos y Herramientas', 'Descripción secuencia operativa', 'Finalización de trabajos', 'Identificar la siguiente secuencia en interruptor termomagnético', 'Identificar la siguiente secuencia en TC lado primario / TC lado secundario / block de pruebas tipo piano', 'Inspección block de pruebas', 'Inspección medidor', 'Preparación del entorno de trabajo', 'Revisión acometida, bajada y unión a tablero', 'Revisión de caja de empalme con TC', 'Revisión de TC en caja de empalme', 'Revisión interior de caja de empalme, medir tensión en block de pruebas', 'Secuencia Operativa', 'Verificar estado de poste a intervenir'],
            ],
            [
                'key' => 'cyr_mt',
                'title' => 'CyR MT',
                'mode' => 'standard',
                'aliases' => ['CYR MT'],
                'columns' => ['RUT', 'Nombres', 'Apellidos', 'Empresas', 'Cargos', 'Aprobación Prueba', 'NOTA', 'Aprobación Terreno', 'Nota Terreno', 'Nota Ponderada', 'Habilitado', 'Fecha de Evaluación', 'Fecha Prueba', 'Contratista', 'Mandante', 'SAGE', 'Operación de Equipos', 'Seguridad', 'Trabajos CyR', 'Finalización de trabajos', 'Preparación del entorno de trabajo', 'Secuencia Operativa'],
            ],
            [
                'key' => 'cyr',
                'title' => 'CyR',
                'mode' => 'standard',
                'aliases' => ['CYR', 'CORTE Y REPOSICION'],
                'columns' => ['RUT', 'Nombres', 'Apellidos', 'Empresas', 'Cargos', 'Aprobación Prueba', 'NOTA', 'Aprobación Terreno', 'Nota Terreno', 'Nota Ponderada', 'Habilitado', 'Fecha de Evaluación', 'Fecha Prueba', 'Contratista', 'Mandante', 'SAGE', 'Estado', 'Conocimiento de equipos y Herramientas', 'Procerdimiento de Trabajo', 'Seguridad', 'Finalización de trabajos', 'Preparación del entorno de trabajo', 'Secuencia Operativa'],
            ],
            [
                'key' => 'caducada_vigencia',
                'title' => 'Caducada la Vigencia',
                'mode' => 'caducada',
                'columns' => ['RUT', 'Nombres', 'Apellidos', 'Cargo del Contrato', 'Contratista General', 'Servicio del Contrato', 'Contratista', 'Servicio Evaluado', 'Cargo Evaluado', 'Estado Habilitacion', 'Pendiente', 'UO', 'Sin Vigencia', 'SAGE'],
            ],
            [
                'key' => 'personas_faltan',
                'title' => 'Personas Que Faltan',
                'mode' => 'faltantes',
                'columns' => ['RUT', 'Nombres', 'Apellidos', 'Cargo del Contrato', 'Contratista General', 'Servicio del Contrato', 'Contratista', 'Servicio Evaluado', 'Cargo Evaluado', 'Estado Habilitacion', 'Pendiente', 'UO', 'Sin Vigencia', 'SAGE'],
            ],
        ];
    }
}

if (!function_exists('iheGetSelectedDefinition')) {
    function iheGetSelectedDefinition(?string $key): array
    {
        $definitions = iheGetSheetDefinitions();
        if ($key === null || $key === '') {
            return $definitions[1];
        }

        foreach ($definitions as $definition) {
            if ($definition['key'] === $key) {
                return $definition;
            }
        }

        return $definitions[1];
    }
}

if (!function_exists('iheLoadLatestTheoryRows')) {
    function iheLoadLatestTheoryRows(PDO $pdo, int $idServicio): array
    {
        $stmt = $pdo->prepare("
            SELECT
                rpi.rut,
                rpi.fecha_rendicion,
                rpi.hora_rendicion,
                rpi.puntaje_total,
                rpi.notafinal,
                rpi.id_proceso_habilitacion,
                ph.numero_proceso,
                (
                    SELECT emp_h.nombre
                    FROM ceo_evaluaciones_programadas ep_h
                    INNER JOIN ceo_habilitacion h ON h.cuadrilla = ep_h.cuadrilla AND h.id_servicio = ep_h.id_servicio
                    LEFT JOIN ceo_empresas emp_h ON emp_h.id = h.empresa
                    WHERE ep_h.id_proceso_habilitacion = rpi.id_proceso_habilitacion
                      AND ep_h.id_servicio = rpi.id_servicio
                      AND ep_h.tipo IN ('PRUEBA', 'TEORICA')
                      AND REPLACE(REPLACE(REPLACE(UPPER(ep_h.rut), '.', ''), '-', ''), ' ', '') = REPLACE(REPLACE(REPLACE(UPPER(rpi.rut), '.', ''), '-', ''), ' ', '')
                    ORDER BY ep_h.id DESC
                    LIMIT 1
                ) AS empresa_historica
            FROM ceo_resultado_prueba_intento rpi
            LEFT JOIN ceo_proceso_habilitacion ph ON ph.id = rpi.id_proceso_habilitacion
            WHERE rpi.id_servicio = :id_servicio
            ORDER BY rpi.fecha_rendicion DESC, rpi.hora_rendicion DESC, rpi.id DESC
        ");
        $stmt->execute([':id_servicio' => $idServicio]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $grouped = [];
        foreach ($rows as $row) {
            $rutKey = iheNormalizeRut((string)$row['rut']);
            if ($rutKey === '' || isset($grouped[$rutKey])) {
                continue;
            }
            $fecha = iheParseDate((string)$row['fecha_rendicion'] . ' ' . (string)$row['hora_rendicion']);
            $puntaje = isset($row['puntaje_total']) ? (float)$row['puntaje_total'] : null;
            $grouped[$rutKey] = [
                'rut' => (string)$row['rut'],
                'fecha' => $fecha,
                'puntaje' => $puntaje,
                'nota' => isset($row['notafinal']) ? (float)$row['notafinal'] : null,
                'aprobacion' => $puntaje !== null ? ($puntaje >= 80.0 ? 'SI' : 'NO') : 'Pendiente',
                'id_proceso_habilitacion' => (int)($row['id_proceso_habilitacion'] ?? 0),
                'numero_proceso' => isset($row['numero_proceso']) ? (int)$row['numero_proceso'] : null,
                'empresa_historica' => trim((string)($row['empresa_historica'] ?? '')),
            ];
        }

        return $grouped;
    }
}

if (!function_exists('iheLoadLatestTheoryAreasByRut')) {
    function iheLoadLatestTheoryAreasByRut(PDO $pdo, int $idServicio): array
    {
        $stmtIntentos = $pdo->prepare("
            SELECT
                rpt.rut,
                rpt.proceso,
                rpt.intento,
                MAX(CONCAT(COALESCE(rpt.fecha_rendicion, '0000-00-00'), ' ', COALESCE(rpt.hora_rendicion, '00:00:00'))) AS fecha_hora
            FROM ceo_resultado_pruebat rpt
            INNER JOIN ceo_preguntas_servicios ps
                ON ps.id = rpt.id_pregunta
               AND ps.id_servicio = :id_servicio
            GROUP BY rpt.rut, rpt.proceso, rpt.intento
            ORDER BY fecha_hora DESC, rpt.intento DESC, rpt.proceso DESC
        ");
        $stmtIntentos->execute([':id_servicio' => $idServicio]);

        $latest = [];
        foreach ($stmtIntentos->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rutKey = iheNormalizeRut((string)$row['rut']);
            if ($rutKey === '' || isset($latest[$rutKey])) {
                continue;
            }
            $latest[$rutKey] = [
                'proceso' => isset($row['proceso']) ? (int)$row['proceso'] : null,
                'intento' => isset($row['intento']) ? (int)$row['intento'] : null,
            ];
        }

        if (empty($latest)) {
            return [];
        }

        $stmtAreas = $pdo->prepare("
            SELECT
                rpt.rut,
                rpt.proceso,
                rpt.intento,
                COALESCE(ac.descripcion, 'Sin area de competencia') AS area,
                cfg.porcentaje AS objetivo,
                SUM(CASE WHEN rpt.validacion = 1 THEN 1 ELSE 0 END) AS correctas,
                COUNT(*) AS total
            FROM ceo_resultado_pruebat rpt
            INNER JOIN ceo_preguntas_servicios ps
                ON ps.id = rpt.id_pregunta
               AND ps.id_servicio = :id_servicio
            LEFT JOIN ceo_areacompetencias ac
                ON ac.id = ps.areacomp
               AND ac.id_servicio = ps.id_servicio
            LEFT JOIN ceo_habilitacion_areascompetencias_pct cfg
                ON cfg.id_servicio = ps.id_servicio
               AND cfg.id_area = ps.areacomp
            GROUP BY rpt.rut, rpt.proceso, rpt.intento, COALESCE(ac.descripcion, 'Sin area de competencia'), cfg.porcentaje
            ORDER BY area ASC
        ");
        $stmtAreas->execute([':id_servicio' => $idServicio]);

        $result = [];
        foreach ($stmtAreas->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rutKey = iheNormalizeRut((string)$row['rut']);
            if (!isset($latest[$rutKey])) {
                continue;
            }
            $meta = $latest[$rutKey];
            if ((int)$row['proceso'] !== (int)$meta['proceso'] || (int)$row['intento'] !== (int)$meta['intento']) {
                continue;
            }

            $total = (int)($row['total'] ?? 0);
            if ($total <= 0) {
                continue;
            }

            $correctas = (int)($row['correctas'] ?? 0);
            $porcentaje = round(($correctas / $total) * 100, 2);
            $threshold = $row['objetivo'] !== null ? (float)$row['objetivo'] : 80.0;
            $note = calcularNotaFinalDesdePorcentaje($porcentaje, $threshold);
            $result[$rutKey][iheNormalizeText((string)$row['area'])] = round($note, 2);
        }

        return $result;
    }
}

if (!function_exists('iheLoadLatestTerrainRows')) {
    function iheLoadLatestTerrainRows(PDO $pdo, int $idServicio): array
    {
        $stmtTerreno = $pdo->prepare("
            SELECT
                et.id,
                et.rut,
                et.fecha_evaluacion,
                et.resultado,
                et.cargo,
                et.id_proceso_habilitacion,
                ph.numero_proceso,
                (
                    SELECT emp_h.nombre
                    FROM ceo_evaluaciones_programadas ep_h
                    INNER JOIN ceo_habilitacion h ON h.cuadrilla = ep_h.cuadrilla AND h.id_servicio = ep_h.id_servicio
                    LEFT JOIN ceo_empresas emp_h ON emp_h.id = h.empresa
                    WHERE ep_h.id_proceso_habilitacion = et.id_proceso_habilitacion
                      AND ep_h.id_servicio = et.id_servicio
                      AND ep_h.tipo = 'TERRENO'
                      AND REPLACE(REPLACE(REPLACE(UPPER(ep_h.rut), '.', ''), '-', ''), ' ', '') = REPLACE(REPLACE(REPLACE(UPPER(et.rut), '.', ''), '-', ''), ' ', '')
                    ORDER BY ep_h.id DESC
                    LIMIT 1
                ) AS empresa_historica
            FROM ceo_evaluacion_terreno et
            LEFT JOIN ceo_proceso_habilitacion ph ON ph.id = et.id_proceso_habilitacion
            WHERE et.id_servicio = :id_servicio
            ORDER BY et.fecha_evaluacion DESC, et.id DESC
        ");
        $stmtTerreno->execute([':id_servicio' => $idServicio]);
        $rows = $stmtTerreno->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stmtIntentos = $pdo->prepare("
            SELECT rut, fecha_rendicion, hora_rendicion, notafinal
            FROM ceo_resultado_terreno_intento
            WHERE id_servicio = :id_servicio
            ORDER BY fecha_rendicion DESC, hora_rendicion DESC, id DESC
        ");
        $stmtIntentos->execute([':id_servicio' => $idServicio]);
        $intentos = [];
        foreach ($stmtIntentos->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rutKey = iheNormalizeRut((string)$row['rut']);
            if ($rutKey === '' || isset($intentos[$rutKey])) {
                continue;
            }
            $intentos[$rutKey] = isset($row['notafinal']) ? (float)$row['notafinal'] : null;
        }

        $grouped = [];
        foreach ($rows as $row) {
            $rutKey = iheNormalizeRut((string)$row['rut']);
            if ($rutKey === '' || isset($grouped[$rutKey])) {
                continue;
            }

            $puntaje = iheParseNullableScore($row['resultado'] ?? null);
            $grouped[$rutKey] = [
                'id' => (int)$row['id'],
                'rut' => (string)$row['rut'],
                'fecha' => iheParseDate((string)$row['fecha_evaluacion']),
                'puntaje' => $puntaje,
                'nota' => $intentos[$rutKey] ?? null,
                'aprobacion' => $puntaje !== null ? ($puntaje >= 80.0 ? 'SI' : 'NO') : 'Pendiente',
                'cargo' => trim((string)($row['cargo'] ?? '')),
                'id_proceso_habilitacion' => (int)($row['id_proceso_habilitacion'] ?? 0),
                'numero_proceso' => isset($row['numero_proceso']) ? (int)$row['numero_proceso'] : null,
                'empresa_historica' => trim((string)($row['empresa_historica'] ?? '')),
            ];
        }

        return $grouped;
    }
}

if (!function_exists('iheLoadTerrainThreshold')) {
    function iheLoadTerrainThreshold(PDO $pdo, int $idServicio): float
    {
        $stmt = $pdo->prepare("
            SELECT p.porcentaje
            FROM ceo_agrupacion_terreno a
            INNER JOIN ceo_porcentaje_agrup_terreno p ON p.id_agrupacion = a.id
            WHERE a.id_servicio = :id_servicio
              AND p.activo = 'S'
            ORDER BY p.fechadesde DESC
            LIMIT 1
        ");
        $stmt->execute([':id_servicio' => $idServicio]);
        return (float)($stmt->fetchColumn() ?: 80.0);
    }
}

if (!function_exists('iheLoadLatestTerrainBreakdownByRut')) {
    function iheLoadLatestTerrainBreakdownByRut(PDO $pdo, int $idServicio, float $threshold): array
    {
        $stmtEval = $pdo->prepare("
            SELECT id, rut
            FROM ceo_evaluacion_terreno
            WHERE id_servicio = :id_servicio
            ORDER BY fecha_evaluacion DESC, id DESC
        ");
        $stmtEval->execute([':id_servicio' => $idServicio]);

        $latestEval = [];
        foreach ($stmtEval->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rutKey = iheNormalizeRut((string)$row['rut']);
            if ($rutKey === '' || isset($latestEval[$rutKey])) {
                continue;
            }
            $latestEval[$rutKey] = (int)$row['id'];
        }

        if (empty($latestEval)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($latestEval), '?'));
        $stmtDetalle = $pdo->prepare("
            SELECT
                etd.id_evaluacion_terreno,
                et.rut,
                etd.area,
                etd.item,
                etd.respuesta,
                etd.resultado_item
            FROM ceo_evaluacion_terreno_detalle etd
            INNER JOIN ceo_evaluacion_terreno et ON et.id = etd.id_evaluacion_terreno
            WHERE etd.id_evaluacion_terreno IN ($placeholders)
            ORDER BY etd.id_evaluacion_terreno ASC, etd.id ASC
        ");
        $stmtDetalle->execute(array_values($latestEval));
        $details = $stmtDetalle->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $areas = [];
        $items = [];
        foreach ($details as $row) {
            $rutKey = iheNormalizeRut((string)$row['rut']);
            if ($rutKey === '') {
                continue;
            }

            [$cumple, $noCumple, $noAplica] = iheMapTerrenoRespuestaFlags((string)($row['respuesta'] ?? $row['resultado_item'] ?? ''));

            $areaKey = iheNormalizeText((string)$row['area']);
            if ($areaKey !== '') {
                if (!isset($areas[$rutKey][$areaKey])) {
                    $areas[$rutKey][$areaKey] = ['ok' => 0, 'no' => 0, 'na' => 0, 'blank' => 0];
                }
                if ($cumple) {
                    $areas[$rutKey][$areaKey]['ok']++;
                } elseif ($noCumple) {
                    $areas[$rutKey][$areaKey]['no']++;
                } elseif ($noAplica) {
                    $areas[$rutKey][$areaKey]['na']++;
                } else {
                    $areas[$rutKey][$areaKey]['blank']++;
                }
            }

            $itemKey = iheNormalizeText((string)$row['item']);
            if ($itemKey !== '') {
                if ($cumple) {
                    $items[$rutKey][$itemKey] = 7.0;
                } elseif ($noCumple) {
                    $items[$rutKey][$itemKey] = 1.0;
                } elseif ($noAplica) {
                    $items[$rutKey][$itemKey] = 0.0;
                } elseif (!isset($items[$rutKey][$itemKey])) {
                    $items[$rutKey][$itemKey] = 0.0;
                }
            }
        }

        $result = [];
        foreach ($latestEval as $rutKey => $_evalId) {
            $result[$rutKey] = [
                'areas' => [],
                'items' => $items[$rutKey] ?? [],
            ];

            foreach (($areas[$rutKey] ?? []) as $areaKey => $stats) {
                $totalEvaluable = (int)$stats['ok'] + (int)$stats['no'] + (int)$stats['blank'];
                if ($totalEvaluable <= 0) {
                    $result[$rutKey]['areas'][$areaKey] = 0.0;
                    continue;
                }

                $porcentaje = round(((int)$stats['ok'] / $totalEvaluable) * 100, 2);
                $result[$rutKey]['areas'][$areaKey] = round(calcularNotaFinalDesdePorcentaje($porcentaje, $threshold), 2);
            }
        }

        return $result;
    }
}

if (!function_exists('iheBuildBaseServiceDataset')) {
    function iheBuildBaseServiceDataset(PDO $pdo, array $serviceRow, array $contractorsByRut): array
    {
        $idServicio = (int)$serviceRow['id'];
        $serviceName = (string)$serviceRow['servicio'];
        $threshold = iheLoadTerrainThreshold($pdo, $idServicio);
        $theory = iheLoadLatestTheoryRows($pdo, $idServicio);
        $theoryAreas = iheLoadLatestTheoryAreasByRut($pdo, $idServicio);
        $terrain = iheLoadLatestTerrainRows($pdo, $idServicio);
        $terrainBreakdown = iheLoadLatestTerrainBreakdownByRut($pdo, $idServicio, $threshold);

        $rowsByRut = [];
        $allRuts = array_unique(array_merge(array_keys($theory), array_keys($terrain)));
        $today = new DateTimeImmutable('today');

        foreach ($allRuts as $rutKey) {
            if (!isset($contractorsByRut[$rutKey])) {
                continue;
            }

            $contractor = $contractorsByRut[$rutKey];
            $teo = $theory[$rutKey] ?? null;
            $terr = $terrain[$rutKey] ?? null;
            if ($teo === null && $terr === null) {
                continue;
            }

            $cargo = trim((string)($contractor['cargo'] ?? ''));
            if ($cargo === '' && $terr !== null) {
                $cargo = trim((string)($terr['cargo'] ?? ''));
            }

            $pesos = iheResolvePesosPorCargoServicio($cargo, isset($contractor['id_cargo']) ? (int)$contractor['id_cargo'] : null);
            $notaFinal = null;
            if ($pesos !== null && isset($teo['nota'], $terr['nota']) && $teo['nota'] !== null && $terr['nota'] !== null) {
                $notaFinal = round((((float)$teo['nota']) * $pesos['teorica']) + (((float)$terr['nota']) * $pesos['terreno']), 2);
            }

            $ultimaEvaluacion = null;
            if (($teo['fecha'] ?? null) instanceof DateTimeImmutable && ($terr['fecha'] ?? null) instanceof DateTimeImmutable) {
                $ultimaEvaluacion = $teo['fecha'] > $terr['fecha'] ? $teo['fecha'] : $terr['fecha'];
            } elseif (($teo['fecha'] ?? null) instanceof DateTimeImmutable) {
                $ultimaEvaluacion = $teo['fecha'];
            } elseif (($terr['fecha'] ?? null) instanceof DateTimeImmutable) {
                $ultimaEvaluacion = $terr['fecha'];
            }

            $habilitado = 'Pendiente';
            if ($notaFinal !== null) {
                $habilitado = $notaFinal >= 4.0 ? 'SI' : 'NO';
            } elseif ($teo !== null || $terr !== null) {
                $habilitado = 'Pendiente';
            }

            $vigenciaHasta = null;
            if (($terr['fecha'] ?? null) instanceof DateTimeImmutable && $notaFinal !== null && $notaFinal >= 4.0) {
                $vigenciaHasta = $terr['fecha']->modify('+3 years');
            }

            $estado = '';
            if ($habilitado === 'SI') {
                $estado = ($vigenciaHasta instanceof DateTimeImmutable && $vigenciaHasta < $today) ? 'NO Vigente' : 'Habilitado';
            } elseif ($habilitado === 'NO') {
                $estado = 'No Habilitado';
            } elseif ($teo !== null || $terr !== null) {
                $estado = 'Pendiente';
            }

            $empresaHistorica = trim((string)($terr['empresa_historica'] ?? ''));
            if ($empresaHistorica === '' && $teo !== null) {
                $empresaHistorica = trim((string)($teo['empresa_historica'] ?? ''));
            }

            $rowsByRut[$rutKey] = [
                'rut' => (string)$contractor['rut'],
                'nombre' => trim((string)($contractor['nombre'] ?? '')),
                'apellidos' => trim((string)($contractor['apellidos'] ?? '')),
                'cargo' => $cargo,
                'empresa' => $empresaHistorica !== '' ? $empresaHistorica : trim((string)($contractor['empresa'] ?? '')),
                'uo' => trim((string)($contractor['uo'] ?? '')),
                'teorica' => $teo,
                'terreno' => $terr,
                'nota_final' => $notaFinal,
                'habilitado' => $habilitado,
                'estado' => $estado,
                'vigencia_hasta' => $vigenciaHasta,
                'ultima_evaluacion' => $ultimaEvaluacion,
                'numero_proceso' => $terr['numero_proceso'] ?? ($teo['numero_proceso'] ?? null),
                'theory_areas' => $theoryAreas[$rutKey] ?? [],
                'terrain_areas' => $terrainBreakdown[$rutKey]['areas'] ?? [],
                'terrain_items' => $terrainBreakdown[$rutKey]['items'] ?? [],
                'service_name' => $serviceName,
                'service_id' => $idServicio,
            ];
        }

        return [
            'id' => $idServicio,
            'servicio' => $serviceName,
            'rows_by_rut' => $rowsByRut,
        ];
    }
}

if (!function_exists('iheResolveDetailColumnValue')) {
    function iheResolveDetailColumnValue(string $columnLabel, array $baseRow): string
    {
        $terrainItem = iheFindBestMatchValue($columnLabel, $baseRow['terrain_items'] ?? []);
        if ($terrainItem !== null) {
            return iheTrimmedNumber($terrainItem, 1);
        }

        $terrainArea = iheFindBestMatchValue($columnLabel, $baseRow['terrain_areas'] ?? []);
        if ($terrainArea !== null) {
            return iheTrimmedNumber($terrainArea, 1);
        }

        $theoryArea = iheFindBestMatchValue($columnLabel, $baseRow['theory_areas'] ?? []);
        if ($theoryArea !== null) {
            return iheTrimmedNumber($theoryArea, 1);
        }

        return '';
    }
}

if (!function_exists('iheBuildStandardSheetRow')) {
    function iheBuildStandardSheetRow(array $definition, array $baseRow, string $empresaNombre): array
    {
        $row = array_fill_keys($definition['columns'], '');
        $teo = $baseRow['teorica'] ?? null;
        $terr = $baseRow['terreno'] ?? null;

        foreach (['RUT', 'Nombres', 'Apellidos', 'Empresas', 'Cargos', 'Cuadrilla', 'Aprobación Prueba', 'NOTA', 'Aprobación Terreno', 'NOTA TERRENO', 'Nota Terreno', 'Nota Ponderada', 'Habilitado', 'Fecha de Evaluación', 'Fecha Prueba', 'Fecha Prueba BT', 'Fecha Prueba MT', 'Fecha Prueba Hot Line', 'Contratista', 'Mandante', 'SAGE', 'Estado', 'Aprobación Hot Line', 'APROBADO TERRENO', 'Habilitación'] as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }

            switch ($key) {
                case 'RUT':
                    $row[$key] = $baseRow['rut'];
                    break;
                case 'Nombres':
                    $row[$key] = $baseRow['nombre'];
                    break;
                case 'Apellidos':
                    $row[$key] = $baseRow['apellidos'];
                    break;
                case 'Empresas':
                    $row[$key] = $baseRow['empresa'];
                    break;
                case 'Cargos':
                    $row[$key] = $baseRow['cargo'];
                    break;
                case 'Cuadrilla':
                    $row[$key] = (string)($definition['cuadrilla_label'] ?? '');
                    break;
                case 'Aprobación Prueba':
                    $row[$key] = $teo['aprobacion'] ?? 'Pendiente';
                    break;
                case 'NOTA':
                    $row[$key] = iheTrimmedNumber(isset($teo['nota']) ? (float)$teo['nota'] : null, 1);
                    break;
                case 'Aprobación Terreno':
                    $row[$key] = $terr['aprobacion'] ?? 'Pendiente';
                    break;
                case 'NOTA TERRENO':
                case 'Nota Terreno':
                    $row[$key] = iheTrimmedNumber(isset($terr['nota']) ? (float)$terr['nota'] : null, 1);
                    break;
                case 'Nota Ponderada':
                    $row[$key] = iheTrimmedNumber(isset($baseRow['nota_final']) ? (float)$baseRow['nota_final'] : null, 1);
                    break;
                case 'Habilitado':
                    $row[$key] = $baseRow['habilitado'];
                    break;
                case 'Fecha de Evaluación':
                    $row[$key] = iheFmtDate($baseRow['ultima_evaluacion']);
                    break;
                case 'Fecha Prueba':
                case 'Fecha Prueba BT':
                case 'Fecha Prueba MT':
                case 'Fecha Prueba Hot Line':
                    $row[$key] = iheFmtDate($teo['fecha'] ?? null);
                    break;
                case 'Contratista':
                case 'Mandante':
                    $row[$key] = $empresaNombre;
                    break;
                case 'SAGE':
                    $row[$key] = 'SI';
                    break;
                case 'Estado':
                    $row[$key] = $baseRow['estado'];
                    break;
                case 'Aprobación Hot Line':
                case 'APROBADO TERRENO':
                    $row[$key] = $terr['aprobacion'] ?? 'Pendiente';
                    break;
                case 'Habilitación':
                    $row[$key] = $baseRow['habilitado'];
                    break;
            }
        }

        foreach ($definition['columns'] as $column) {
            if ($row[$column] !== '') {
                continue;
            }
            $row[$column] = iheResolveDetailColumnValue($column, $baseRow);
        }

        $row['__meta'] = [
            'rut_key' => iheNormalizeRut($baseRow['rut']),
            'rut' => $baseRow['rut'],
            'nombre' => $baseRow['nombre'],
            'apellidos' => $baseRow['apellidos'],
            'cargo' => $baseRow['cargo'],
            'empresa' => $empresaNombre,
            'uo' => $baseRow['uo'],
            'servicio' => $definition['title'],
            'estado_habilitacion' => $baseRow['habilitado'],
            'vigencia_hasta' => $baseRow['vigencia_hasta'],
            'estado' => $baseRow['estado'],
        ];

        return $row;
    }
}

if (!function_exists('iheBuildDualSheetRows')) {
    function iheBuildDualSheetRows(array $definition, array $datasetsBySlot, string $empresaNombre): array
    {
        $allRuts = [];
        foreach ($datasetsBySlot as $slot => $dataset) {
            foreach (array_keys($dataset['rows_by_rut']) as $rutKey) {
                $allRuts[$rutKey] = true;
            }
        }
        $allRutKeys = array_keys($allRuts);
        sort($allRutKeys);

        $rows = [];
        foreach ($allRutKeys as $rutKey) {
            $row = array_fill_keys($definition['columns'], '');
            $mt = $datasetsBySlot['MT']['rows_by_rut'][$rutKey] ?? null;
            $bt = $datasetsBySlot['BT']['rows_by_rut'][$rutKey] ?? null;
            $base = $mt ?? $bt;
            if ($base === null) {
                continue;
            }

            $row['RUT'] = $base['rut'];
            $row['Nombres'] = $base['nombre'];
            $row['Apellidos'] = $base['apellidos'];
            $row['Cargos'] = $base['cargo'];
            $row['Empresas'] = $base['empresa'];
            $row['Prueba MT'] = $mt !== null ? (($mt['teorica']['aprobacion'] ?? 'Pendiente')) : '';
            $row['NOTA MT'] = $mt !== null ? iheTrimmedNumber($mt['teorica']['nota'] ?? null, 1) : '';
            $row['Terreno MT'] = $mt !== null ? (($mt['terreno']['aprobacion'] ?? 'Pendiente')) : '';
            $row['Nota Terreno MT'] = $mt !== null ? iheTrimmedNumber($mt['terreno']['nota'] ?? null, 1) : '';
            $row['Prueba BT'] = $bt !== null ? (($bt['teorica']['aprobacion'] ?? 'Pendiente')) : '';
            $row['NOTA BT'] = $bt !== null ? iheTrimmedNumber($bt['teorica']['nota'] ?? null, 1) : '';
            $row['Terreno BT'] = $bt !== null ? (($bt['terreno']['aprobacion'] ?? 'Pendiente')) : '';
            $row['Nota Terreno BT'] = $bt !== null ? iheTrimmedNumber($bt['terreno']['nota'] ?? null, 1) : '';
            $row['Habilitado MT'] = $mt !== null ? $mt['habilitado'] : '';
            $row['Habilitado BT'] = $bt !== null ? $bt['habilitado'] : '';
            $row['Habilitado'] = ($mt !== null && $mt['habilitado'] === 'SI') || ($bt !== null && $bt['habilitado'] === 'SI') ? 'SI' : (($mt !== null || $bt !== null) ? 'Pendiente' : '');
            $fechaEvaluacion = $base['ultima_evaluacion'];
            if (($bt['ultima_evaluacion'] ?? null) instanceof DateTimeImmutable && (!($fechaEvaluacion instanceof DateTimeImmutable) || $bt['ultima_evaluacion'] > $fechaEvaluacion)) {
                $fechaEvaluacion = $bt['ultima_evaluacion'];
            }
            $row['Fecha de Evaluación'] = iheFmtDate($fechaEvaluacion);
            $row['Fecha Prueba BT'] = iheFmtDate($bt['teorica']['fecha'] ?? null);
            $row['Fecha Prueba MT'] = iheFmtDate($mt['teorica']['fecha'] ?? null);
            $row['Contratista'] = $empresaNombre;
            $row['Mandante'] = $empresaNombre;
            $row['SAGE'] = 'SI';
            $row['Estado'] = $base['estado'];

            foreach ($definition['columns'] as $column) {
                if ($row[$column] !== '') {
                    continue;
                }

                $source = str_contains(iheNormalizeText($column), 'BT') ? ($bt ?? $base) : ($mt ?? $bt ?? $base);
                $row[$column] = $source !== null ? iheResolveDetailColumnValue($column, $source) : '';
            }

            $row['__meta'] = [
                'rut_key' => $rutKey,
                'rut' => $base['rut'],
                'nombre' => $base['nombre'],
                'apellidos' => $base['apellidos'],
                'cargo' => $base['cargo'],
                'empresa' => $empresaNombre,
                'uo' => $base['uo'],
                'servicio' => $definition['title'],
                'estado_habilitacion' => $row['Habilitado'],
                'vigencia_hasta' => $base['vigencia_hasta'],
                'estado' => $row['Estado'],
            ];

            $rows[] = $row;
        }

        return $rows;
    }
}

if (!function_exists('iheBuildCuadrillasRows')) {
    function iheBuildCuadrillasRows(array $definition, array $datasets, array $contractorsByRut): array
    {
        $dataset = $datasets[0] ?? null;
        $rowsByRut = $dataset['rows_by_rut'] ?? [];
        $rows = [];

        foreach ($contractorsByRut as $rutKey => $contractor) {
            $base = $rowsByRut[$rutKey] ?? null;
            $row = array_fill_keys($definition['columns'], '');
            $row['RUT'] = (string)$contractor['rut'];
            $row['Nombre'] = trim((string)($contractor['nombre'] ?? ''));
            $row['Apellidos'] = trim((string)($contractor['apellidos'] ?? ''));
            $row['Cargo'] = trim((string)($contractor['cargo'] ?? ''));
            $row['Empresa'] = trim((string)($contractor['empresa'] ?? ''));

            if ($base !== null) {
                $row['Fecha Prueba'] = iheFmtDate($base['teorica']['fecha'] ?? null);
                $row['Nota Prueba'] = iheTrimmedNumber($base['teorica']['nota'] ?? null, 1);
                $row['Prueba'] = $base['teorica']['aprobacion'] ?? 'Pendiente';
                $row['Fecha Terreno'] = iheFmtDate($base['terreno']['fecha'] ?? null);
                $row['Nota Terreno'] = iheTrimmedNumber($base['terreno']['nota'] ?? null, 1);
                $row['Terreno'] = $base['terreno']['aprobacion'] ?? 'Pendiente';
                $row['Habilitado'] = $base['habilitado'];
                $row['Estado'] = $base['habilitado'] === 'SI' ? '🟩' : ($base['habilitado'] === 'NO' ? '🟥' : '🟨');
                $row['RDO'] = $row['Estado'];
            }

            $row['__meta'] = [
                'rut_key' => $rutKey,
                'rut' => (string)$contractor['rut'],
                'nombre' => trim((string)($contractor['nombre'] ?? '')),
                'apellidos' => trim((string)($contractor['apellidos'] ?? '')),
                'cargo' => trim((string)($contractor['cargo'] ?? '')),
                'empresa' => trim((string)($contractor['empresa'] ?? '')),
                'uo' => trim((string)($contractor['uo'] ?? '')),
                'servicio' => $definition['title'],
                'estado_habilitacion' => $base['habilitado'] ?? '',
                'vigencia_hasta' => $base['vigencia_hasta'] ?? null,
                'estado' => $base['estado'] ?? '',
            ];

            $rows[] = $row;
        }

        return $rows;
    }
}

if (!function_exists('iheResolveDatasetsForDefinition')) {
    function iheResolveDatasetsForDefinition(PDO $pdo, array $definition, array $services, array $contractorsByRut): array
    {
        $datasets = [];

        if (($definition['mode'] ?? '') === 'dual') {
            foreach ($services as $service) {
                $serviceName = (string)$service['servicio'];
                $serviceNorm = iheNormalizeText($serviceName);
                $slot = null;

                if (str_contains($serviceNorm, ' BT')) {
                    $slot = 'BT';
                } elseif (str_contains($serviceNorm, ' MT')) {
                    $slot = 'MT';
                } else {
                    foreach ($definition['components'] as $component) {
                        if (iheServiceMatchesAliases($serviceName, $component['aliases'])) {
                            $slot = (string)$component['slot'];
                            break;
                        }
                    }
                }

                if ($slot === null || isset($datasets[$slot])) {
                    continue;
                }

                $datasets[$slot] = iheBuildBaseServiceDataset($pdo, $service, $contractorsByRut);
            }

            return $datasets;
        }

        foreach ($services as $service) {
            if (iheServiceMatchesAliases((string)$service['servicio'], $definition['aliases'] ?? [])) {
                $datasets[] = iheBuildBaseServiceDataset($pdo, $service, $contractorsByRut);
            }
        }

        usort($datasets, static fn(array $a, array $b): int => strcmp((string)$a['servicio'], (string)$b['servicio']));
        return $datasets;
    }
}

if (!function_exists('iheBuildCaducadaRows')) {
    function iheBuildCaducadaRows(array $definition, array $allSheetRows, array $contractorsByRut, string $empresaNombre): array
    {
        $today = new DateTimeImmutable('today');
        $rows = [];
        $seen = [];

        foreach ($allSheetRows as $sheetKey => $sheetRows) {
            foreach ($sheetRows as $sheetRow) {
                $meta = $sheetRow['__meta'] ?? null;
                if (!is_array($meta)) {
                    continue;
                }
                if (($meta['estado_habilitacion'] ?? '') !== 'SI') {
                    continue;
                }
                $vigencia = $meta['vigencia_hasta'] ?? null;
                if (!$vigencia instanceof DateTimeImmutable || $vigencia >= $today) {
                    continue;
                }

                $composite = (string)$meta['rut_key'] . '|' . (string)$meta['servicio'];
                if (isset($seen[$composite])) {
                    continue;
                }
                $seen[$composite] = true;
                $contractor = $contractorsByRut[(string)$meta['rut_key']] ?? null;

                $row = array_fill_keys($definition['columns'], '');
                $row['RUT'] = (string)$meta['rut'];
                $row['Nombres'] = (string)$meta['nombre'];
                $row['Apellidos'] = (string)$meta['apellidos'];
                $row['Cargo del Contrato'] = trim((string)($contractor['cargo'] ?? $meta['cargo'] ?? ''));
                $row['Contratista General'] = $empresaNombre;
                $row['Servicio del Contrato'] = (string)$meta['servicio'];
                $row['Contratista'] = $empresaNombre;
                $row['Servicio Evaluado'] = (string)$meta['servicio'];
                $row['Cargo Evaluado'] = (string)($meta['cargo'] ?? '');
                $row['Estado Habilitacion'] = 'SI';
                $row['Pendiente'] = '';
                $row['UO'] = trim((string)($contractor['uo'] ?? $meta['uo'] ?? ''));
                $row['Sin Vigencia'] = iheFmtDate($vigencia);
                $row['SAGE'] = 'SI';
                $rows[] = $row;
            }
        }

        usort($rows, static fn(array $a, array $b): int => strcmp((string)$a['RUT'], (string)$b['RUT']));
        return $rows;
    }
}

if (!function_exists('iheBuildFaltantesRows')) {
    function iheBuildFaltantesRows(array $definition, array $allSheetRows, array $contractorsByRut, string $empresaNombre): array
    {
        $withData = [];
        foreach ($allSheetRows as $sheetKey => $sheetRows) {
            foreach ($sheetRows as $sheetRow) {
                $meta = $sheetRow['__meta'] ?? null;
                if (is_array($meta) && !empty($meta['rut_key'])) {
                    $withData[(string)$meta['rut_key']] = true;
                }
            }
        }

        $rows = [];
        foreach ($contractorsByRut as $rutKey => $contractor) {
            if (isset($withData[$rutKey])) {
                continue;
            }

            $row = array_fill_keys($definition['columns'], '');
            $row['RUT'] = (string)$contractor['rut'];
            $row['Nombres'] = trim((string)($contractor['nombre'] ?? ''));
            $row['Apellidos'] = trim((string)($contractor['apellidos'] ?? ''));
            $row['Cargo del Contrato'] = trim((string)($contractor['cargo'] ?? ''));
            $row['Contratista General'] = $empresaNombre;
            $row['Servicio del Contrato'] = '';
            $row['Contratista'] = $empresaNombre;
            $row['Servicio Evaluado'] = '';
            $row['Cargo Evaluado'] = '';
            $row['Estado Habilitacion'] = '';
            $row['Pendiente'] = '';
            $row['UO'] = trim((string)($contractor['uo'] ?? ''));
            $row['Sin Vigencia'] = '';
            $row['SAGE'] = 'SI';
            $rows[] = $row;
        }

        usort($rows, static fn(array $a, array $b): int => strcmp((string)$a['RUT'], (string)$b['RUT']));
        return $rows;
    }
}

if (!function_exists('iheBuildLegendRows')) {
    function iheBuildLegendRows(): array
    {
        return [
            ['ESTADO' => 'SI', 'OBSERVACION' => 'Persona habilitada con nota final mayor o igual a 4.0 y vigencia vigente.'],
            ['ESTADO' => 'NO', 'OBSERVACION' => 'Persona evaluada con nota final menor a 4.0.'],
            ['ESTADO' => 'Pendiente', 'OBSERVACION' => 'Persona con instrumentos pendientes o sin información suficiente para concluir.'],
            ['ESTADO' => 'NO Vigente', 'OBSERVACION' => 'Persona que estuvo habilitada, pero ya superó la vigencia interna de 3 años.'],
        ];
    }
}

if (!function_exists('iheBuildCompanyReport')) {
    function iheBuildCompanyReport(PDO $pdo, int $empresaId): array
    {
        $definitions = iheGetSheetDefinitions();
        $companies = iheFetchEmpresas($pdo);
        $empresaNombre = '';
        foreach ($companies as $company) {
            if ((int)$company['id'] === $empresaId) {
                $empresaNombre = (string)$company['nombre'];
                break;
            }
        }

        $contractorsByRut = $empresaId > 0 ? iheFetchCompanyContractors($pdo, $empresaId) : [];
        $services = iheFetchServicios($pdo);
        $sheets = [];

        foreach ($definitions as $definition) {
            $mode = $definition['mode'];
            if ($mode === 'legend' || $mode === 'caducada' || $mode === 'faltantes') {
                continue;
            }

            $datasets = iheResolveDatasetsForDefinition($pdo, $definition, $services, $contractorsByRut);
            if ($mode === 'dual') {
                $rows = iheBuildDualSheetRows($definition, $datasets, $empresaNombre);
            } elseif ($mode === 'cuadrillas') {
                $rows = iheBuildCuadrillasRows($definition, $datasets, $contractorsByRut);
            } else {
                $rows = [];
                foreach ($datasets as $dataset) {
                    foreach ($dataset['rows_by_rut'] as $baseRow) {
                        $rows[] = iheBuildStandardSheetRow($definition, $baseRow, $empresaNombre);
                    }
                }
            }

            usort($rows, static function (array $a, array $b): int {
                $leftName = (string)($a['Apellidos'] ?? $a['Nombre'] ?? '');
                $rightName = (string)($b['Apellidos'] ?? $b['Nombre'] ?? '');
                $cmp = strcmp($leftName, $rightName);
                if ($cmp !== 0) {
                    return $cmp;
                }
                return strcmp((string)($a['RUT'] ?? ''), (string)($b['RUT'] ?? ''));
            });

            $sheets[$definition['key']] = [
                'definition' => $definition,
                'rows' => $rows,
            ];
        }

        $serviceRowsForAux = [];
        foreach ($sheets as $key => $sheet) {
            if (in_array($key, ['cuadrillas_cyr_bt'], true)) {
                continue;
            }
            $serviceRowsForAux[$key] = $sheet['rows'];
        }

        $legendDefinition = iheGetSelectedDefinition('estados');
        $sheets['estados'] = [
            'definition' => $legendDefinition,
            'rows' => iheBuildLegendRows(),
        ];

        $cadDefinition = iheGetSelectedDefinition('caducada_vigencia');
        $sheets['caducada_vigencia'] = [
            'definition' => $cadDefinition,
            'rows' => iheBuildCaducadaRows($cadDefinition, $serviceRowsForAux, $contractorsByRut, $empresaNombre),
        ];

        $faltDefinition = iheGetSelectedDefinition('personas_faltan');
        $sheets['personas_faltan'] = [
            'definition' => $faltDefinition,
            'rows' => iheBuildFaltantesRows($faltDefinition, $serviceRowsForAux, $contractorsByRut, $empresaNombre),
        ];

        $orderedSheets = [];
        foreach ($definitions as $definition) {
            $key = $definition['key'];
            if (isset($sheets[$key])) {
                $orderedSheets[$key] = $sheets[$key];
            }
        }

        return [
            'empresa_id' => $empresaId,
            'empresa_nombre' => $empresaNombre,
            'companies' => $companies,
            'definitions' => $definitions,
            'sheets' => $orderedSheets,
            'contractors_count' => count($contractorsByRut),
        ];
    }
}
