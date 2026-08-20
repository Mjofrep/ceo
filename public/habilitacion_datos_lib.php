<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/functions.php';

function habDataToolConfig(): array
{
    return [
        'ceo_contratistas' => [
            'label' => 'Contratistas',
            'description' => 'Base de trabajadores con RUT, cargo, empresa y unidad operativa.',
            'primary_key' => ['id'],
            'editable_columns' => ['rut', 'nombre', 'apellidos', 'correo', 'telefono', 'id_cargo', 'fecha_ingreso', 'id_empresa', 'uo'],
            'default_order' => ['id' => 'DESC'],
        ],
        'ceo_habilitacion' => [
            'label' => 'Habilitacion',
            'description' => 'Cabecera de habilitacion por cuadrilla, servicio y empresa.',
            'primary_key' => ['id'],
            'editable_columns' => ['fecha', 'jornada', 'id_servicio', 'cuadrilla', 'empresa', 'uo', 'gestor', 'nsolicitud', 'Estado'],
            'default_order' => ['id' => 'DESC'],
        ],
        'ceo_habilitacion_participantes' => [
            'label' => 'Participantes Habilitacion',
            'description' => 'Participantes asociados a la cuadrilla de habilitacion.',
            'primary_key' => ['id'],
            'editable_columns' => ['id_cuadrilla', 'reevaluo', 'rut', 'nombre', 'apellidos', 'cargo'],
            'default_order' => ['id' => 'DESC'],
        ],
        'ceo_evaluaciones_programadas' => [
            'label' => 'Evaluaciones Programadas',
            'description' => 'Planificacion de pruebas teoricas y terreno por participante.',
            'primary_key' => ['id'],
            'editable_columns' => ['rut', 'id_servicio', 'id_agrupacion', 'tipo', 'cuadrilla', 'id_proceso_habilitacion', 'fecha_programacion', 'usuario_programa', 'estado', 'intento', 'resultado', 'fecha_resultado', 'cobrado'],
            'default_order' => ['id' => 'DESC'],
        ],
        'ceo_proceso_habilitacion' => [
            'label' => 'Proceso Habilitacion',
            'description' => 'Proceso logico abierto o cerrado que agrupa intentos y vigencias.',
            'primary_key' => ['id'],
            'editable_columns' => ['rut', 'id_servicio', 'id_cargo', 'numero_proceso', 'estado', 'origen', 'fecha_inicio', 'fecha_cierre'],
            'default_order' => ['id' => 'DESC'],
        ],
        'ceo_resultado_prueba_intento' => [
            'label' => 'Intentos Teoricos',
            'description' => 'Resumen por intento de prueba teorica.',
            'primary_key' => ['id'],
            'editable_columns' => ['rut', 'id_servicio', 'id_proceso_habilitacion', 'id_evaluador', 'fecha_rendicion', 'hora_rendicion', 'puntaje_total', 'correctas', 'incorrectas', 'ncontestadas', 'noaplica', 'notafinal'],
            'default_order' => ['id' => 'DESC'],
        ],
        'ceo_resultado_pruebat' => [
            'label' => 'Respuestas Teoricas',
            'description' => 'Detalle de respuestas teoricas por pregunta e intento.',
            'primary_key' => ['id'],
            'editable_columns' => ['rut', 'id_pregunta', 'respuesta', 'fecha_rendicion', 'hora_rendicion', 'proceso', 'validacion', 'intento'],
            'default_order' => ['id' => 'DESC'],
        ],
        'ceo_resultado_terreno_intento' => [
            'label' => 'Intentos Terreno',
            'description' => 'Resumen por intento de evaluacion de terreno.',
            'primary_key' => ['id'],
            'editable_columns' => ['rut', 'id_servicio', 'id_proceso_habilitacion', 'id_evaluador', 'fecha_rendicion', 'hora_rendicion', 'puntaje_total', 'correctas', 'incorrectas', 'ncontestadas', 'noaplica', 'notafinal'],
            'default_order' => ['id' => 'DESC'],
        ],
        'ceo_resultado_prueba_terreno' => [
            'label' => 'Respuestas Terreno',
            'description' => 'Detalle de respuestas de terreno por seccion, pregunta y participante.',
            'primary_key' => ['id_resultado', 'id_pregunta', 'id_seccion', 'rut_contratista'],
            'editable_columns' => ['cumple', 'no_cumple', 'no_aplica', 'observaciones', 'practico', 'referente', 'fecha'],
            'default_order' => ['id_resultado' => 'DESC'],
        ],
        'ceo_evaluacion_terreno' => [
            'label' => 'Evaluacion Terreno',
            'description' => 'Resultado consolidado de terreno por persona.',
            'primary_key' => ['id'],
            'editable_columns' => ['codigo_evaluacion', 'rut', 'nombre', 'cargo', 'contratista', 'evaluador', 'usuario', 'resultado', 'id_servicio', 'id_proceso_habilitacion', 'fecha_evaluacion', 'fecha_inicio', 'fecha_fin', 'fecha_aprobacion', 'uo', 'unidad', 'ciudad', 'comuna', 'region', 'coordenadas_inicio', 'coordenadas_fin', 'comentarios_finales', 'comentarios_responsable'],
            'default_order' => ['id' => 'DESC'],
        ],
        'ceo_agrupacion' => [
            'label' => 'Agrupaciones Teoricas',
            'description' => 'Configuracion de agrupaciones de preguntas teoricas por servicio.',
            'primary_key' => ['id'],
            'editable_columns' => ['titulo', 'id_servicio', 'tiempo', 'cantidad', 'total'],
            'default_order' => ['id' => 'DESC'],
        ],
        'ceo_porcentaje_agrupacion' => [
            'label' => 'Porcentaje Aprobacion Teorica',
            'description' => 'Umbral minimo de aprobacion por agrupacion teorica.',
            'primary_key' => ['id'],
            'editable_columns' => ['id_agrupacion', 'porcentaje', 'fechadesde', 'activo'],
            'default_order' => ['id' => 'DESC'],
        ],
        'ceo_preguntas_servicios' => [
            'label' => 'Preguntas Teoricas',
            'description' => 'Banco de preguntas teoricas por servicio y agrupacion.',
            'primary_key' => ['id'],
            'editable_columns' => ['pregunta', 'id_servicio', 'imagen', 'estado', 'id_agrupacion', 'retropos', 'retroneg', 'areacomp'],
            'default_order' => ['id' => 'DESC'],
        ],
        'ceo_alternativas_preguntas' => [
            'label' => 'Alternativas Teoricas',
            'description' => 'Alternativas de respuesta de preguntas teoricas.',
            'primary_key' => ['id'],
            'editable_columns' => ['alternativa', 'id_pregunta', 'estado', 'imagen', 'correcta'],
            'default_order' => ['id' => 'DESC'],
        ],
        'ceo_agrupacion_terreno' => [
            'label' => 'Agrupaciones Terreno',
            'description' => 'Agrupaciones base para formularios de terreno por servicio.',
            'primary_key' => ['id'],
            'editable_columns' => ['grupo', 'id_servicio'],
            'default_order' => ['id' => 'DESC'],
        ],
        'ceo_porcentaje_agrup_terreno' => [
            'label' => 'Porcentaje Aprobacion Terreno',
            'description' => 'Umbral minimo de aprobacion por agrupacion de terreno.',
            'primary_key' => ['id'],
            'editable_columns' => ['id_agrupacion', 'porcentaje', 'fechadesde', 'activo'],
            'default_order' => ['id' => 'DESC'],
        ],
        'ceo_seccion_terreno' => [
            'label' => 'Secciones Terreno',
            'description' => 'Secciones o areas del formulario de evaluacion terreno.',
            'primary_key' => ['id'],
            'editable_columns' => ['seccion', 'nombre', 'id_grupo', 'orden'],
            'default_order' => ['id' => 'DESC'],
        ],
        'ceo_preguntas_seccion_terreno' => [
            'label' => 'Preguntas Terreno',
            'description' => 'Items o preguntas de cada seccion del formulario terreno.',
            'primary_key' => ['id'],
            'editable_columns' => ['id_seccion', 'pregunta', 'cumplesi', 'cumpleno', 'cumplena', 'ponderacion', 'practico', 'referente', 'orden'],
            'default_order' => ['id' => 'DESC'],
        ],
        'ceo_resultado_final_servicio' => [
            'label' => 'Resultado Final Servicio',
            'description' => 'Consolidado final por servicio, proceso y cargo.',
            'primary_key' => ['id'],
            'editable_columns' => ['rut', 'id_servicio', 'id_proceso', 'id_proceso_habilitacion', 'cargo', 'segmento', 'nota_prueba', 'nota_terreno', 'porcentaje_prueba', 'porcentaje_terreno', 'ponderacion_prueba', 'ponderacion_terreno', 'nota_final', 'resultado_final', 'observacion', 'fecha_calculo'],
            'default_order' => ['id' => 'DESC'],
        ],
        'ceo_vigencia_detalle' => [
            'label' => 'Vigencia Detalle',
            'description' => 'Vigencia por servicio y proceso dentro de la habilitacion.',
            'primary_key' => ['id'],
            'editable_columns' => ['rut', 'id_servicio', 'fechavig_ini', 'fechavig_fin', 'id_proceso', 'id_proceso_habilitacion', 'tipo'],
            'default_order' => ['id' => 'DESC'],
        ],
    ];
}

