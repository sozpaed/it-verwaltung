<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/src/database.php';

try {
    database()->query('SELECT 1');
    echo "Verbindung zur lokalen Datenbank itdoku_dev erfolgreich.\n";
} catch (PDOException|RuntimeException $exception) {
    fwrite(STDERR, "Datenbankverbindung fehlgeschlagen: " . $exception->getMessage() . "\n");
    exit(1);
}
