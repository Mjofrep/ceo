<?php
declare(strict_types=1);

$gpWorkflowAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($gpWorkflowAutoload)) {
    require_once $gpWorkflowAutoload;
}

function gpQuestionValidation(PDO $pdo, int $idPregunta): array
{
    $stmt = $pdo->prepare('SELECT pregunta, destino, id_servicio, id_agrupacion FROM ceo_gp_preguntas WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $idPregunta]);
    $q = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$q) {
        return ['Pregunta no encontrada.'];
    }
    $errors = [];
    if (trim((string)$q['pregunta']) === '') {
        $errors[] = 'La pregunta esta vacia.';
    }
    if ((int)$q['id_servicio'] <= 0 || (int)($q['id_agrupacion'] ?? 0) <= 0) {
        $errors[] = 'Debe tener servicio y agrupacion definidos.';
    }
    $stmtAlt = $pdo->prepare('SELECT alternativa, correcta FROM ceo_gp_alternativas WHERE id_pregunta = :id AND estado = "A" ORDER BY orden ASC, id ASC');
    $stmtAlt->execute([':id' => $idPregunta]);
    $alts = $stmtAlt->fetchAll(PDO::FETCH_ASSOC);
    if (count($alts) < 2) {
        $errors[] = 'Debe tener al menos 2 alternativas.';
    }
    $correctas = 0;
    foreach ($alts as $alt) {
        if (trim((string)$alt['alternativa']) === '') {
            $errors[] = 'Existen alternativas vacias.';
        }
        if ((string)$alt['correcta'] === 'S') {
            $correctas++;
        }
    }
    if ($correctas !== 1) {
        $errors[] = 'Debe tener exactamente una alternativa correcta.';
    }
    if ((string)$q['destino'] === 'FORMACION') {
        if (mb_strlen((string)$q['pregunta'], 'UTF-8') > 500) {
            $errors[] = 'La pregunta supera 500 caracteres para publicacion en formacion.';
        }
        foreach ($alts as $alt) {
            if (mb_strlen((string)$alt['alternativa'], 'UTF-8') > 500) {
                $errors[] = 'Una alternativa supera 500 caracteres para publicacion en formacion.';
                break;
            }
        }
    }
    return array_values(array_unique($errors));
}

