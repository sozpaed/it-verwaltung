<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/src/database.php';

try {
    $database = database();
    $columns = [
        'designation', 'product_name', 'manufacturer', 'purchase_date', 'purchase_price',
        'graphics_card', 'processor', 'storage_capacity', 'ram', 'operating_system',
    ];
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $check = $database->prepare(
        'SELECT column_name FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name IN (' . $placeholders . ')'
    );
    $check->execute(['devices', ...$columns]);
    $existing = $check->fetchAll(PDO::FETCH_COLUMN);
    if (count($existing) === count($columns)) {
        echo "Die zusätzlichen Gerätefelder sind bereits vorhanden.\n";
        exit;
    }
    if ($existing !== []) {
        throw new RuntimeException('Die Gerätefelder sind nur teilweise vorhanden. Bitte das Schema vor einer Migration prüfen.');
    }
    $database->exec(
        "ALTER TABLE devices
         ADD COLUMN designation VARCHAR(150) NOT NULL DEFAULT '',
         ADD COLUMN product_name VARCHAR(150) NOT NULL DEFAULT '',
         ADD COLUMN manufacturer VARCHAR(150) NOT NULL DEFAULT '',
         ADD COLUMN purchase_date DATE NULL,
         ADD COLUMN purchase_price DECIMAL(12,2) NULL,
         ADD COLUMN graphics_card VARCHAR(150) NOT NULL DEFAULT '',
         ADD COLUMN processor VARCHAR(150) NOT NULL DEFAULT '',
         ADD COLUMN storage_capacity VARCHAR(150) NOT NULL DEFAULT '',
         ADD COLUMN ram VARCHAR(150) NOT NULL DEFAULT '',
         ADD COLUMN operating_system VARCHAR(150) NOT NULL DEFAULT ''"
    );
    echo "Zusätzliche Gerätefelder angelegt; bestehende Geräte bleiben erhalten.\n";
} catch (PDOException|RuntimeException $exception) {
    fwrite(STDERR, "Migration fehlgeschlagen: " . $exception->getMessage() . "\n");
    exit(1);
}
