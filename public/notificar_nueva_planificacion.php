<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/db.php';

function notificarNuevaPlanificacion(
    int $cuadrilla,
    string $fecha,
    string $jornada,
    string $empresa,
    string $uo,
    string $servicio
): void {

    $pdo = db();

    try {
        $mail = new PHPMailer(true);

        // SMTP
        $mail->isSMTP();
        $mail->Host       = 'mail.noetica.cl';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'ceo@noetica.cl';
        $mail->Password   = 'Neotica_1964$';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;

        $mail->setFrom('ceo@noetica.cl', 'Sistema CEO');

        // Admins
        $stmt = $pdo->query("
            SELECT correo
            FROM ceo_usuarios
            WHERE id_rol = 1
              AND correo IS NOT NULL
              AND correo <> ''
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $correo) {
            $mail->addAddress(trim($correo));
        }

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';

        $mail->Subject = "CEO | Nueva planificacion (Cuadrilla {$cuadrilla})";

        $mail->Body = "
        <h3>Nueva Planificacion de Habilitacion</h3>
        <table cellpadding='6'>
            <tr><td><b>Cuadrilla:</b></td><td>{$cuadrilla}</td></tr>
            <tr><td><b>Fecha:</b></td><td>{$fecha}</td></tr>
            <tr><td><b>Jornada:</b></td><td>{$jornada}</td></tr>
            <tr><td><b>Empresa:</b></td><td>{$empresa}</td></tr>
            <tr><td><b>Unidad Operativa:</b></td><td>{$uo}</td></tr>
            <tr><td><b>Servicio:</b></td><td>{$servicio}</td></tr>
        </table>
        <p>Correo generado automaticamente por CEONext.</p>
        ";

        $mail->send();

    } catch (Throwable $e) {
        // ❗ Nunca romper el flujo principal
        error_log("ERROR correo planificación: " . $e->getMessage());
    }
}