function habDataToolObjectives(): array
{
    return [
        [
            'id' => 'planificacion',
            'label' => 'Planificacion',
            'description' => 'Programacion y armado operativo de la habilitacion.',
            'tables' => ['ceo_habilitacion', 'ceo_habilitacion_participantes', 'ceo_evaluaciones_programadas', 'ceo_proceso_habilitacion'],
        ],
        [
            'id' => 'revision',
            'label' => 'Revision y Consulta Operativa',
            'description' => 'Vista transversal del trabajador, programacion, intentos y vigencias.',
            'tables' => ['ceo_contratistas', 'ceo_habilitacion', 'ceo_habilitacion_participantes', 'ceo_evaluaciones_programadas', 'ceo_resultado_prueba_intento', 'ceo_resultado_terreno_intento', 'ceo_evaluacion_terreno', 'ceo_resultado_final_servicio', 'ceo_vigencia_detalle', 'ceo_proceso_habilitacion'],
        ],
        [
            'id' => 'teorica',
            'label' => 'Resultado Prueba Teorica',
            'description' => 'Resumenes de intentos y estado teorico por participante.',
            'tables' => ['ceo_resultado_prueba_intento', 'ceo_resultado_pruebat'],
        ],
        [
            'id' => 'respuestas_teoricas',
            'label' => 'Lo que Respondieron en Teoria',
            'description' => 'Detalle de respuestas teoricas y tablas de apoyo para interpretarlas.',
            'tables' => ['ceo_resultado_pruebat', 'ceo_agrupacion', 'ceo_porcentaje_agrupacion', 'ceo_preguntas_servicios', 'ceo_alternativas_preguntas'],
        ],
        [
            'id' => 'terreno',
            'label' => 'Resultado Terreno',
            'description' => 'Resumenes e informacion consolidada de evaluacion terreno.',
            'tables' => ['ceo_resultado_terreno_intento', 'ceo_evaluacion_terreno', 'ceo_resultado_prueba_terreno'],
        ],
        [
            'id' => 'respuestas_terreno',
            'label' => 'Lo que Respondieron en Terreno',
            'description' => 'Detalle de respuestas de terreno y estructura base del formulario.',
            'tables' => ['ceo_resultado_prueba_terreno', 'ceo_agrupacion_terreno', 'ceo_porcentaje_agrup_terreno', 'ceo_seccion_terreno', 'ceo_preguntas_seccion_terreno'],
        ],
        [
            'id' => 'vigencia',
            'label' => 'Resultado Final y Vigencia',
            'description' => 'Consolidado final por servicio y vigencia generada.',
            'tables' => ['ceo_resultado_final_servicio', 'ceo_vigencia_detalle', 'ceo_proceso_habilitacion'],
        ],
    ];
}

