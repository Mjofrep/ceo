<?php
declare(strict_types=1);

require_once __DIR__ . '/gp_auth.php';

gpLogout();
header('Location: ' . GP_LOGIN_PATH);
exit;
