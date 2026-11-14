<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/schema.php';

try {
    initializeEmptyDatabase(database(), dirname(__DIR__) . '/database/schema.sql');
    echo "Datenbankschema angelegt.\n";
} catch (PDOException|RuntimeException $exception) {
    fwrite(STDERR, "Initialisierung fehlgeschlagen: " . $exception->getMessage() . "\n");
    fwrite(STDERR, "Falls bereits Tabellen angelegt wurden, Zustand vor einem weiteren Versuch prüfen.\n");
    exit(1);
}