function habDataToolTableConfig(string $table): ?array
{
    $config = habDataToolConfig();
    return $config[$table] ?? null;
}

function habDataToolAllowedTables(): array
{
    return array_keys(habDataToolConfig());
}

function habDataToolDescribeTable(PDO $pdo, string $table): array
{
    $config = habDataToolTableConfig($table);
    if ($config === null) {
        throw new InvalidArgumentException('Tabla no permitida.');
    }

    $stmt = $pdo->query('DESCRIBE `' . str_replace('`', '``', $table) . '`');
    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $field = (string)($row['Field'] ?? '');
        if ($field === '') {
            continue;
        }
        $columns[$field] = [
            'name' => $field,
            'type' => (string)($row['Type'] ?? ''),
            'null' => (string)($row['Null'] ?? '') === 'YES',
            'key' => (string)($row['Key'] ?? ''),
            'default' => $row['Default'] ?? null,
            'extra' => (string)($row['Extra'] ?? ''),
            'editable' => in_array($field, $config['editable_columns'], true),
            'is_primary' => in_array($field, $config['primary_key'], true),
        ];
    }

    return $columns;
}

function habDataToolFormatPrimaryKey(array $primaryKey, array $row): string
{
    $parts = [];
    foreach ($primaryKey as $column) {
        $parts[] = $column . '=' . (string)($row[$column] ?? '');
    }
    return implode(' | ', $parts);
}

function habDataToolNormalizeValue(string $type, mixed $value): mixed
{
    if ($value === '') {
        $typeLower = strtolower($type);
        if (str_contains($typeLower, 'int') || str_contains($typeLower, 'decimal') || str_contains($typeLower, 'float') || str_contains($typeLower, 'double')) {
            return null;
        }
    }

    return $value;
}

