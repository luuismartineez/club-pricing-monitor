<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

function db(): mysqli
{
    static $mysqli = null;

    if ($mysqli instanceof mysqli) {
        return $mysqli;
    }

    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    if ($mysqli->connect_errno) {
        die('Error de conexión a la base de datos: ' . $mysqli->connect_error);
    }

    $mysqli->set_charset(DB_CHARSET);

    return $mysqli;
}