<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/src/database.php';

try {
    $db = database();
    $table = $db->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'inventory_settings'"
    )->fetchColumn();
    $columns = $db->query(
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
         AND table_name = 'devices' AND column_name IN ('inventory_area_id', 'inventory_group_id', 'inventory_category_id')"
    )->fetchColumn();
    if ((int) $table === 1 && (int) $columns === 3) {
        echo "Die Inventareinstellungen sind bereits vorhanden.\n";
        exit;
    }
    if ((int) $table !== 0 || (int) $columns !== 0) {
        throw new RuntimeException('Die Inventareinstellungen sind nur teilweise vorhanden. Bitte das Schema prüfen.');
    }
    $schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
    if ($schema === false) {
        throw new RuntimeException('Das Datenbankschema konnte nicht gelesen werden.');
    }
    $statements = array_filter(array_map('trim', explode(';', $schema)), static fn (string $statement): bool =>
        str_starts_with($statement, 'CREATE TABLE inventory_settings')
        || str_starts_with($statement, 'INSERT INTO inventory_settings'));
    if (count($statements) !== 3) {
        throw new RuntimeException('Die Schemaanweisungen für Inventareinstellungen sind unvollständig.');
    }
    foreach ($statements as $statement) {
        $db->exec($statement);
    }
    $db->exec(
        'ALTER TABLE devices
         ADD COLUMN inventory_area_id BIGINT UNSIGNED NULL,
         ADD COLUMN inventory_group_id BIGINT UNSIGNED NULL,
         ADD COLUMN inventory_category_id BIGINT UNSIGNED NULL,
         ADD CONSTRAINT fk_devices_area FOREIGN KEY (inventory_area_id) REFERENCES inventory_settings(id),
         ADD CONSTRAINT fk_devices_group FOREIGN KEY (inventory_group_id) REFERENCES inventory_settings(id),
         ADD CONSTRAINT fk_devices_category FOREIGN KEY (inventory_category_id) REFERENCES inventory_settings(id)'
    );
    echo "Inventareinstellungen angelegt; bestehende Geräte bleiben unverändert.\n";
} catch (PDOException|RuntimeException $exception) {
    fwrite(STDERR, "Migration fehlgeschlagen: " . $exception->getMessage() . "\n");
    exit(1);
}
