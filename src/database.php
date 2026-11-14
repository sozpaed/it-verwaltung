<?php

declare(strict_types=1);

function database(): PDO
{
    $path = dirname(__DIR__) . '/config.php';
    if (!is_file($path)) {
        throw new RuntimeException('config.php fehlt.');
    }
    $config = require $path;
    return databaseWithConfiguration($config);
}

function databaseWithConfiguration(mixed $config): PDO
{
    if (!is_array($config)
        || ($config['DB_HOST'] ?? null) !== '127.0.0.1'
        || ($config['DB_PORT'] ?? null) !== 3307
        || ($config['DB_NAME'] ?? null) !== 'itdoku_dev'
        || ($config['DB_USER'] ?? null) !== 'itdoku_app'
        || !is_string($config['DB_PASSWORD'] ?? null)
        || $config['DB_PASSWORD'] === '') {
        throw new RuntimeException('Ungültige lokale Datenbankkonfiguration.');
    }

    return new PDO(
        'mysql:host=127.0.0.1;port=3307;dbname=itdoku_dev;charset=utf8mb4',
        $config['DB_USER'],
        $config['DB_PASSWORD'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}
