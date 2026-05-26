<?php
declare(strict_types=1);

require_once __DIR__ . '/gp_auth.php';

$pdo = db();
gpEnsureTables($pdo);
gpRequireRole(['ADMIN', 'CREADOR', 'REVISOR']);

header('Location: gp_revision.php');
exit;
