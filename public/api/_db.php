<?php
// Small helper for the app endpoints in this folder (no website bootstrap, no countdown).
date_default_timezone_set('America/Curacao');

function api_db(): PDO
{
    static $db = null;
    if (!$db) {
        $c = require __DIR__ . '/../../app/config.local.php';
        $db = new PDO('mysql:host=' . $c['db_host'] . ';dbname=' . $c['db_name'] . ';charset=utf8mb4', $c['db_user'], $c['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }
    return $db;
}

function api_json($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
