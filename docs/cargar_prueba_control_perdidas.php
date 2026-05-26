<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=UTF-8');
}

$idServicio = 19;
$tituloAgrupacion = 'Control de Perdidas';
$tiempo = '01:00:00';

$preguntas = [
    [
        'pregunta' => 'Un calefactor que ha estado conectado durante 1 hora y 30 minutos, con un voltaje de 240 volt y una potencia de 2200 W ha consumido energia igual a:',
        'alternativas' => ['15 kWh', '1366 Wh', '3,3 kWh', '720 Wh'],
    ],
    [
        'pregunta' => '¿Cual es la formula para el calculo de potencia activa?',
        'alternativas' => ['V x I x cos (Φ)', 'V x I', 'V x I x sen(Φ)', 'V x I x sen(Φ) x √3'],
    ],
    [
        'pregunta' => '¿Que dice la ley de ohm?',
        'alternativas' => [
            'En un circuito electrico, la intensidad de la corriente es directamente proporcional a la resistencia que presenta este e inversamente proporcional a la tension aplicada.',
            'En un circuito electrico, la resistencia que presenta este es directamente proporcional a la intensidad de la corriente e inversamente proporcional a la tension aplicada.',
            'En un circuito electrico, la resistencia es proporcional a la seccion del conductor.',
            'En un circuito electrico, la intensidad de la corriente es directamente proporcional a la tension aplicada e inversamente proporcional a la resistencia.',
        ],
    ],
    [
        'pregunta' => 'El flujo de carga electrica a traves de un material conductor durante una unidad de tiempo es:',
        'alternativas' => ['La energia electrica', 'La corriente electrica', 'La cantidad de electricidad', 'La diferencia de potencial'],
    ],
    [
        'pregunta' => '¿Que se entiende por tension electrica?',
        'alternativas' => [
            'El desnivel de corriente existente entre dos puntos del circuito',
            'La energia electrica que tienen los electrones de un circuito.',
            'La diferencia de potencial electrico que hay entre dos puntos de un circuito.',
            'La fuerza electromotriz.',
        ],
    ],
    [
        'pregunta' => 'La cantidad de trabajo desarrollado en una unidad de tiempo es:',
        'alternativas' => ['Potencia electrica', 'Energia electrica', 'Tension electrica.', 'Resistencia electrica'],
    ],
    [
        'pregunta' => 'Indicar la potencia instantanea de un secador de pelo si se le aplica una tension de 240V y circula una corriente de 2,0 A.',
        'alternativas' => ['480 W', '480 V', '120 W', '4800 W'],
    ],
    [
        'pregunta' => '¿Cual es el codigo de colores de los conductores de fase para obras electricas?',
        'alternativas' => ['Rojo - Azul - Verde', 'Azul - Negro - Rojo', 'Negro - Azul - Blanco', 'Rojo - Blanco - Azul'],
    ],
    [
        'pregunta' => '¿Cuales son los voltajes de la red de baja tension de Enel?',
        'alternativas' => [
            '220 voltios entre fases, 110 Voltios entre neutro y cualquier fase, tiende a 0 entre neutro y tierra.',
            '220 voltios entre fases, 380 voltios entre neutro y cualquier fase, tiende a 0 entre neutro y tierra.',
            '380 voltios entre fases, 220 Voltios entre neutro y cualquier fase, tiende a 0 entre neutro y tierra.',
            '480 voltios entre fases, 220 Voltios entre neutro y cualquier fase, tiende a 0 entre neutro y tierra.',
        ],
    ],
    [
        'pregunta' => 'De acuerdo con las Regulaciones y Normativas Vigente en el Titulo III, Capitulo 1: Derechos y obligaciones, ¿cual de las alternativas es correcta?',
        'alternativas' => [
            'Cualquier aumento de capacidad sobre la potencia conectada del inmueble obligara al cliente a pagar el valor del empalme que corresponda de acuerdo con la nueva potencia conectada.',
            'Si el equipo de medida para otorgar suministro esta en calidad de arriendo, el Cliente se obliga a pagar mensualmente el cargo fijo por la lectura.',
            'Pagar oportunamente por el consumo bimensual del servicio electrico recibido, dentro del plazo establecido en la factura o documento de cobranza que Enel distribuya para tal efecto, mas los intereses de mora y gastos administrativos que autorice la legislacion vigente.',
            'Permitir el acceso al personal debidamente autorizado por Enel a los equipos de medicion para registrar los consumos, verificar su funcionamiento y realizar trabajos de mantenimiento.',
        ],
    ],
    [
        'pregunta' => '¿Cual es el rango de tension o voltaje permitido por la legislacion vigente para lineas BT?',
        'alternativas' => ['+/- 7,5%', '+/- 20%', '- 10%', '+/- 10%'],
    ],
    [
        'pregunta' => 'Los principales riesgos que considerar en la zona de trabajo son:',
        'alternativas' => [
            'Riesgos asociados al entorno y los empalmes.',
            'Riesgos asociados a los elementos del empalme.',
            'Clientes agresivos, condiciones viales, asaltos, entre otros.',
            'No cumplimiento de tiempos de respuesta del servicio.',
        ],
    ],
    [
        'pregunta' => '¿Cuales son los principales elementos de proteccion personal a utilizar en la operacion a desarrollar?',
        'alternativas' => [
            'Casco, lentes de seguridad, careta, guantes dielectricos, zapatos de seguridad y uniforme.',
            'Casco, lentes de seguridad, careta, guantes de proteccion, zapatos de seguridad y uniforme, alicate y escalera.',
            'Casco, lentes de seguridad, careta, guantes de proteccion, zapatos de seguridad y uniforme.',
            'Casco, lentes de seguridad, careta, guantes dielectricos, guantes de proteccion, guantes de cabritilla, zapatos de seguridad y uniforme.',
        ],
    ],
    [
        'pregunta' => 'Las cinco reglas de salud y seguridad ocupacional son:',
        'alternativas' => [
            'Corte efectivo de todas las fuentes de tension, avisar a jefe directo, comprobacion de ausencia de tension, puesta a tierra y en cortocircuito, señalizacion de la zona de trabajo.',
            'Corte efectivo de todas las fuentes de tension, bloqueo de aparatos de corte, comprobacion de ausencia de tension, puesta a tierra y en cortocircuito, señalizacion de la zona de trabajo, usar elementos de proteccion personal.',
            'Corte efectivo de todas las fuentes de tension, bloqueo de aparatos de corte, comprobacion de ausencia de tension, mantener tranquilidad durante la operacion, señalizacion de la zona de trabajo.',
            'Corte efectivo de todas las fuentes de tension, bloqueo de aparatos de corte, comprobacion de ausencia de tension, puesta a tierra y en cortocircuito, señalizacion de la zona de trabajo.',
        ],
    ],
    [
        'pregunta' => 'Antes de abrir una caja de empalme se debe:',
        'alternativas' => ['Verificar que tenga suministro electrico', 'Verificar las herramientas para abrir la caja de empalme', 'Verificar que no este energizada', 'Verificar que no tenga intervenciones'],
    ],
    [
        'pregunta' => 'La detencion de un trabajo conocida como Stop Work, politica implementada por Enel, se aplica en las siguientes condiciones:',
        'alternativas' => [
            'Cuando el trabajo a realizar presenta un riesgo al operador.',
            'Cuando existe una condicion insegura para terceros o el cliente.',
            'Cuando existe un riesgo de cortocircuito.',
            'Cuando existe una condicion que supone un riesgo para la salud y seguridad o para las personas con las cuales interactua o el medio ambiente.',
        ],
    ],
    [
        'pregunta' => '¿Cual es el objetivo del proceso de inspecciones de control de perdidas?',
        'alternativas' => [
            'Garantizar la seguridad de los empalmes con el cumplimiento de la normativa.',
            'Recuperar energia no registrada y velar que los empalmes cumplan la normativa.',
            'Realizar revision y cambio de medidores para realizar refacturacion de consumos.',
            'Detectar intervenciones en los empalmes para recuperar la energia hurtada.',
        ],
    ],
    [
        'pregunta' => 'En una inspeccion la caja empalme esta sin sello, tapa terminal con sello cafe bien instalado y el de cubierta de medidor tiene un sello verde instalado, no se aprecia intervencion. ¿Que debes realizar?',
        'alternativas' => [
            'Realizar pruebas con verificador rapido y sellar caja de empalme.',
            'Reponer sello caja empalme e informar como Normal.',
            'Utilizar verificador rapido, realizar prueba de registro y generar trabajo por reposicion de sello.',
            'Utilizar verificador rapido y realizar prueba de registro y generar trabajo normalizacion de medidor.',
        ],
    ],
    [
        'pregunta' => 'Si al realizar una inspeccion el verificador rapido indica % de error -14%, ¿estoy en presencia de la anomalia?',
        'alternativas' => ['Medidor intervenido', 'Medidor con falla interna', 'Medidor con subregistro', 'Medidor con sobreregistro'],
    ],
    [
        'pregunta' => 'Si al realizar una inspeccion coloco el amperimetro en la bajada y este registra 2,1 A. ¿Estoy en presencia de?',
        'alternativas' => ['Neutro artificial comandado', 'Neutro enmallado', 'Neutro en bajada cortado', 'Ninguna de las anteriores'],
    ],
    [
        'pregunta' => '¿Cual es la definicion de hurto de energia? Seleccione la definicion mas completa.',
        'alternativas' => [
            'Es la energia consumida, no reflejada en la medicion del cliente.',
            'Es la sustraccion de energia electrica desde la acometida hacia el interior del domicilio.',
            'Es la sustraccion de energia electrica desde una instalacion electrica o del empalme.',
            'Es la sustraccion de energia electrica desde la red de la concesionaria o del empalme.',
        ],
    ],
    [
        'pregunta' => '¿Cual es la secuencia correcta de conexion del conector volante?',
        'alternativas' => [
            'Primero el conector rojo se conecta a fase de salida del medidor y luego el conector blanco a la salida de neutro del medidor.',
            'Primero el conector blanco se conecta al neutro de entrada del medidor y luego el conector rojo a la entrada de fase del medidor.',
            'Primero el conector blanco se conecta al neutro de salida del medidor y luego el conector rojo a la salida de fase del medidor.',
            'Primero el conector blanco se conecta al neutro de entrada del medidor y luego el conector rojo a la salida de fase del medidor.',
        ],
    ],
    [
        'pregunta' => 'Si al realizar una inspeccion el verificador rapido indica un error de 19%, ¿estoy en presencia de?',
        'alternativas' => ['Medidor con sobre registro', 'Medidor con subregistro', 'Medidor intervenido', 'Medidor con falla interna'],
    ],
    [
        'pregunta' => 'Si se inspecciona un cliente que tiene un medidor concentrador y este se encuentra fallado sin registrar consumos, ¿que se debe realizar con los otros clientes del medidor concentrador?',
        'alternativas' => [
            'Basta con atender la orden de este cliente y emitir orden de verificacion para que se cambie el equipo y se regularicen los otros clientes.',
            'Informar a coordinador o supervisor, inspeccionar resto de clientes y solicitar las ordenes para cada uno para registrarlas posteriormente.',
            'Solicitar orden de inspeccion para el resto de los clientes y volver otro dia.',
            'Basta con atender la orden y cursar CNR a este cliente para poder cursar CNR al resto de clientes del concentrador sin necesidad de aplicar el procedimiento de trabajo para cada uno.',
        ],
    ],
    [
        'pregunta' => '¿Cuando se usa el resultado de inspeccion "Stand-by"?',
        'alternativas' => [
            'Cuando no se ha podido inspeccionar el empalme por alguna razon ajena al cliente.',
            'Cuando se desea dejar pendiente una inspeccion y volver a visitar durante el mismo dia u otro dia en una nueva asignacion.',
            'Cuando el cliente o usuario no permitio el acceso o revision del equipo de medida.',
            'Cuando luego de la inspeccion al servicio se tiene dudas o sospechas de manipulacion o intervencion por parte de cliente.',
        ],
    ],
    [
        'pregunta' => 'De acuerdo con la pregunta anterior (Stand-by), con este resultado se puede:',
        'alternativas' => ['Emitir orden de trabajo', 'Informar traspaso a detencion', 'Cambiar medidor', 'Ninguna de las anteriores'],
    ],
    [
        'pregunta' => '¿Cuando se deja como resultado de inspeccion "Retirado"?',
        'alternativas' => [
            'Cuando no existe consumo no registrado o normalizacion de empalme requerida y se verifica que el equipo de medida que trae la orden no se encuentra en terreno.',
            'Cuando se verifica que el empalme y el equipo de medida no se encuentran en terreno.',
            'Cuando la orden de trabajo no fue ejecutada debido a que no se logro ubicar la direccion del cliente y el empalme.',
            'Ninguna de las anteriores.',
        ],
    ],
    [
        'pregunta' => 'En un cliente con medidor CPD o CP4, el equipo de medida se encuentra intervenido en su sistema de registro ciclo metrico. ¿Como se debe proceder?',
        'alternativas' => ['Cambiar el medidor y cursar hurto', 'Cambiar el medidor y cursar CNR', 'Emitir orden de verificacion y cerrar orden con resultado Normal', 'Cambiar el medidor y cerrar orden con resultado Normal'],
    ],
    [
        'pregunta' => 'Ante un medidor con sobreconsumo, ¿que se debe hacer?',
        'alternativas' => ['Cambiar el medidor y cursar CNR', 'Cambiar el medidor y cerrar orden con resultado Normal', 'Emitir orden de verificacion y cursar CNR', 'Ninguna de las anteriores'],
    ],
    [
        'pregunta' => '¿En que caso debo realizar un traspaso a detencion?',
        'alternativas' => [
            'Cuando se detecta hurto con carga conectada relevante.',
            'Cuando hay hurto detectado pero que no es posible de cursar.',
            'Cuando cliente tiene consumos irregulares y no permite realizar la inspeccion.',
            'Cuando hay alto nivel de energia hurtada y traspaso es aprobado por supervisor o personal Enel.',
        ],
    ],
    [
        'pregunta' => 'Respecto al resultado de inspeccion "CNR" (falla en equipo de medida), se puede decir que:',
        'alternativas' => [
            'Corresponde a la energia suministrada por Enel Dx Chile y que no es registrada correctamente por el equipo de medida a causa de una intervencion en el empalme o en el mismo medidor, cuyo subregistro se debe a una accion con dolo con responsabilidad del Cliente.',
            'Corresponde a aquellas visitas en la cual el cliente o usuario no permitio el acceso o revision del equipo de medida.',
            'Corresponde a la energia suministrada por Enel Dx Chile y que no es registrada correctamente por el equipo de medida, cuyo subregistro no se debe a una accion con dolo responsabilidad del Cliente.',
            'El servicio que aun siendo inspeccionado se tiene dudas o sospechas de manipulacion o intervencion por parte de cliente.',
        ],
    ],
    [
        'pregunta' => 'En cuanto a la deteccion de hurto, ¿que se debe realizar antes de abrir la caja de empalme?',
        'alternativas' => [
            'Solo i',
            'Solo iii',
            'Solo ii y iii',
            'Todas: i, ii y iii. i. Realizar analisis segun consumos facturados y consumo estimado del cliente. ii. Medir la corriente en bajada de acometida. iii. Realizar inspeccion visual de arranques y verificar estado de acometida.',
        ],
    ],
    [
        'pregunta' => 'Situacion en terreno: si al realizar la inspeccion visual del empalme, la acometida se encuentra tapada en algun tramo debido a remodelacion de fachada de la propiedad. ¿Cual o cuales acciones se deben realizar?',
        'alternativas' => [
            'Solo i',
            'Solo iii',
            'Solo i y iii',
            'Todas: i, ii y iii. i. Realizar mediciones electricas para verificar hurto. ii. Registrar situacion en la observacion de la orden y con fotografias. iii. Notificar al cliente y registrar trabajo de cambio de acometida con cargo.',
        ],
    ],
    [
        'pregunta' => 'Situacion en terreno: cliente indica que tiene generacion electrica con paneles fotovoltaicos, sin embargo tiene instalado un medidor electromecanico, ¿que accion se debe realizar?',
        'alternativas' => [
            'Si cliente no tiene lectura retrocedida entonces no se debe informar situacion y el resultado de la inspeccion es Normal.',
            'Si cliente tiene lectura retrocedida entonces se instala medidor electronico y el resultado de la inspeccion es CNR.',
            'Se instala medidor electronico o se emite trabajo de cambio de medidor y el resultado de la inspeccion es Hurto.',
            'Solo se debe informar en la observacion de la orden y se debe emitir trabajo de cambio de medidor.',
        ],
    ],
    [
        'pregunta' => 'Situacion en terreno: segun la orden de inspeccion cliente tiene mas de un medidor instalado y en terreno se encuentran todos. ¿Que accion se debe realizar?',
        'alternativas' => [
            'Inspeccionar todos los medidores.',
            'Inspeccionar solamente el primer medidor que aparezca en la orden.',
            'Si todos los medidores tienen lectura en avance, se debe inspeccionar solo el primer medidor que aparezca en la orden.',
            'Realizar inspeccion solo al medidor en uso.',
        ],
    ],
    [
        'pregunta' => 'Un medidor se encuentra destrozado o quemado, luego al registrar en la orden de inspeccion el cambio de medidor realizado in situ, ¿que trabajos se deben registrar en la aplicacion movil?',
        'alternativas' => ['Orden por "Cambio medidor medida tecnica"', 'Orden por "Cambio de medidor" o "Cambio medidor inteligente", segun modelo', 'Orden por "Reposicion de Medidor"', 'Orden por "Cambio de medidor" y "Normalizar conexionado o alambrado"'],
    ],
    [
        'pregunta' => 'Si el medidor de la orden de inspeccion no se encuentra en terreno y en su lugar se encuentra otro medidor en uso con registro en avance, ¿que accion se debe realizar?',
        'alternativas' => [
            'Solo informar en la orden que no se encontro medidor indicado en la orden.',
            'Informar en la orden el numero de medidor encontrado con respaldo fotografico.',
            'Cerrar orden con resultado Stand-by para consultar al supervisor o coordinador.',
            'Cerrar orden con resultado Normal.',
        ],
    ],
    [
        'pregunta' => '¿Que tipo de frases o expresiones NO se deben usar al llegar a una propiedad y presentarse con el cliente?',
        'alternativas' => [
            'Solo i',
            'Solo ii',
            'Solo i y ii',
            'Todas: i, ii y iii. i. Mi dama, Mi rey, Mi reina o expresiones similares. ii. Frases con Hurto, Intervencion, Robo, Adulteracion o similares que ofendan al cliente. iii. Venimos a verificar, por su seguridad, el estado del empalme y el equipo de medida.',
        ],
    ],
];