function habDataToolColumnFilterMode(string $type): string
{
    $typeLower = strtolower($type);
    if (str_contains($typeLower, 'date') || str_contains($typeLower, 'time')) {
        return 'date';
    }
    if (str_contains($typeLower, 'int') || str_contains($typeLower, 'decimal') || str_contains($typeLower, 'float') || str_contains($typeLower, 'double')) {
        return 'number';
    }
    if (str_starts_with($typeLower, 'enum(')) {
        return 'enum';
    }
    return 'text';
}

function habDataToolEnumValues(string $type): array
{
    $typeLower = strtolower($type);
    if (!str_starts_with($typeLower, 'enum(')) {
        return [];
    }

    if (!preg_match('/^enum\((.*)\)$/i', $type, $matches)) {
        return [];
    }

    $raw = (string)($matches[1] ?? '');
    if ($raw === '') {
        return [];
    }

    $parts = str_getcsv($raw, ',', "'", '\\');
    return array_values(array_filter(array_map(static fn($v) => trim((string)$v), $parts), static fn($v) => $v !== ''));
}

function habDataToolNormalizeFilters(array $columnsMap, mixed $filters): array
{
    $filters = is_array($filters) ? $filters : [];
    $whereParts = [];
    $params = [];
    $filterValues = [];

    foreach ($filters as $column => $value) {
        $column = trim((string)$column);
        if ($column === '' || !isset($columnsMap[$column])) {
            continue;
        }

        $value = trim((string)$value);
        if ($value === '') {
            continue;
        }

        $filterValues[$column] = $value;
        $placeholder = ':f_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $column) . '_' . count($params);
        $mode = habDataToolColumnFilterMode((string)$columnsMap[$column]['type']);

        if ($mode === 'number') {
            $whereParts[] = '`' . $column . '` = ' . $placeholder;
            $params[$placeholder] = $value;
        } elseif ($mode === 'date') {
            $whereParts[] = 'CAST(`' . $column . '` AS CHAR) LIKE ' . $placeholder;
            $params[$placeholder] = $value . '%';
        } else {
            $whereParts[] = 'CAST(`' . $column . '` AS CHAR) LIKE ' . $placeholder;
            $params[$placeholder] = '%' . $value . '%';
        }
    }

    return [
        'where_sql' => $whereParts ? (' WHERE ' . implode(' AND ', $whereParts)) : '',
        'params' => $params,
        'filter_values' => $filterValues,
    ];
}

function habDataToolBuildOrderSql(array $columnsMap, array $config): string
{
    $columnNames = array_keys($columnsMap);
    $orderParts = [];

    foreach (($config['default_order'] ?? []) as $column => $direction) {
        if (isset($columnsMap[$column])) {
            $dir = strtoupper((string)$direction) === 'ASC' ? 'ASC' : 'DESC';
            $orderParts[] = '`' . $column . '` ' . $dir;
        }
    }

    if ($orderParts === [] && $columnNames !== []) {
        $orderParts[] = '`' . $columnNames[0] . '` DESC';
    }

    return implode(', ', $orderParts);
}

function habDataToolBuildQueryContext(PDO $pdo, string $table, mixed $filters = []): array
{
    $config = habDataToolTableConfig($table);
    if ($config === null) {
        throw new InvalidArgumentException('Tabla no permitida.');
    }

    $columnsMap = habDataToolDescribeTable($pdo, $table);
    $columnNames = array_keys($columnsMap);
    $filterData = habDataToolNormalizeFilters($columnsMap, $filters);

    return [
        'config' => $config,
        'columns_map' => $columnsMap,
        'column_names' => $columnNames,
        'where_sql' => (string)$filterData['where_sql'],
        'params' => $filterData['params'],
        'filter_values' => $filterData['filter_values'],
        'order_sql' => habDataToolBuildOrderSql($columnsMap, $config),
    ];
}

function habDataToolBuildColumnsPayload(array $columnsMap, array $filterValues = []): array
{
    $columns = [];
    foreach (array_keys($columnsMap) as $columnName) {
        $column = $columnsMap[$columnName];
        $columns[] = [
            'name' => $columnName,
            'label' => $columnName,
            'type' => (string)$column['type'],
            'editable' => (bool)$column['editable'],
            'is_primary' => (bool)$column['is_primary'],
            'filter_mode' => habDataToolColumnFilterMode((string)$column['type']),
            'enum_values' => habDataToolEnumValues((string)$column['type']),
            'filter_value' => $filterValues[$columnName] ?? '',
        ];
    }

    return $columns;
}