function gpPublicationValidation(PDO $pdo, int $idPregunta): array
{
    $errors = gpQuestionValidation($pdo, $idPregunta);
    $stmt = $pdo->prepare('SELECT id_area FROM ceo_gp_preguntas WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $idPregunta]);
    $idArea = (int)$stmt->fetchColumn();
    if ($idArea <= 0) {
        $errors[] = 'Debe tener area de competencia definida.';
    }
    return array_values(array_unique($errors));
}

function gpAddRevisionLog(PDO $pdo, int $idPregunta, ?string $desde, string $hasta, string $comentario, int $usuario): void
{
    $stmt = $pdo->prepare('INSERT INTO ceo_gp_revision (id_pregunta, estado_desde, estado_hasta, comentario, creado_por) VALUES (:id_pregunta, :desde, :hasta, :comentario, :creado_por)');
    $stmt->execute([
        ':id_pregunta' => $idPregunta,
        ':desde' => $desde,
        ':hasta' => $hasta,
        ':comentario' => $comentario !== '' ? $comentario : null,
        ':creado_por' => $usuario > 0 ? $usuario : null,
    ]);
}

function gpWorkflowSmtpConfig(): array
{
    return [
        'host' => 'mail.noetica.cl',
        'username' => 'ceo@noetica.cl',
        'password' => 'Neotica_1964$',
        'port' => 465,
        'from_email' => 'ceo@noetica.cl',
        'from_name' => 'Sistema CEO',
    ];
}

function gpFetchOperacionUsers(PDO $pdo, string $destino = '', int $idServicio = 0): array
{
    $params = [':rol' => 'OPERACION'];
    $whereService = '';
    if ($idServicio > 0 && in_array($destino, ['HABILITACION', 'FORMACION'], true)) {
        $whereService = " AND EXISTS (
            SELECT 1
            FROM ceo_gp_usuario_servicio us
            WHERE us.id_usuario = u.id
              AND us.id_servicio = :id_servicio
              AND us.destino IN (:destino, 'AMBOS')
        )";
        $params[':id_servicio'] = $idServicio;
        $params[':destino'] = $destino;
    }

    $sql = "SELECT u.id, u.usuario, u.nombres, u.apellidos, u.correo
        FROM ceo_gp_usuarios u
        INNER JOIN ceo_gp_roles r ON r.id = u.id_rol
        WHERE u.estado = 'A'
          AND r.estado = 'A'
          AND r.codigo = :rol
          {$whereService}
        ORDER BY u.nombres ASC, u.apellidos ASC, u.usuario ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function gpFetchAdminEmails(PDO $pdo): array
{
    $stmt = $pdo->prepare("SELECT u.correo
        FROM ceo_gp_usuarios u
        INNER JOIN ceo_gp_roles r ON r.id = u.id_rol
        WHERE u.estado = 'A'
          AND r.estado = 'A'
          AND r.codigo = 'ADMIN'
          AND u.correo IS NOT NULL
          AND u.correo <> ''
        ORDER BY u.correo ASC");
    $stmt->execute();
    return array_values(array_filter(array_map('trim', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
}

function gpFetchOperacionUserById(PDO $pdo, int $idUsuario): ?array
{
    if ($idUsuario <= 0) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT u.id, u.usuario, u.nombres, u.apellidos, u.correo
        FROM ceo_gp_usuarios u
        INNER JOIN ceo_gp_roles r ON r.id = u.id_rol
        WHERE u.id = :id
          AND u.estado = 'A'
          AND r.estado = 'A'
          AND r.codigo = 'OPERACION'
        LIMIT 1");
    $stmt->execute([':id' => $idUsuario]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function gpSendOperacionAssignmentMail(PDO $pdo, array $operator, array $context, int $assignedBy): array
{
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        return ['sent' => false, 'warning' => 'PHPMailer no esta disponible para notificar.'];
    }
    $to = trim((string)($operator['correo'] ?? ''));
    if ($to === '') {
        return ['sent' => false, 'warning' => 'El operador seleccionado no tiene correo registrado.'];
    }

    $admins = gpFetchAdminEmails($pdo);
    $assignedByUser = gpFetchOperacionUserById($pdo, $assignedBy);
    $assignedByLabel = trim((string)(($assignedByUser['nombres'] ?? '') . ' ' . ($assignedByUser['apellidos'] ?? '')));
    if ($assignedByLabel === '') {
        $stmt = $pdo->prepare('SELECT nombres, apellidos FROM ceo_gp_usuarios WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $assignedBy]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $assignedByLabel = trim((string)(($user['nombres'] ?? '') . ' ' . ($user['apellidos'] ?? '')));
    }

    $cfg = gpWorkflowSmtpConfig();
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $cfg['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $cfg['username'];
        $mail->Password = $cfg['password'];
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = $cfg['port'];
        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($to);
        foreach ($admins as $admin) {
            if ($admin !== '' && strcasecmp($admin, $to) !== 0) {
                $mail->addCC($admin);
            }
        }
        $mail->isHTML(true);
        $mail->Subject = 'Prueba asignada a Operacion - ' . (string)($context['servicio'] ?? '') . ' - ' . (string)($context['agrupacion'] ?? '');
        $mail->Body = '<html><body style="font-family:Arial,sans-serif">'
            . '<h3 style="color:#0046AD;">Asignacion de prueba a Operacion</h3>'
            . '<p>Se ha asignado una prueba para revision de Operacion. Ingresa al Gestor de Preguntas para revisar el cuestionario correspondiente.</p>'
            . '<table cellpadding="6" cellspacing="0" style="font-size:14px">'
            . '<tr><td><b>Operador:</b></td><td>' . gpEsc(trim((string)(($operator['nombres'] ?? '') . ' ' . ($operator['apellidos'] ?? '')))) . '</td></tr>'
            . '<tr><td><b>Servicio:</b></td><td>' . gpEsc((string)($context['servicio'] ?? '')) . '</td></tr>'
            . '<tr><td><b>Agrupacion:</b></td><td>' . gpEsc((string)($context['agrupacion'] ?? '')) . '</td></tr>'
            . '<tr><td><b>Fuente o carga:</b></td><td>' . gpEsc((string)($context['fuente'] ?? '')) . '</td></tr>'
            . '<tr><td><b>Preguntas:</b></td><td>' . gpEsc((string)($context['preguntas'] ?? '0')) . '</td></tr>'
            . '<tr><td><b>Asignado por:</b></td><td>' . gpEsc($assignedByLabel !== '' ? $assignedByLabel : ('Usuario #' . $assignedBy)) . '</td></tr>'
            . '<tr><td><b>Fecha de asignacion:</b></td><td>' . gpEsc(date('d-m-Y H:i')) . '</td></tr>'
            . '<tr><td><b>Acceso:</b></td><td><a href="https://www.noetica.cl/ceo.noetica.cl/public/gp_login.php">Abrir Gestor de Preguntas</a></td></tr>'
            . '</table>'
            . '<hr><small>Mensaje generado automaticamente por el sistema CEO.</small>'
            . '</body></html>';
        $mail->send();
        return ['sent' => true, 'warning' => ''];
    } catch (\Throwable $e) {
        return ['sent' => false, 'warning' => 'No se pudo enviar correo al operador: ' . $e->getMessage()];
    }
}

function gpWorkflowBucketTokenFromRow(array $row): string
{
    $idGeneracion = (int)($row['id_generacion'] ?? 0);
    $idFuente = (int)($row['id_fuente'] ?? 0);
    return $idGeneracion > 0 ? 'G:' . $idGeneracion : 'F:' . $idFuente;
}

function gpWorkflowBucketFiltersFromToken(string $token): array
{
    $token = trim($token);
    if ($token === '') {
        return [];
    }
    if (preg_match('/^G:(\d+)$/', $token, $m)) {
        return ['id_generacion' => (int)$m[1]];
    }
    if (preg_match('/^F:(\d+)$/', $token, $m)) {
        return ['id_fuente' => (int)$m[1], 'sin_generacion' => true];
    }
    return [];
}

function gpWorkflowBucketLabel(array $row): string
{
    $fuente = trim((string)($row['fuente'] ?? 'Sin fuente'));
    $total = (int)($row['total'] ?? 0);
    $idGeneracion = (int)($row['id_generacion'] ?? 0);
    $idFuente = (int)($row['id_fuente'] ?? 0);
    if ($idGeneracion > 0) {
        return 'Generacion #' . $idGeneracion . ' | ' . $fuente . ' | ' . $total . ' preguntas';
    }
    return 'Fuente #' . $idFuente . ' | ' . $fuente . ' | ' . $total . ' preguntas';
}

function gpWorkflowBuildWhere(array $states, array $filters = []): array
{
    if (!$states) {
        throw new RuntimeException('Se requiere al menos un estado para el workflow.');
    }

    $conditions = ['q.estado IN (' . implode(',', array_fill(0, count($states), '?')) . ')'];
    $params = $states;

    if ((int)($filters['id_agrupacion'] ?? 0) > 0) {
        $conditions[] = 'q.id_agrupacion = ?';
        $params[] = (int)$filters['id_agrupacion'];
    }
    if ((int)($filters['id_servicio'] ?? 0) > 0) {
        $conditions[] = 'q.id_servicio = ?';
        $params[] = (int)$filters['id_servicio'];
    }
    if ((int)($filters['id_operador_asignado'] ?? 0) > 0) {
        $conditions[] = 'q.id_operador_asignado = ?';
        $params[] = (int)$filters['id_operador_asignado'];
    }
    if ((int)($filters['id_fuente'] ?? 0) > 0) {
        $conditions[] = 'q.id_fuente = ?';
        $params[] = (int)$filters['id_fuente'];
    }
    if (!empty($filters['sin_generacion'])) {
        $conditions[] = '(q.id_generacion IS NULL OR q.id_generacion = 0)';
    } elseif ((int)($filters['id_generacion'] ?? 0) > 0) {
        $conditions[] = 'q.id_generacion = ?';
        $params[] = (int)$filters['id_generacion'];
    }
    if (!empty($filters['ids']) && is_array($filters['ids'])) {
        $ids = array_values(array_filter(array_map('intval', $filters['ids']), static fn(int $id): bool => $id > 0));
        if (!$ids) {
            return ['where' => '1 = 0', 'params' => []];
        }
        $conditions[] = 'q.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        foreach ($ids as $id) {
            $params[] = $id;
        }
    }

    return ['where' => implode(' AND ', $conditions), 'params' => $params];
}

function gpFetchWorkflowBuckets(PDO $pdo, array $states, array $filters = []): array
{
    $where = gpWorkflowBuildWhere($states, $filters);
    $sql = "SELECT
        q.destino,
        q.id_servicio,
        q.id_agrupacion,
        q.id_fuente,
        q.id_generacion,
        COUNT(*) AS total,
        SUM(CASE WHEN q.estado = 'REVISION' THEN 1 ELSE 0 END) AS total_revision,
        SUM(CASE WHEN q.estado = 'OBSERVADA' THEN 1 ELSE 0 END) AS total_observada,
        SUM(CASE WHEN q.estado = 'OPERACION' THEN 1 ELSE 0 END) AS total_operacion,
        SUM(CASE WHEN q.estado = 'APROBADA_OPERACION' THEN 1 ELSE 0 END) AS total_visada,
        SUM(CASE WHEN q.estado = 'PUBLICADA' THEN 1 ELSE 0 END) AS total_publicada,
        MAX(COALESCE(q.fecha_actualizacion, q.fecha_creacion)) AS ultima_fecha,
        COALESCE(f.titulo, 'Sin fuente') AS fuente,
        MAX(COALESCE(op.nombres, '')) AS operador_nombres,
        MAX(COALESCE(op.apellidos, '')) AS operador_apellidos,
        MAX(COALESCE(asg.nombres, '')) AS asignado_por_nombres,
        MAX(COALESCE(asg.apellidos, '')) AS asignado_por_apellidos,
        MAX(q.fecha_asignacion_operacion) AS fecha_asignacion_operacion,
        CASE WHEN q.destino = 'FORMACION'
             THEN (SELECT fs.servicio FROM ceo_formacion_servicios fs WHERE fs.id = q.id_servicio LIMIT 1)
             ELSE (SELECT sp.servicio FROM ceo_servicios_pruebas sp WHERE sp.id = q.id_servicio LIMIT 1)
        END AS servicio,
        CASE WHEN q.destino = 'FORMACION'
             THEN (SELECT fa.titulo FROM ceo_formacion_agrupacion fa WHERE fa.id = q.id_agrupacion LIMIT 1)
             ELSE (SELECT a.titulo FROM ceo_agrupacion a WHERE a.id = q.id_agrupacion LIMIT 1)
        END AS agrupacion
        FROM ceo_gp_preguntas q
        LEFT JOIN ceo_gp_fuentes f ON f.id = q.id_fuente
        LEFT JOIN ceo_gp_usuarios op ON op.id = q.id_operador_asignado
        LEFT JOIN ceo_gp_usuarios asg ON asg.id = q.asignado_operacion_por
        WHERE {$where['where']}
        GROUP BY q.destino, q.id_servicio, q.id_agrupacion, q.id_fuente, q.id_generacion, f.titulo
        ORDER BY agrupacion ASC, ultima_fecha DESC, q.id_generacion DESC, q.id_fuente DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($where['params']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row['bucket_token'] = gpWorkflowBucketTokenFromRow($row);
        $row['bucket_label'] = gpWorkflowBucketLabel($row);
    }
    unset($row);
    return $rows;
}

function gpFetchWorkflowQuestions(PDO $pdo, array $states, array $filters = []): array
{
    $where = gpWorkflowBuildWhere($states, $filters);
    $sql = "SELECT q.*,
        f.titulo AS fuente,
        COALESCE(op.nombres, '') AS operador_nombres,
        COALESCE(op.apellidos, '') AS operador_apellidos,
        COALESCE(asg.nombres, '') AS asignado_por_nombres,
        COALESCE(asg.apellidos, '') AS asignado_por_apellidos,
        CASE WHEN q.destino = 'FORMACION'
             THEN (SELECT fs.servicio FROM ceo_formacion_servicios fs WHERE fs.id = q.id_servicio LIMIT 1)
             ELSE (SELECT sp.servicio FROM ceo_servicios_pruebas sp WHERE sp.id = q.id_servicio LIMIT 1)
        END AS servicio,
        CASE WHEN q.destino = 'FORMACION'
             THEN (SELECT fa.titulo FROM ceo_formacion_agrupacion fa WHERE fa.id = q.id_agrupacion LIMIT 1)
             ELSE (SELECT a.titulo FROM ceo_agrupacion a WHERE a.id = q.id_agrupacion LIMIT 1)
        END AS agrupacion
        FROM ceo_gp_preguntas q
        LEFT JOIN ceo_gp_fuentes f ON f.id = q.id_fuente
        LEFT JOIN ceo_gp_usuarios op ON op.id = q.id_operador_asignado
        LEFT JOIN ceo_gp_usuarios asg ON asg.id = q.asignado_operacion_por
        WHERE {$where['where']}
        ORDER BY q.id ASC
        LIMIT 500";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($where['params']);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$questions) {
        return [];
    }
    $ids = array_map(static fn($q) => (int)$q['id'], $questions);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmtAlt = $pdo->prepare("SELECT * FROM ceo_gp_alternativas WHERE id_pregunta IN ($ph) AND estado = 'A' ORDER BY id_pregunta ASC, orden ASC, id ASC");
    $stmtAlt->execute($ids);
    $alts = [];
    foreach ($stmtAlt->fetchAll(PDO::FETCH_ASSOC) as $alt) {
        $alts[(int)$alt['id_pregunta']][] = $alt;
    }
    $stmtRev = $pdo->prepare("SELECT r.*, u.usuario FROM ceo_gp_revision r LEFT JOIN ceo_gp_usuarios u ON u.id = r.creado_por WHERE r.id_pregunta IN ($ph) ORDER BY r.id DESC");
    $stmtRev->execute($ids);
    $logs = [];
    foreach ($stmtRev->fetchAll(PDO::FETCH_ASSOC) as $log) {
        $logs[(int)$log['id_pregunta']][] = $log;
    }
    foreach ($questions as &$q) {
        $q['alternativas'] = $alts[(int)$q['id']] ?? [];
        $q['logs'] = $logs[(int)$q['id']] ?? [];
    }
    unset($q);
    return $questions;
}

function gpAssignQuestionsToOperation(PDO $pdo, array $ids, int $operatorId, string $comment, int $assignedBy, bool $validateQuestions = false): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    if (!$ids) {
        throw new RuntimeException('Debes seleccionar al menos una pregunta.');
    }
    $operator = gpFetchOperacionUserById($pdo, $operatorId);
    if (!$operator) {
        throw new RuntimeException('Debes seleccionar un operador válido.');
    }

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, estado FROM ceo_gp_preguntas WHERE id IN ($ph)");
    $stmt->execute($ids);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[(int)$row['id']] = (string)$row['estado'];
    }

    $pdo->beginTransaction();
    try {
        $stmtUpdate = $pdo->prepare('UPDATE ceo_gp_preguntas SET estado = "OPERACION", id_operador_asignado = :operador, fecha_asignacion_operacion = NOW(), asignado_operacion_por = :asignado_por, actualizado_por = :u, fecha_actualizacion = NOW() WHERE id = :id');
        $moved = 0;
        $warnings = [];
        foreach ($ids as $id) {
            $currentState = $rows[$id] ?? '';
            if ($currentState === '') {
                $warnings[] = 'Pregunta #' . $id . ' no encontrada.';
                continue;
            }
            if (!in_array($currentState, ['REVISION', 'OBSERVADA'], true)) {
                $warnings[] = 'Pregunta #' . $id . ' no esta en estado valido para Operacion.';
                continue;
            }
            if ($validateQuestions) {
                $errors = gpQuestionValidation($pdo, $id);
                if ($errors) {
                    $warnings[] = 'Pregunta #' . $id . ': ' . implode(' ', $errors);
                    continue;
                }
            }
            $stmtUpdate->execute([
                ':operador' => $operatorId,
                ':asignado_por' => $assignedBy > 0 ? $assignedBy : null,
                ':u' => $assignedBy > 0 ? $assignedBy : null,
                ':id' => $id,
            ]);
            gpAddRevisionLog($pdo, $id, $currentState, 'OPERACION', $comment, $assignedBy);
            $moved++;
        }
        $pdo->commit();
        return ['moved' => $moved, 'warnings' => $warnings, 'operator' => $operator];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function gpWorkflowQuestionIds(PDO $pdo, array $states, array $filters = []): array
{
    $where = gpWorkflowBuildWhere($states, $filters);
    $stmt = $pdo->prepare("SELECT q.id FROM ceo_gp_preguntas q WHERE {$where['where']} ORDER BY q.id ASC");
    $stmt->execute($where['params']);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

function gpWorkflowTransitionQuestions(PDO $pdo, array $ids, array $allowedStates, string $newState, string $comment, int $usuario, bool $validateQuestions = false): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    if (!$ids) {
        throw new RuntimeException('Debes seleccionar al menos una pregunta.');
    }

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, estado FROM ceo_gp_preguntas WHERE id IN ($ph)");
    $stmt->execute($ids);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[(int)$row['id']] = (string)$row['estado'];
    }

    $pdo->beginTransaction();
    try {
        $stmtUpdate = $pdo->prepare('UPDATE ceo_gp_preguntas SET estado = :estado, actualizado_por = :u, fecha_actualizacion = NOW() WHERE id = :id');
        $moved = 0;
        $warnings = [];

        foreach ($ids as $id) {
            $currentState = $rows[$id] ?? '';
            if ($currentState === '') {
                $warnings[] = 'Pregunta #' . $id . ' no encontrada.';
                continue;
            }
            if (!in_array($currentState, $allowedStates, true)) {
                $warnings[] = 'Pregunta #' . $id . ' no esta en estado valido.';
                continue;
            }
            if ($validateQuestions) {
                $errors = gpQuestionValidation($pdo, $id);
                if ($errors) {
                    $warnings[] = 'Pregunta #' . $id . ': ' . implode(' ', $errors);
                    continue;
                }
            }

            $stmtUpdate->execute([
                ':estado' => $newState,
                ':u' => $usuario > 0 ? $usuario : null,
                ':id' => $id,
            ]);
            gpAddRevisionLog($pdo, $id, $currentState, $newState, $comment, $usuario);
            $moved++;
        }

        $pdo->commit();
        return ['moved' => $moved, 'warnings' => $warnings];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function gpPublishQuestions(PDO $pdo, array $ids, int $usuario): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    if (!$ids) {
        throw new RuntimeException('Debes seleccionar al menos una pregunta para publicar.');
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmtQ = $pdo->prepare("SELECT * FROM ceo_gp_preguntas WHERE id IN ($placeholders) ORDER BY id ASC");
    $stmtQ->execute($ids);
    $questions = [];
    foreach ($stmtQ->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $questions[(int)$row['id']] = $row;
    }

    foreach ($ids as $id) {
        $question = $questions[$id] ?? null;
        if (!$question) {
            throw new RuntimeException('Pregunta #' . $id . ' no encontrada para publicar.');
        }
        if (($question['estado'] ?? '') !== 'APROBADA_OPERACION') {
            throw new RuntimeException('Pregunta #' . $id . ' no esta visada para publicar.');
        }
        $errors = gpPublicationValidation($pdo, $id);
        if ($errors) {
            throw new RuntimeException('Pregunta #' . $id . ': ' . implode(' ', $errors));
        }
    }

    $stmtPubCheck = $pdo->prepare("SELECT id_pregunta FROM ceo_gp_publicacion WHERE id_pregunta IN ($placeholders)");
    $stmtPubCheck->execute($ids);
    $alreadyPublished = array_map('intval', $stmtPubCheck->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($alreadyPublished) {
        throw new RuntimeException('Ya existen preguntas publicadas dentro del lote seleccionado.');
    }

    $stmtAlt = $pdo->prepare("SELECT * FROM ceo_gp_alternativas WHERE id_pregunta IN ($placeholders) AND estado = 'A' ORDER BY id_pregunta ASC, orden ASC, id ASC");
    $stmtAlt->execute($ids);
    $alternativesByQuestion = [];
    foreach ($stmtAlt->fetchAll(PDO::FETCH_ASSOC) as $alt) {
        $alternativesByQuestion[(int)$alt['id_pregunta']][] = $alt;
    }

    $stmtInsertHabQuestion = $pdo->prepare('INSERT INTO ceo_preguntas_servicios (pregunta, id_servicio, imagen, estado, id_agrupacion, retropos, retroneg, areacomp) VALUES (:pregunta, :id_servicio, "", "S", :id_agrupacion, :retropos, :retroneg, :areacomp)');
    $stmtInsertHabAlt = $pdo->prepare('INSERT INTO ceo_alternativas_preguntas (alternativa, correcta, estado, id_pregunta, imagen) VALUES (:alternativa, :correcta, "S", :id_pregunta, "")');
    $stmtInsertForQuestion = $pdo->prepare('INSERT INTO ceo_formacion_preguntas_servicios (pregunta, id_servicio, imagen, estado, id_agrupacion, retropos, retroneg, areacomp, peso, tipo_pregunta, obligatoria) VALUES (:pregunta, :id_servicio, "", "S", :id_agrupacion, :retropos, :retroneg, :areacomp, :peso, :tipo_pregunta, :obligatoria)');
    $stmtInsertForAlt = $pdo->prepare('INSERT INTO ceo_formacion_alternativas_preguntas (alternativa, correcta, estado, id_pregunta, imagen) VALUES (:alternativa, :correcta, "S", :id_pregunta, "")');
    $stmtGpPublication = $pdo->prepare('INSERT INTO ceo_gp_publicacion (id_pregunta, destino, tabla_pregunta, id_pregunta_oficial, publicado_por) VALUES (:id_pregunta, :destino, :tabla_pregunta, :id_pregunta_oficial, :publicado_por)');
    $stmtUpdate = $pdo->prepare('UPDATE ceo_gp_preguntas SET estado = "PUBLICADA", actualizado_por = :u, fecha_actualizacion = NOW() WHERE id = :id');

    $pdo->beginTransaction();
    try {
        $published = 0;
        foreach ($ids as $id) {
            $question = $questions[$id];
            $alternatives = $alternativesByQuestion[$id] ?? [];
            if (($question['destino'] ?? '') === 'FORMACION') {
                $stmtInsertForQuestion->execute([
                    ':pregunta' => (string)$question['pregunta'],
                    ':id_servicio' => (int)$question['id_servicio'],
                    ':id_agrupacion' => (int)$question['id_agrupacion'],
                    ':retropos' => (string)($question['retropos'] ?? ''),
                    ':retroneg' => (string)($question['retroneg'] ?? ''),
                    ':areacomp' => (int)$question['id_area'],
                    ':peso' => 1,
                    ':tipo_pregunta' => 'ALT',
                    ':obligatoria' => 0,
                ]);
                $officialQuestionId = (int)$pdo->lastInsertId();
                foreach ($alternatives as $alt) {
                    $stmtInsertForAlt->execute([
                        ':alternativa' => (string)$alt['alternativa'],
                        ':correcta' => (string)$alt['correcta'] === 'S' ? 'S' : 'N',
                        ':id_pregunta' => $officialQuestionId,
                    ]);
                }
                $tablaPregunta = 'ceo_formacion_preguntas_servicios';
            } else {
                $stmtInsertHabQuestion->execute([
                    ':pregunta' => (string)$question['pregunta'],
                    ':id_servicio' => (int)$question['id_servicio'],
                    ':id_agrupacion' => (int)$question['id_agrupacion'],
                    ':retropos' => (string)($question['retropos'] ?? ''),
                    ':retroneg' => (string)($question['retroneg'] ?? ''),
                    ':areacomp' => (int)$question['id_area'],
                ]);
                $officialQuestionId = (int)$pdo->lastInsertId();
                foreach ($alternatives as $alt) {
                    $stmtInsertHabAlt->execute([
                        ':alternativa' => (string)$alt['alternativa'],
                        ':correcta' => (string)$alt['correcta'] === 'S' ? 'S' : 'N',
                        ':id_pregunta' => $officialQuestionId,
                    ]);
                }
                $tablaPregunta = 'ceo_preguntas_servicios';
            }

            $stmtGpPublication->execute([
                ':id_pregunta' => $id,
                ':destino' => (string)$question['destino'],
                ':tabla_pregunta' => $tablaPregunta,
                ':id_pregunta_oficial' => $officialQuestionId,
                ':publicado_por' => $usuario > 0 ? $usuario : null,
            ]);
            $stmtUpdate->execute([':u' => $usuario > 0 ? $usuario : null, ':id' => $id]);
            gpAddRevisionLog($pdo, $id, 'APROBADA_OPERACION', 'PUBLICADA', 'Pregunta publicada al banco oficial.', $usuario);
            $published++;
        }

        $pdo->commit();
        return ['published' => $published];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
