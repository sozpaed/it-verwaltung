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
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $check->execute(['app_users', 'can_manage_users']);
    if ((int) $check->fetchColumn() !== 0) {
        echo "Administratorrecht ist bereits vorhanden.\n";
        exit;
    }
    $database->exec('ALTER TABLE app_users ADD COLUMN can_manage_users BOOLEAN NOT NULL DEFAULT FALSE');
    echo "Administratorrecht ergänzt. Bestehende Konten behalten ihre bisherigen Rechte.\n";
} catch (PDOException|RuntimeException $exception) {
    fwrite(STDERR, "Migration fehlgeschlagen: " . $exception->getMessage() . "\n");
    exit(1);
}
