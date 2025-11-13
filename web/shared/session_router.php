<?php

declare(strict_types=1);

require_once __DIR__ . '/db_session_handler.php';

function initAppSession(bool $forceDatabase = false): void
{
    static $started = false;
    if ($started) {
        return;
    }
    if ($forceDatabase || shouldUseDatabaseSession()) {
        error_log("Using database session handler");
        activateDatabaseSessionHandler();
    }
    session_start();
    $started = true;
}

function shouldUseDatabaseSession(): bool
{
    if (!empty($_GET['session_mode']) && $_GET['session_mode'] === 'db') {
        return true;
    }

    if (!empty($_COOKIE['SESSION_FORCE_DB'])) {
        return $_COOKIE['SESSION_FORCE_DB'] === '1';
    }

    return empty($_COOKIE['SRV']);
}

function activateDatabaseSessionHandler(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }

    $dsn = 'mysql:host=haproxy-db;port=3307;dbname=clustering;charset=utf8mb4';
    $pdo = new PDO($dsn, 'root', 'root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $handler = new DBSessionHandler($pdo);
    session_set_save_handler($handler, true);
    $registered = true;
}
