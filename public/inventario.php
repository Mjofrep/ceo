<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../src/Csrf.php';

function invNormalizeRole(string $value): string
{
    $normalized = strtr($value, [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'Ñ' => 'N', 'ñ' => 'n',
    ]);

    $normalized = preg_replace('/\s+/', ' ', trim($normalized));

    return strtolower((string)$normalized);
}

function invHasAccess(array $auth): bool
{
    $role = invNormalizeRole((string)($auth['rol'] ?? ''));
    $idRol = (int)($auth['id_rol'] ?? 0);

    return $idRol === 1 || in_array($role, ['administrador', 'registro asistencia'], true);
}

function invTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table
        LIMIT 1
    ");
    $stmt->execute(['table' => $table]);

    return (bool)$stmt->fetchColumn();
}

function invMissingTables(PDO $pdo, array $tables): array
{
    $missing = [];
    foreach ($tables as $table) {
        if (!invTableExists($pdo, $table)) {
            $missing[] = $table;
        }
    }

    return $missing;
}

function invGenerateInternalCode(string $name): string
{
    $base = strtoupper((string)preg_replace('/[^A-Z0-9]+/', '-', invNormalizeRole($name)));
    $base = trim($base, '-');
    $base = substr($base, 0, 16);

    if ($base === '') {
        $base = 'ITEM';
    }

    return 'INV-' . $base . '-' . date('ymdHis');
}

function invMovementDelta(string $type, float $qty): float
{
    if (in_array($type, ['INICIAL', 'ENTRADA', 'DEVOLUCION'], true)) {
        return $qty;
    }

    if (in_array($type, ['SALIDA', 'PRESTAMO', 'BAJA'], true)) {
        return $qty * -1;
    }

    return $qty;
}

function invGetCurrentStock(PDO $pdo, int $idProducto): float
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(
            CASE
                WHEN tipo_movimiento IN ('INICIAL', 'ENTRADA', 'DEVOLUCION') THEN cantidad
                WHEN tipo_movimiento IN ('SALIDA', 'PRESTAMO', 'BAJA') THEN -cantidad
                ELSE cantidad
            END
        ), 0)
        FROM ceo_inv_movimiento
        WHERE id_producto = :id_producto
    ");
    $stmt->execute(['id_producto' => $idProducto]);

    return (float)$stmt->fetchColumn();
}

function invFormatQty(float $value): string
{
    $rounded = round($value, 2);
    $decimals = abs($rounded - round($rounded)) < 0.001 ? 0 : 2;

    return number_format($rounded, $decimals, ',', '.');
}

function invStockStatus(array $product): array
{
    $controla = (int)($product['controla_stock'] ?? 0) === 1;
    $stock = (float)($product['stock_actual'] ?? 0);
    $minimo = (float)($product['stock_minimo'] ?? 0);

    if (!$controla) {
        return ['badge bg-secondary-subtle text-secondary-emphasis', 'Catalogo'];
    }

    if ($stock <= 0) {
        return ['badge bg-danger-subtle text-danger-emphasis', 'Sin stock'];
    }

    if ($stock <= $minimo) {
        return ['badge bg-warning-subtle text-warning-emphasis', 'Bajo minimo'];
    }

    return ['badge bg-success-subtle text-success-emphasis', 'Disponible'];
}

$auth = $_SESSION['auth'] ?? [];
if (!invHasAccess($auth)) {
    http_response_code(403);
    ?>
    <!doctype html>
    <html lang="es">
    <head>
      <meta charset="utf-8">
      <title><?= APP_NAME ?> | Inventario</title>
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
      <main class="container py-5">
        <div class="alert alert-danger shadow-sm">
          No tienes permisos para acceder a Inventario CEO.
        </div>
        <a class="btn btn-outline-primary" href="<?= APP_BASE ?>/public/general.php">Volver</a>
      </main>
    </body>
    </html>
    <?php
    exit;
}

$pdo = null;
$dbError = '';
try {
    $pdo = db();
} catch (Throwable $e) {
    $dbError = 'No fue posible conectar con la base de datos. Revisa MAMP/MySQL antes de usar Inventario CEO.';
}

$requiredTables = [
    'ceo_inv_categoria',
    'ceo_inv_tipo_control',
    'ceo_inv_producto',
    'ceo_inv_movimiento',
];
$missingTables = $pdo ? invMissingTables($pdo, $requiredTables) : $requiredTables;

