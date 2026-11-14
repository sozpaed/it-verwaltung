<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/src/database.php';

try {
    $database = database();
    $check = $database->prepare(
        'SELECT data_type AS data_type, column_type AS column_type, is_nullable AS is_nullable, column_default AS column_default FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $check->execute(['app_users', 'can_manage_inventory_settings']);
    $column = $check->fetch();
    if ($column !== false) {
        if ($column['data_type'] !== 'tinyint' || $column['column_type'] !== 'tinyint(1)'
            || $column['is_nullable'] !== 'NO' || (string) $column['column_default'] !== '0') {
            throw new RuntimeException('Die vorhandene Berechtigungsspalte hat ein unerwartetes Schema.');
        }
        echo "Die Berechtigung für Inventareinstellungen ist bereits vorhanden.\n";
        exit;
    }
    $database->exec('ALTER TABLE app_users ADD COLUMN can_manage_inventory_settings BOOLEAN NOT NULL DEFAULT FALSE');
    echo "Berechtigung für Inventareinstellungen ergänzt. Bestehende Rechte bleiben unverändert.\n";
} catch (PDOException|RuntimeException $exception) {
    fwrite(STDERR, "Migration fehlgeschlagen: " . $exception->getMessage() . "\n");
    exit(1);
}
