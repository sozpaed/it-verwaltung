<?php

declare(strict_types=1);

function initializeEmptyDatabase(PDO $database, string $schemaPath): void
{
    $existing = $database->query(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
    )->fetchColumn();
    if ((int) $existing !== 0) {
        throw new RuntimeException('Die Datenbank ist nicht leer. Es wurden keine Tabellen verändert.');
    }
    $schema = file_get_contents($schemaPath);
    if ($schema === false || trim($schema) === '') {
        throw new RuntimeException('Das Datenbankschema konnte nicht gelesen werden.');
    }
    foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
        $database->exec($statement);
    }
}