function out(string $message): void
{
    echo $message . PHP_EOL;
}

if (count($preguntas) !== 38) {
    throw new RuntimeException('La carga debe contener 38 preguntas. Encontradas: ' . count($preguntas));
}

foreach ($preguntas as $idx => $pregunta) {
    if (mb_strlen($pregunta['pregunta'], 'UTF-8') > 500) {
        throw new RuntimeException('Pregunta ' . ($idx + 1) . ' excede 500 caracteres.');
    }
    if (count($pregunta['alternativas']) !== 4) {
        throw new RuntimeException('Pregunta ' . ($idx + 1) . ' no tiene 4 alternativas.');
    }
    foreach ($pregunta['alternativas'] as $altIdx => $alternativa) {
        if (mb_strlen($alternativa, 'UTF-8') > 500) {
            throw new RuntimeException('Alternativa ' . chr(97 + $altIdx) . ' de pregunta ' . ($idx + 1) . ' excede 500 caracteres.');
        }
    }
}

$pdo = db();

try {
    $pdo->beginTransaction();

    $stmtServicio = $pdo->prepare('SELECT id, servicio FROM ceo_formacion_servicios WHERE id = :id LIMIT 1');
    $stmtServicio->execute([':id' => $idServicio]);
    $servicio = $stmtServicio->fetch(PDO::FETCH_ASSOC);
    if (!$servicio) {
        throw new RuntimeException('No existe ceo_formacion_servicios.id = ' . $idServicio);
    }

    $stmtAgrupacion = $pdo->prepare('SELECT id FROM ceo_formacion_agrupacion WHERE id_servicio = :id_servicio AND titulo = :titulo LIMIT 1');
    $stmtAgrupacion->execute([
        ':id_servicio' => $idServicio,
        ':titulo' => $tituloAgrupacion,
    ]);
    $idAgrupacion = (int)($stmtAgrupacion->fetchColumn() ?: 0);

    if ($idAgrupacion <= 0) {
        $stmtCrearAgrupacion = $pdo->prepare('INSERT INTO ceo_formacion_agrupacion (titulo, id_servicio, tiempo, cantidad, total) VALUES (:titulo, :id_servicio, :tiempo, :cantidad, :total)');
        $stmtCrearAgrupacion->execute([
            ':titulo' => $tituloAgrupacion,
            ':id_servicio' => $idServicio,
            ':tiempo' => $tiempo,
            ':cantidad' => count($preguntas),
            ':total' => count($preguntas),
        ]);
        $idAgrupacion = (int)$pdo->lastInsertId();
        out('Agrupacion creada: ' . $tituloAgrupacion . ' (ID ' . $idAgrupacion . ')');
    } else {
        out('Agrupacion existente reutilizada: ' . $tituloAgrupacion . ' (ID ' . $idAgrupacion . ')');
    }

    $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM ceo_formacion_preguntas_servicios WHERE id_agrupacion = :id_agrupacion');
    $stmtCount->execute([':id_agrupacion' => $idAgrupacion]);
    $preguntasExistentes = (int)$stmtCount->fetchColumn();
    if ($preguntasExistentes > 0) {
        throw new RuntimeException('La agrupacion ya tiene ' . $preguntasExistentes . ' pregunta(s). No se insertaron duplicados.');
    }

    $stmtPregunta = $pdo->prepare('
        INSERT INTO ceo_formacion_preguntas_servicios
            (pregunta, id_servicio, imagen, estado, id_agrupacion, retropos, retroneg, areacomp, peso, tipo_pregunta, obligatoria)
        VALUES
            (:pregunta, :id_servicio, "", "S", :id_agrupacion, NULL, NULL, NULL, 1, "ALT", 0)
    ');

    $stmtAlternativa = $pdo->prepare('
        INSERT INTO ceo_formacion_alternativas_preguntas
            (alternativa, correcta, estado, id_pregunta, imagen)
        VALUES
            (:alternativa, "N", "S", :id_pregunta, "")
    ');

    $totalAlternativas = 0;
    foreach ($preguntas as $pregunta) {
        $stmtPregunta->execute([
            ':pregunta' => $pregunta['pregunta'],
            ':id_servicio' => $idServicio,
            ':id_agrupacion' => $idAgrupacion,
        ]);
        $idPregunta = (int)$pdo->lastInsertId();

        foreach ($pregunta['alternativas'] as $alternativa) {
            $stmtAlternativa->execute([
                ':alternativa' => $alternativa,
                ':id_pregunta' => $idPregunta,
            ]);
            $totalAlternativas++;
        }
    }

    $pdo->commit();

    out('Carga completada correctamente.');
    out('Servicio: ' . $servicio['servicio'] . ' (ID ' . $idServicio . ')');
    out('Agrupacion: ' . $tituloAgrupacion . ' (ID ' . $idAgrupacion . ')');
    out('Preguntas insertadas: ' . count($preguntas));
    out('Alternativas insertadas: ' . $totalAlternativas);
    out('Correctas: todas cargadas como N. Deben marcarse posteriormente desde el mantenedor.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    out('Error: ' . $e->getMessage());
    exit(1);
}
