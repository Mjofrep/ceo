<?php
// /config/db.php
declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_NAME = 'noeticac_ceo';
const DB_USER = 'noeticac_root';
const DB_PASS = 'Turing_1964$';
const DB_DSN  = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
