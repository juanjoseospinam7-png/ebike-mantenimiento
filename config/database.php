<?php
declare(strict_types=1);

/*
 * CONFIGURACIÓN DE BASE DE DATOS
 * En XAMPP los valores por defecto funcionan sin cambios.
 * Se permiten variables de entorno para pruebas automatizadas o despliegues:
 * DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PORT
 */
const DB_HOST = '127.0.0.1';
const DB_NAME = 'ebike_taller';
const DB_USER = 'root';
const DB_PASS = '';
const DB_PORT = '3306';

function db_dsn(): string
{
    $host = (string)(getenv('DB_HOST') ?: DB_HOST);
    $port = (string)(getenv('DB_PORT') ?: DB_PORT);
    $name = (string)(getenv('DB_NAME') ?: DB_NAME);
    return 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $pdo = new PDO(db_dsn(), (string)(getenv('DB_USER') ?: DB_USER), (string)(getenv('DB_PASS') ?: DB_PASS), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}