$msg = '';
$msgType = 'info';
$csrf = Csrf::token();

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'POST') {
    if (!Csrf::validate($_POST['csrf'] ?? null)) {
        $msg = 'La sesion del formulario expiro. Recarga la pagina e intenta nuevamente.';
        $msgType = 'danger';
    } elseif ($pdo === null) {
        $msg = $dbError;
        $msgType = 'danger';
    } elseif ($missingTables !== []) {
        $msg = 'Primero debes ejecutar el script SQL de Inventario Fase 1 antes de registrar datos.';
        $msgType = 'warning';
    } else {
        $accion = (string)($_POST['accion'] ?? '');
        $usuarioId = (int)($auth['id'] ?? 0);

        try {
            if ($accion === 'crear_producto') {
                $nombre = trim((string)($_POST['nombre'] ?? ''));
                $codigoInterno = trim((string)($_POST['codigo_interno'] ?? ''));
                $descripcion = trim((string)($_POST['descripcion'] ?? ''));
                $idCategoria = (int)($_POST['id_categoria'] ?? 0);
                $idTipoControl = (int)($_POST['id_tipo_control'] ?? 0);
                $unidadMedida = strtoupper(trim((string)($_POST['unidad_medida'] ?? 'UN')));
                $stockMinimo = round((float)($_POST['stock_minimo'] ?? 0), 2);
                $stockInicial = round((float)($_POST['stock_inicial'] ?? 0), 2);
                $usaSerie = isset($_POST['usa_serie']) ? 1 : 0;
                $requiereResponsable = isset($_POST['requiere_responsable_salida']) ? 1 : 0;
                $controlaStock = isset($_POST['controla_stock']) ? 1 : 0;
                $activo = (($_POST['activo'] ?? 'A') === 'D') ? 'D' : 'A';

                if ($nombre === '' || $idCategoria <= 0 || $idTipoControl <= 0) {
                    throw new RuntimeException('Completa nombre, categoria y tipo de control para crear el producto.');
                }

                if ($stockMinimo < 0 || $stockInicial < 0) {
                    throw new RuntimeException('Los valores de stock no pueden ser negativos.');
                }

                if ($codigoInterno === '') {
                    $codigoInterno = invGenerateInternalCode($nombre);
                }

                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    INSERT INTO ceo_inv_producto (
                        codigo_interno,
                        nombre,
                        descripcion,
                        id_categoria,
                        id_tipo_control,
                        unidad_medida,
                        stock_minimo,
                        usa_serie,
                        requiere_responsable_salida,
                        controla_stock,
                        activo,
                        creado_por,
                        creado_en,
                        actualizado_por,
                        actualizado_en
                    ) VALUES (
                        :codigo_interno,
                        :nombre,
                        :descripcion,
                        :id_categoria,
                        :id_tipo_control,
                        :unidad_medida,
                        :stock_minimo,
                        :usa_serie,
                        :requiere_responsable_salida,
                        :controla_stock,
                        :activo,
                        :creado_por,
                        NOW(),
                        :actualizado_por,
                        NOW()
                    )
                ");
                $stmt->execute([
                    'codigo_interno' => $codigoInterno,
                    'nombre' => $nombre,
                    'descripcion' => $descripcion !== '' ? $descripcion : null,
                    'id_categoria' => $idCategoria,
                    'id_tipo_control' => $idTipoControl,
                    'unidad_medida' => $unidadMedida !== '' ? $unidadMedida : 'UN',
                    'stock_minimo' => $stockMinimo,
                    'usa_serie' => $usaSerie,
                    'requiere_responsable_salida' => $requiereResponsable,
                    'controla_stock' => $controlaStock,
                    'activo' => $activo,
                    'creado_por' => $usuarioId > 0 ? $usuarioId : null,
                    'actualizado_por' => $usuarioId > 0 ? $usuarioId : null,
                ]);

                $idProducto = (int)$pdo->lastInsertId();

                if ($stockInicial > 0) {
                    $stmtMov = $pdo->prepare("
                        INSERT INTO ceo_inv_movimiento (
                            tipo_movimiento,
                            id_producto,
                            cantidad,
                            fecha_movimiento,
                            motivo,
                            estado_resultante,
                            observacion,
                            registrado_por,
                            registrado_en
                        ) VALUES (
                            'INICIAL',
                            :id_producto,
                            :cantidad,
                            NOW(),
                            :motivo,
                            :estado_resultante,
                            :observacion,
                            :registrado_por,
                            NOW()
                        )
                    ");
                    $stmtMov->execute([
                        'id_producto' => $idProducto,
                        'cantidad' => $stockInicial,
                        'motivo' => 'Carga inicial de producto',
                        'estado_resultante' => 'DISPONIBLE',
                        'observacion' => 'Movimiento inicial generado desde inventario.php',
                        'registrado_por' => $usuarioId > 0 ? $usuarioId : null,
                    ]);
                }

                $pdo->commit();
                $msg = 'Producto creado correctamente.';
                $msgType = 'success';
            } elseif ($accion === 'registrar_movimiento') {
                $idProducto = (int)($_POST['id_producto'] ?? 0);
                $tipoMovimiento = strtoupper(trim((string)($_POST['tipo_movimiento'] ?? '')));
                $cantidad = round((float)($_POST['cantidad'] ?? 0), 2);
                $entregadoA = trim((string)($_POST['entregado_a'] ?? ''));
                $rutEntregadoA = trim((string)($_POST['rut_entregado_a'] ?? ''));
                $areaDestino = trim((string)($_POST['area_destino'] ?? ''));
                $motivo = trim((string)($_POST['motivo'] ?? ''));
                $documentoReferencia = trim((string)($_POST['documento_referencia'] ?? ''));
                $observacion = trim((string)($_POST['observacion'] ?? ''));

                if ($idProducto <= 0 || !in_array($tipoMovimiento, ['ENTRADA', 'SALIDA'], true) || $cantidad <= 0) {
                    throw new RuntimeException('Selecciona producto, tipo de movimiento y una cantidad valida.');
                }

                $stmtProducto = $pdo->prepare("
                    SELECT p.id, p.nombre, p.controla_stock, p.requiere_responsable_salida, tc.codigo AS tipo_codigo
                    FROM ceo_inv_producto p
                    INNER JOIN ceo_inv_tipo_control tc ON tc.id = p.id_tipo_control
                    WHERE p.id = :id
                    LIMIT 1
                ");
                $stmtProducto->execute(['id' => $idProducto]);
                $producto = $stmtProducto->fetch(PDO::FETCH_ASSOC);

                if (!$producto) {
                    throw new RuntimeException('El producto seleccionado ya no existe.');
                }

                $stockActual = invGetCurrentStock($pdo, $idProducto);
                $delta = invMovementDelta($tipoMovimiento, $cantidad);
                $stockResultante = $stockActual + $delta;

                if ((int)$producto['controla_stock'] === 1 && $tipoMovimiento === 'SALIDA' && $stockResultante < 0) {
                    throw new RuntimeException('La salida excede el stock disponible para este producto.');
                }

                if ((int)$producto['requiere_responsable_salida'] === 1 && $tipoMovimiento === 'SALIDA' && $entregadoA === '') {
                    throw new RuntimeException('Este producto requiere indicar a quien se entrega.');
                }

                $stmtMov = $pdo->prepare("
                    INSERT INTO ceo_inv_movimiento (
                        tipo_movimiento,
                        id_producto,
                        cantidad,
                        fecha_movimiento,
                        entregado_a,
                        rut_entregado_a,
                        area_destino,
                        motivo,
                        documento_referencia,
                        estado_resultante,
                        observacion,
                        registrado_por,
                        registrado_en
                    ) VALUES (
                        :tipo_movimiento,
                        :id_producto,
                        :cantidad,
                        NOW(),
                        :entregado_a,
                        :rut_entregado_a,
                        :area_destino,
                        :motivo,
                        :documento_referencia,
                        :estado_resultante,
                        :observacion,
                        :registrado_por,
                        NOW()
                    )
                ");
                $stmtMov->execute([
                    'tipo_movimiento' => $tipoMovimiento,
                    'id_producto' => $idProducto,
                    'cantidad' => $cantidad,
                    'entregado_a' => $entregadoA !== '' ? $entregadoA : null,
                    'rut_entregado_a' => $rutEntregadoA !== '' ? $rutEntregadoA : null,
                    'area_destino' => $areaDestino !== '' ? $areaDestino : null,
                    'motivo' => $motivo !== '' ? $motivo : null,
                    'documento_referencia' => $documentoReferencia !== '' ? $documentoReferencia : null,
                    'estado_resultante' => $stockResultante <= 0 ? 'SIN_STOCK' : 'DISPONIBLE',
                    'observacion' => $observacion !== '' ? $observacion : null,
                    'registrado_por' => $usuarioId > 0 ? $usuarioId : null,
                ]);

                $msg = sprintf(
                    'Movimiento %s registrado. Stock resultante: %s.',
                    strtolower($tipoMovimiento),
                    invFormatQty($stockResultante)
                );
                $msgType = 'success';
            }
        } catch (Throwable $e) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $msg = $e->getMessage();
            $msgType = 'danger';
        }

        $missingTables = invMissingTables($pdo, $requiredTables);
    }
}

$categorias = [];
$tiposControl = [];
$productos = [];
$productosActivos = [];
$movimientosRecientes = [];
$stats = [
    'productos' => 0,
    'stock_total' => 0.0,
    'bajo_minimo' => 0,
    'movimientos_hoy' => 0,
];

$filtroCategoria = (int)($_GET['categoria'] ?? 0);
$filtroTipo = (int)($_GET['tipo_control'] ?? 0);
$filtroEstado = (string)($_GET['estado'] ?? 'A');
$filtroStock = (string)($_GET['stock'] ?? '');
$filtroTexto = trim((string)($_GET['q'] ?? ''));

if ($pdo !== null && $missingTables === []) {
    $categorias = $pdo->query("
        SELECT id, nombre
        FROM ceo_inv_categoria
        WHERE estado = 'A'
        ORDER BY nombre
    ")->fetchAll(PDO::FETCH_ASSOC);

    $tiposControl = $pdo->query("
        SELECT id, codigo, nombre
        FROM ceo_inv_tipo_control
        ORDER BY nombre
    ")->fetchAll(PDO::FETCH_ASSOC);

    $productosActivos = $pdo->query("
        SELECT id, nombre
        FROM ceo_inv_producto
        WHERE activo = 'A'
        ORDER BY nombre
    ")->fetchAll(PDO::FETCH_ASSOC);

    $where = [];
    $params = [];

    if ($filtroCategoria > 0) {
        $where[] = 'p.id_categoria = :categoria';
        $params['categoria'] = $filtroCategoria;
    }

    if ($filtroTipo > 0) {
        $where[] = 'p.id_tipo_control = :tipo_control';
        $params['tipo_control'] = $filtroTipo;
    }

    if (in_array($filtroEstado, ['A', 'D'], true)) {
        $where[] = 'p.activo = :estado';
        $params['estado'] = $filtroEstado;
    } else {
        $filtroEstado = '';
    }

    if ($filtroTexto !== '') {
        $where[] = '(p.nombre LIKE :texto OR p.codigo_interno LIKE :texto)';
        $params['texto'] = '%' . $filtroTexto . '%';
    }

    $sqlProductos = "
        SELECT
            p.id,
            p.codigo_interno,
            p.nombre,
            p.descripcion,
            p.unidad_medida,
            p.stock_minimo,
            p.usa_serie,
            p.requiere_responsable_salida,
            p.controla_stock,
            p.activo,
            c.nombre AS categoria_nombre,
            tc.codigo AS tipo_codigo,
            tc.nombre AS tipo_nombre,
            COALESCE(stock.stock_actual, 0) AS stock_actual
        FROM ceo_inv_producto p
        INNER JOIN ceo_inv_categoria c ON c.id = p.id_categoria
        INNER JOIN ceo_inv_tipo_control tc ON tc.id = p.id_tipo_control
        LEFT JOIN (
            SELECT
                id_producto,
                SUM(
                    CASE
                        WHEN tipo_movimiento IN ('INICIAL', 'ENTRADA', 'DEVOLUCION') THEN cantidad
                        WHEN tipo_movimiento IN ('SALIDA', 'PRESTAMO', 'BAJA') THEN -cantidad
                        ELSE cantidad
                    END
                ) AS stock_actual
            FROM ceo_inv_movimiento
            GROUP BY id_producto
        ) stock ON stock.id_producto = p.id
    ";

    if ($where !== []) {
        $sqlProductos .= ' WHERE ' . implode(' AND ', $where);
    }

    if ($filtroStock === 'bajo') {
        $sqlProductos .= ($where === [] ? ' WHERE ' : ' AND ') . 'p.controla_stock = 1 AND COALESCE(stock.stock_actual, 0) <= p.stock_minimo';
    } elseif ($filtroStock === 'sin') {
        $sqlProductos .= ($where === [] ? ' WHERE ' : ' AND ') . 'p.controla_stock = 1 AND COALESCE(stock.stock_actual, 0) <= 0';
    }

    $sqlProductos .= ' ORDER BY p.activo DESC, p.nombre ASC';

    $stmtProductos = $pdo->prepare($sqlProductos);
    $stmtProductos->execute($params);
    $productos = $stmtProductos->fetchAll(PDO::FETCH_ASSOC);

    $stats['productos'] = (int)$pdo->query("SELECT COUNT(*) FROM ceo_inv_producto WHERE activo = 'A'")->fetchColumn();
    $stats['stock_total'] = (float)$pdo->query("
        SELECT COALESCE(SUM(stock_actual), 0)
        FROM (
            SELECT
                p.id,
                COALESCE(SUM(
                    CASE
                        WHEN m.tipo_movimiento IN ('INICIAL', 'ENTRADA', 'DEVOLUCION') THEN m.cantidad
                        WHEN m.tipo_movimiento IN ('SALIDA', 'PRESTAMO', 'BAJA') THEN -m.cantidad
                        ELSE m.cantidad
                    END
                ), 0) AS stock_actual
            FROM ceo_inv_producto p
            LEFT JOIN ceo_inv_movimiento m ON m.id_producto = p.id
            WHERE p.activo = 'A'
            GROUP BY p.id
        ) resumen
    ")->fetchColumn();
    $stats['bajo_minimo'] = (int)$pdo->query("
        SELECT COUNT(*)
        FROM (
            SELECT
                p.id,
                p.stock_minimo,
                p.controla_stock,
                COALESCE(SUM(
                    CASE
                        WHEN m.tipo_movimiento IN ('INICIAL', 'ENTRADA', 'DEVOLUCION') THEN m.cantidad
                        WHEN m.tipo_movimiento IN ('SALIDA', 'PRESTAMO', 'BAJA') THEN -m.cantidad
                        ELSE m.cantidad
                    END
                ), 0) AS stock_actual
            FROM ceo_inv_producto p
            LEFT JOIN ceo_inv_movimiento m ON m.id_producto = p.id
            WHERE p.activo = 'A'
            GROUP BY p.id, p.stock_minimo, p.controla_stock
        ) resumen
        WHERE controla_stock = 1
          AND stock_actual <= stock_minimo
    ")->fetchColumn();
    $stats['movimientos_hoy'] = (int)$pdo->query("
        SELECT COUNT(*)
        FROM ceo_inv_movimiento
        WHERE DATE(fecha_movimiento) = CURDATE()
    ")->fetchColumn();

    $movimientosRecientes = $pdo->query("
        SELECT
            m.tipo_movimiento,
            m.cantidad,
            m.fecha_movimiento,
            m.entregado_a,
            m.area_destino,
            m.motivo,
            p.nombre AS producto_nombre,
            p.unidad_medida,
            u.nombres,
            u.apellidos
        FROM ceo_inv_movimiento m
        INNER JOIN ceo_inv_producto p ON p.id = m.id_producto
        LEFT JOIN ceo_usuarios u ON u.id = m.registrado_por
        ORDER BY m.fecha_movimiento DESC, m.id DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title><?= APP_NAME ?> | Inventario CEO</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <style>
    :root {
      --inv-ink: #14213d;
      --inv-blue: #1d4ed8;
      --inv-cyan: #0f766e;
      --inv-sand: #f7f5ef;
      --inv-line: rgba(20, 33, 61, 0.08);
    }

    body {
      background:
        radial-gradient(circle at top left, rgba(29, 78, 216, 0.08), transparent 30%),
        radial-gradient(circle at bottom right, rgba(15, 118, 110, 0.08), transparent 28%),
        #f8fafc;
      color: var(--inv-ink);
      font-family: "Segoe UI", Roboto, sans-serif;
    }

    .topbar {
      background: rgba(255,255,255,0.92);
      border-bottom: 1px solid rgba(29, 78, 216, 0.12);
      backdrop-filter: blur(10px);
      box-shadow: 0 12px 26px rgba(15, 23, 42, 0.04);
    }

    .hero-card,
    .panel-card,
    .metric-card {
      border: 1px solid var(--inv-line);
      border-radius: 1.35rem;
      background: rgba(255,255,255,0.94);
      box-shadow: 0 18px 40px rgba(15, 23, 42, 0.06);
    }

    .hero-card {
      overflow: hidden;
      position: relative;
      background:
        linear-gradient(135deg, rgba(255,255,255,0.96), rgba(241,245,249,0.93)),
        #fff;
    }

    .hero-card::before,
    .hero-card::after {
      content: "";
      position: absolute;
      border-radius: 999px;
      filter: blur(12px);
      opacity: 0.7;
    }

    .hero-card::before {
      width: 240px;
      height: 240px;
      right: -90px;
      top: -100px;
      background: rgba(29, 78, 216, 0.12);
    }

    .hero-card::after {
      width: 180px;
      height: 180px;
      left: -60px;
      bottom: -90px;
      background: rgba(15, 118, 110, 0.12);
    }

    .hero-kicker {
      display: inline-flex;
      align-items: center;
      gap: 0.45rem;
      padding: 0.35rem 0.8rem;
      border-radius: 999px;
      background: rgba(29, 78, 216, 0.08);
      color: var(--inv-blue);
      font-size: 0.82rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }

    .hero-title {
      font-size: clamp(2rem, 4vw, 3.1rem);
      line-height: 0.95;
      font-weight: 800;
      max-width: 10ch;
      margin: 0.9rem 0 0.85rem;
    }

    .hero-copy {
      max-width: 58ch;
      color: #475569;
      margin-bottom: 1.15rem;
    }

    .hero-chip {
      display: inline-flex;
      align-items: center;
      border-radius: 999px;
      padding: 0.55rem 0.9rem;
      margin: 0 0.55rem 0.55rem 0;
      background: #fff;
      border: 1px solid rgba(29, 78, 216, 0.12);
      box-shadow: 0 10px 20px rgba(15, 23, 42, 0.04);
      color: #334155;
      font-size: 0.92rem;
    }

    .hero-aside {
      position: relative;
      z-index: 1;
      padding: 1.25rem;
      border-radius: 1.2rem;
      background:
        radial-gradient(circle at top left, rgba(29, 78, 216, 0.14), transparent 34%),
        linear-gradient(155deg, rgba(20,33,61,0.98), rgba(29,78,216,0.88));
      color: #fff;
      min-height: 100%;
    }

    .hero-grid {
      display: grid;
      gap: 1rem;
      grid-template-columns: minmax(0, 1.2fr) minmax(280px, 0.8fr);
      align-items: stretch;
    }

    .metric-card {
      padding: 1.1rem 1.15rem;
      height: 100%;
    }

    .metric-label {
      color: #64748b;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      font-size: 0.75rem;
      font-weight: 700;
    }

    .metric-value {
      font-size: 2rem;
      line-height: 1;
      font-weight: 800;
      margin-top: 0.6rem;
      color: var(--inv-ink);
    }

    .metric-note {
      color: #475569;
      margin-top: 0.4rem;
      font-size: 0.92rem;
    }

    .panel-card {
      padding: 1.35rem;
      margin-bottom: 1.25rem;
    }

    .panel-card h2,
    .panel-card h3 {
      font-size: 1.05rem;
      font-weight: 700;
      margin-bottom: 1rem;
    }

    .table thead th {
      white-space: nowrap;
    }

    .stock-number {
      font-weight: 700;
      color: var(--inv-ink);
    }

    .product-code {
      font-size: 0.82rem;
      color: #64748b;
    }

    .tiny-note {
      font-size: 0.88rem;
      color: #64748b;
    }

    .inventory-table td {
      vertical-align: middle;
    }

    footer {
      text-align: center;
      font-size: 0.9rem;
      color: #64748b;
      padding: 1.5rem 0 2rem;
    }

    @media (max-width: 991.98px) {
      .hero-grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
<header class="topbar py-3 mb-4">
  <div class="container d-flex align-items-center justify-content-between gap-3">
    <div class="d-flex align-items-center gap-2">
      <img src="<?= APP_LOGO ?>" alt="Logo <?= APP_NAME ?>" style="height:60px;">
      <div>
        <div class="h4 mb-0 fw-bold text-primary"><?= APP_NAME ?></div>
        <small class="text-secondary"><?= APP_SUBTITLE ?></small>
      </div>
    </div>
    <a href="<?= APP_BASE ?>/public/general.php" class="btn btn-outline-primary btn-sm">Volver</a>
  </div>
</header>

<main class="container pb-4">
  <?php if ($msg !== ''): ?>
    <div class="alert alert-<?= esc($msgType) ?> shadow-sm"><?= esc($msg) ?></div>
  <?php endif; ?>

  <section class="hero-card p-4 p-lg-5 mb-4">
    <div class="hero-grid">
      <div class="position-relative" style="z-index:1;">
        <span class="hero-kicker">Fase 1 inventario</span>
        <h1 class="hero-title">Base operativa para stock y movimientos</h1>
        <p class="hero-copy">Primera pantalla del modulo Inventario CEO para manejar catalogo de productos, movimiento inicial, entradas y salidas simples sin salir del patron actual de CEONext.</p>
        <div>
          <span class="hero-chip">Productos maestros</span>
          <span class="hero-chip">Entradas y salidas</span>
          <span class="hero-chip">Alertas de stock minimo</span>
        </div>
      </div>
      <aside class="hero-aside">
        <div class="small text-uppercase fw-semibold opacity-75 mb-2">Acceso y alcance</div>
        <h2 class="h5 fw-bold mb-3">Administrador y Registro asistencia</h2>
        <p class="mb-3 opacity-75">La pantalla deja lista la base operativa del inventario. Prestamos, devoluciones y serializacion quedan preparados en el modelo para las fases siguientes.</p>
        <div class="tiny-note text-white-50">Script de instalacion: <code class="text-white">docs/inventario_fase1.sql</code></div>
      </aside>
    </div>
  </section>

  <?php if ($dbError !== ''): ?>
    <div class="panel-card">
      <div class="alert alert-danger mb-0"><?= esc($dbError) ?></div>
    </div>
  <?php endif; ?>

  <?php if ($dbError === '' && $missingTables !== []): ?>
    <div class="panel-card">
      <h2>Configuracion pendiente</h2>
      <p class="mb-3">La pantalla ya esta disponible, pero faltan tablas base para operar el modulo. Ejecuta el script <code>docs/inventario_fase1.sql</code> en MySQL y luego recarga.</p>
      <div class="alert alert-warning mb-0">
        Tablas faltantes: <?= esc(implode(', ', $missingTables)) ?>
      </div>
    </div>
  <?php endif; ?>

  <section class="row g-3 mb-4">
    <div class="col-md-6 col-xl-3">
      <div class="metric-card">
        <div class="metric-label">Productos activos</div>
        <div class="metric-value"><?= invFormatQty((float)$stats['productos']) ?></div>
        <div class="metric-note">Catalogo actualmente habilitado.</div>
      </div>
    </div>
    <div class="col-md-6 col-xl-3">
      <div class="metric-card">
        <div class="metric-label">Unidades en stock</div>
        <div class="metric-value"><?= invFormatQty($stats['stock_total']) ?></div>
        <div class="metric-note">Saldo calculado desde movimientos.</div>
      </div>
    </div>
    <div class="col-md-6 col-xl-3">
      <div class="metric-card">
        <div class="metric-label">Bajo minimo</div>
        <div class="metric-value"><?= invFormatQty((float)$stats['bajo_minimo']) ?></div>
        <div class="metric-note">Productos que requieren reposicion.</div>
      </div>
    </div>
    <div class="col-md-6 col-xl-3">
      <div class="metric-card">
        <div class="metric-label">Movimientos hoy</div>
        <div class="metric-value"><?= invFormatQty((float)$stats['movimientos_hoy']) ?></div>
        <div class="metric-note">Actividad registrada en la jornada.</div>
      </div>
    </div>
  </section>

  <?php if ($dbError === '' && $missingTables === []): ?>
    <section class="row g-4">
      <div class="col-lg-6">
        <div class="panel-card h-100">
          <h2>Alta rapida de producto</h2>
          <form method="post" class="row g-3">
            <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
            <input type="hidden" name="accion" value="crear_producto">

            <div class="col-md-5">
              <label class="form-label">Codigo interno</label>
              <input type="text" name="codigo_interno" class="form-control" placeholder="Se genera si queda vacio">
            </div>
            <div class="col-md-7">
              <label class="form-label">Nombre</label>
              <input type="text" name="nombre" class="form-control" required>
            </div>
            <div class="col-12">
              <label class="form-label">Descripcion</label>
              <textarea name="descripcion" class="form-control" rows="2" placeholder="Detalle opcional del producto"></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label">Categoria</label>
              <select name="id_categoria" class="form-select" required>
                <option value="">Seleccione...</option>
                <?php foreach ($categorias as $categoria): ?>
                  <option value="<?= (int)$categoria['id'] ?>"><?= esc($categoria['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Tipo de control</label>
              <select name="id_tipo_control" id="id_tipo_control" class="form-select" required>
                <option value="">Seleccione...</option>
                <?php foreach ($tiposControl as $tipo): ?>
                  <option value="<?= (int)$tipo['id'] ?>" data-codigo="<?= esc($tipo['codigo']) ?>"><?= esc($tipo['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Unidad</label>
              <input type="text" name="unidad_medida" class="form-control" value="UN" maxlength="30">
            </div>
            <div class="col-md-4">
              <label class="form-label">Stock minimo</label>
              <input type="number" name="stock_minimo" class="form-control" min="0" step="0.01" value="0">
            </div>
            <div class="col-md-4">
              <label class="form-label">Stock inicial</label>
              <input type="number" name="stock_inicial" class="form-control" min="0" step="0.01" value="0">
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="controla_stock" id="controla_stock" checked>
                <label class="form-check-label" for="controla_stock">Controla stock desde movimientos</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="requiere_responsable_salida" id="requiere_responsable_salida">
                <label class="form-check-label" for="requiere_responsable_salida">Exige responsable en salidas</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="usa_serie" id="usa_serie">
                <label class="form-check-label" for="usa_serie">Preparado para serie o trazabilidad unitaria</label>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Estado</label>
              <select name="activo" class="form-select">
                <option value="A">Activo</option>
                <option value="D">Inactivo</option>
              </select>
            </div>
            <div class="col-md-8 d-flex align-items-end justify-content-end">
              <button type="submit" class="btn btn-primary">Guardar producto</button>
            </div>
          </form>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="panel-card h-100">
          <h2>Entrada o salida simple</h2>
          <form method="post" class="row g-3">
            <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
            <input type="hidden" name="accion" value="registrar_movimiento">

            <div class="col-md-7">
              <label class="form-label">Producto</label>
              <select name="id_producto" class="form-select" required>
                <option value="">Seleccione...</option>
                <?php foreach ($productosActivos as $producto): ?>
                  <option value="<?= (int)$producto['id'] ?>"><?= esc($producto['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-5">
              <label class="form-label">Movimiento</label>
              <select name="tipo_movimiento" class="form-select" required>
                <option value="ENTRADA">Entrada</option>
                <option value="SALIDA">Salida</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Cantidad</label>
              <input type="number" name="cantidad" class="form-control" min="0.01" step="0.01" required>
            </div>
            <div class="col-md-8">
              <label class="form-label">Entregado a</label>
              <input type="text" name="entregado_a" class="form-control" placeholder="Persona, cuadrilla o area">
            </div>
            <div class="col-md-4">
              <label class="form-label">RUT</label>
              <input type="text" name="rut_entregado_a" class="form-control" placeholder="Opcional">
            </div>
            <div class="col-md-8">
              <label class="form-label">Area destino</label>
              <input type="text" name="area_destino" class="form-control" placeholder="Ej: Sala, terreno, oficina">
            </div>
            <div class="col-md-6">
              <label class="form-label">Motivo</label>
              <input type="text" name="motivo" class="form-control" placeholder="Compra, consumo, reposicion...">
            </div>
            <div class="col-md-6">
              <label class="form-label">Documento referencia</label>
              <input type="text" name="documento_referencia" class="form-control" placeholder="Factura, guia, OT...">
            </div>
            <div class="col-12">
              <label class="form-label">Observacion</label>
              <textarea name="observacion" class="form-control" rows="2" placeholder="Detalle operativo opcional"></textarea>
            </div>
            <div class="col-12 d-flex justify-content-end">
              <button type="submit" class="btn btn-success">Registrar movimiento</button>
            </div>
          </form>
        </div>
      </div>
    </section>

    <section class="panel-card mt-4">
      <h2>Consulta de stock actual</h2>
      <form method="get" class="row g-3 align-items-end">
        <div class="col-md-3">
          <label class="form-label">Buscar</label>
          <input type="text" name="q" value="<?= esc($filtroTexto) ?>" class="form-control" placeholder="Producto o codigo">
        </div>
        <div class="col-md-2">
          <label class="form-label">Categoria</label>
          <select name="categoria" class="form-select">
            <option value="0">Todas</option>
            <?php foreach ($categorias as $categoria): ?>
              <option value="<?= (int)$categoria['id'] ?>"<?= $filtroCategoria === (int)$categoria['id'] ? ' selected' : '' ?>><?= esc($categoria['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Tipo</label>
          <select name="tipo_control" class="form-select">
            <option value="0">Todos</option>
            <?php foreach ($tiposControl as $tipo): ?>
              <option value="<?= (int)$tipo['id'] ?>"<?= $filtroTipo === (int)$tipo['id'] ? ' selected' : '' ?>><?= esc($tipo['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Estado</label>
          <select name="estado" class="form-select">
            <option value="">Todos</option>
            <option value="A"<?= $filtroEstado === 'A' ? ' selected' : '' ?>>Activos</option>
            <option value="D"<?= $filtroEstado === 'D' ? ' selected' : '' ?>>Inactivos</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Stock</label>
          <select name="stock" class="form-select">
            <option value="">Todos</option>
            <option value="bajo"<?= $filtroStock === 'bajo' ? ' selected' : '' ?>>Bajo minimo</option>
            <option value="sin"<?= $filtroStock === 'sin' ? ' selected' : '' ?>>Sin stock</option>
          </select>
        </div>
        <div class="col-md-1 d-grid">
          <button type="submit" class="btn btn-outline-primary">Filtrar</button>
        </div>
      </form>
    </section>

    <section class="panel-card">
      <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
        <h2 class="mb-0">Productos registrados</h2>
        <div class="tiny-note"><?= count($productos) ?> resultado(s)</div>
      </div>
      <div class="table-responsive">
        <table class="table table-hover inventory-table align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Producto</th>
              <th>Categoria</th>
              <th>Tipo control</th>
              <th>Stock actual</th>
              <th>Minimo</th>
              <th>Estado</th>
              <th>Bandera</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($productos === []): ?>
            <tr>
              <td colspan="7" class="text-center text-secondary py-4">Aun no hay productos cargados para este filtro.</td>
            </tr>
          <?php endif; ?>
          <?php foreach ($productos as $producto): ?>
            <?php [$badgeClass, $badgeLabel] = invStockStatus($producto); ?>
            <tr>
              <td>
                <div class="fw-semibold"><?= esc($producto['nombre']) ?></div>
                <div class="product-code"><?= esc($producto['codigo_interno'] ?: 'Sin codigo interno') ?></div>
              </td>
              <td><?= esc($producto['categoria_nombre']) ?></td>
              <td>
                <div><?= esc($producto['tipo_nombre']) ?></div>
                <div class="product-code">
                  <?= esc($producto['unidad_medida']) ?>
                  <?php if ((int)$producto['usa_serie'] === 1): ?> · Serie<?php endif; ?>
                  <?php if ((int)$producto['requiere_responsable_salida'] === 1): ?> · Responsable<?php endif; ?>
                </div>
              </td>
              <td class="stock-number"><?= invFormatQty((float)$producto['stock_actual']) ?></td>
              <td><?= invFormatQty((float)$producto['stock_minimo']) ?></td>
              <td>
                <span class="badge <?= $producto['activo'] === 'A' ? 'bg-primary-subtle text-primary-emphasis' : 'bg-secondary-subtle text-secondary-emphasis' ?>">
                  <?= $producto['activo'] === 'A' ? 'Activo' : 'Inactivo' ?>
                </span>
              </td>
              <td><span class="<?= esc($badgeClass) ?>"><?= esc($badgeLabel) ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="panel-card">
      <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
        <h2 class="mb-0">Movimientos recientes</h2>
        <div class="tiny-note">Ultimos 10 registros</div>
      </div>
      <div class="table-responsive">
        <table class="table table-striped align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Fecha</th>
              <th>Tipo</th>
              <th>Producto</th>
              <th>Cantidad</th>
              <th>Destino / responsable</th>
              <th>Motivo</th>
              <th>Registrado por</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($movimientosRecientes === []): ?>
            <tr>
              <td colspan="7" class="text-center text-secondary py-4">Todavia no hay movimientos registrados.</td>
            </tr>
          <?php endif; ?>
          <?php foreach ($movimientosRecientes as $movimiento): ?>
            <tr>
              <td><?= esc(date('d/m/Y H:i', strtotime((string)$movimiento['fecha_movimiento']))) ?></td>
              <td><span class="badge <?= $movimiento['tipo_movimiento'] === 'SALIDA' ? 'bg-warning-subtle text-warning-emphasis' : 'bg-success-subtle text-success-emphasis' ?>"><?= esc($movimiento['tipo_movimiento']) ?></span></td>
              <td><?= esc($movimiento['producto_nombre']) ?></td>
              <td><?= invFormatQty((float)$movimiento['cantidad']) ?> <?= esc($movimiento['unidad_medida']) ?></td>
              <td><?= esc($movimiento['entregado_a'] ?: $movimiento['area_destino'] ?: '-') ?></td>
              <td><?= esc($movimiento['motivo'] ?: '-') ?></td>
              <td><?= esc(trim((string)($movimiento['nombres'] ?? '') . ' ' . (string)($movimiento['apellidos'] ?? '')) ?: 'Sistema') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endif; ?>
</main>

<footer><?= APP_FOOTER ?></footer>

<script>
  (() => {
    const typeSelect = document.getElementById('id_tipo_control');
    const requiresField = document.getElementById('requiere_responsable_salida');
    const stockField = document.getElementById('controla_stock');
    const serialField = document.getElementById('usa_serie');

    if (!typeSelect || !requiresField || !stockField || !serialField) return;

    typeSelect.addEventListener('change', () => {
      const selected = typeSelect.options[typeSelect.selectedIndex];
      const code = selected?.dataset.codigo || '';

      if (code === 'CONSUMIBLE') {
        stockField.checked = true;
        requiresField.checked = false;
        serialField.checked = false;
      } else if (code === 'PRESTAMO') {
        stockField.checked = true;
        requiresField.checked = true;
      } else if (code === 'SERIALIZADO') {
        stockField.checked = true;
        requiresField.checked = true;
        serialField.checked = true;
      }
    });
  })();
</script>
</body>
</html>
