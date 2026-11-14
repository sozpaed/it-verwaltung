<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/src/database.php';
require_once dirname(__DIR__) . '/src/users.php';

$username = $argv[1] ?? '';
$role = $argv[2] ?? '';
$password = getenv('ITDOKU_USER_PASSWORD');
if (!in_array($role, ['inventar', 'ausgabe', 'admin'], true)) {
    fwrite(STDERR, "Aufruf: php bin/create-user.php BENUTZERNAME inventar|ausgabe|admin\n");
    fwrite(STDERR, "ITDOKU_USER_PASSWORD muss vorübergehend ein Passwort mit 12 bis 72 Bytes enthalten.\n");
    exit(1);
}
try {
    createUser(database(), $username, $password, $role === 'admin', $role === 'ausgabe');
    echo "Benutzerkonto angelegt.\n";
} catch (PDOException|RuntimeException|InvalidArgumentException $exception) {
    fwrite(STDERR, "Benutzerkonto konnte nicht angelegt werden: " . $exception->getMessage() . "\n");
    exit(1);
}
